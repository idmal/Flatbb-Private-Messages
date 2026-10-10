<?php
/**
 * Messages — the chat page (list + conversation), send, poll, older messages, leave (delete chat), block. Loaded by plugin.php.
 * On phones an open conversation fills the screen like a messenger: the site header, the bottom bar and the footer stay
 * under it, only the messages scroll, and the input rides on top of the keyboard (the visual viewport, see messages_js()).
 */
if (!defined('FLATBB')) exit;

/** The other member of a chat URL, or a 404. */
function messages_partner(string $name, array $me): array
{
    $u = user_by_name(rawurldecode($name));
    if ($u === null || (int)$u['id'] === (int)$me['id']) not_found();
    return $u;
}

/**
 * Bubbles for a run of messages with a date line whenever the day changes ($prev_day = Y-m-d of the message before, '' at the top).
 * $their_read: when the other member last read the chat (my messages up to then get two ticks); $partner: their name as shown.
 */
function messages_render(array $posts, int $uid, int $their_read, string $partner, string $prev_day = ''): string
{
    $html = '';
    foreach ($posts as $p) {
        $day = date('Y-m-d', (int)$p['created_at']);
        if ($day !== $prev_day) {
            $html .= '<div class="chat-day"><span>' . h(messages_day_label((int)$p['created_at'])) . '</span></div>';
            $prev_day = $day;
        }
        $html .= messages_bubble($p, $uid, $their_read, $partner);
    }
    return $html;
}

/** GET /messages and /messages/{name}: the conversation list, and the chat with {name}. */
function messages_page(string $name = ''): never
{
    $me = need_login();
    $uid = (int)$me['id'];
    db_ensure_columns('plugin_messages_members', ['cleared_to' => 'uint']); // 1.5 column: a site that upgraded the files without reinstalling still works
    $partner = $name !== '' ? messages_partner($name, $me) : null;
    // conversation list
    $threads = all('SELECT t.*, m.last_read FROM plugin_messages_threads t JOIN plugin_messages_members m ON m.thread_id=t.id WHERE m.user_id=? AND m.hidden=0 AND t.msg_count>0 ORDER BY t.last_at DESC LIMIT 100', [$uid]);
    $others = [];
    foreach ($threads as $t) { [$a, $b] = explode(':', (string)$t['pair']); $others[(int)$t['id']] = (int)$a === $uid ? (int)$b : (int)$a; }
    $users = users_by_ids($others);
    // a conversation with a member who no longer exists is erased, not shown
    $gone = [];
    foreach ($threads as $i => $t) if (!isset($users[$others[(int)$t['id']]])) { $gone[] = $t; unset($threads[$i]); }
    if ($gone !== []) messages_purge_orphans($gone);
    elseif (mt_rand(1, 20) === 1) messages_gc();
    $reads = []; // when the other member of each conversation last read it: the tick before "You: …"
    $counts = []; // unread messages per conversation: the badge
    if ($threads !== []) {
        $tids = array_map('intval', array_column($threads, 'id'));
        foreach (all('SELECT thread_id, last_read FROM plugin_messages_members WHERE user_id<>? AND thread_id IN (' . sql_marks(count($tids)) . ')', array_merge([$uid], $tids)) as $r) $reads[(int)$r['thread_id']] = (int)$r['last_read'];
        foreach (all('SELECT p.thread_id, COUNT(*) AS c FROM plugin_messages_posts p JOIN plugin_messages_members m ON m.thread_id=p.thread_id AND m.user_id=? WHERE p.user_id<>? AND COALESCE(p.recalled_at,0)=0 AND p.created_at>m.last_read AND p.id>COALESCE(m.cleared_to,0) AND p.thread_id IN (' . sql_marks(count($tids)) . ') GROUP BY p.thread_id', array_merge([$uid, $uid], $tids)) as $r) $counts[(int)$r['thread_id']] = (int)$r['c'];
    }
    $list = '';
    foreach ($threads as $t) {
        $u = $users[$others[(int)$t['id']]];
        $unread = (int)$t['last_at'] > (int)$t['last_read'] && (int)$t['last_by'] !== $uid;
        $mine = (int)$t['last_by'] === $uid;
        // the newest message was recalled: say so, without a tick or "You:"
        $last = (string)$t['last_text'] !== ''
            ? ($mine ? messages_tick(($reads[(int)$t['id']] ?? 0) >= (int)$t['last_at']) . messages_t('You: ') : '') . h((string)$t['last_text'])
            : '<em>' . h($mine ? messages_t('You recalled a message') : messages_t('%s recalled a message', messages_name($u))) . '</em>';
        $n = max(1, (int)($counts[(int)$t['id']] ?? 0));
        $badge = $unread ? '<span class="chat-badge">' . h($n > 99 ? messages_digits('99') . '+' : messages_digits((string)$n)) . '</span>' : '';
        $list .= '<a class="chat-item' . ($unread ? ' unread' : '') . ($partner !== null && (int)$u['id'] === (int)$partner['id'] ? ' active' : '') . '" href="' . h(url('/messages/' . rawurlencode((string)$u['username']))) . '">' . avatar($u, 48, false)
            . '<div class="chat-item-main"><div class="chat-item-top"><b dir="auto">' . h(messages_name($u)) . '</b><time>' . h(messages_when((int)$t['last_at'])) . '</time></div>'
            . '<div class="chat-item-bottom"><div class="chat-item-last" dir="auto">' . $last . '</div>' . $badge . '</div></div></a>';
    }
    if ($list === '') $list = '<div class="chat-empty muted">' . messages_t('No conversations yet. Open a profile and press Message.') . '</div>';
    // chat pane
    if ($partner === null) {
        $pane = '<div class="chat-pane chat-none"><div class="chat-empty muted">' . icon('message') . '<p>' . messages_t('Pick a conversation') . '</p></div></div>';
    } else {
        $t = messages_thread($uid, (int)$partner['id'], false);
        $floor = $t !== null ? messages_floor((int)$t['id'], $uid) : 0; // messages I deleted never come back
        $posts = $t !== null ? array_reverse(all('SELECT * FROM plugin_messages_posts WHERE thread_id=? AND id>? ORDER BY id DESC LIMIT ' . MESSAGES_PAGE, [(int)$t['id'], $floor])) : [];
        if ($t !== null) { db_update('plugin_messages_members', ['last_read' => now()], 'thread_id=? AND user_id=?', [(int)$t['id'], $uid]); request_cache('messages_unread_' . $uid, null, true); }
        $their = $t !== null ? messages_their_read((int)$t['id'], (int)$partner['id']) : 0;
        $bubbles = messages_render($posts, $uid, $their, messages_name($partner));
        $last = $posts !== [] ? (int)end($posts)['id'] : 0;
        $why = messages_can_send($me);
        $blocked = messages_blocked($uid, (int)$partner['id']);
        if ($why === '') {
            if ($blocked) $why = messages_t('You blocked %s.', messages_name($partner));
            elseif (!messages_accepts($partner, $me)) $why = messages_t('%s does not accept messages.', messages_name($partner));
        }
        $base = url('/messages/' . rawurlencode((string)$partner['username']));
        $first = $posts !== [] ? (int)$posts[0]['id'] : 0;
        $seen = messages_seen((int)$partner['last_seen']);
        $dots = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="12" cy="19" r="1.9"/></svg>';
        $menu = '<details class="chat-menu"><summary class="chat-menu-btn" aria-label="' . h(messages_t('Messages')) . '">' . $dots . '</summary><div class="chat-menu-pop">'
            . action_form(url('/messages/block'), '<button class="chat-menu-item">' . messages_t($blocked ? 'Unblock' : 'Block') . '</button>', ['id' => (int)$partner['id'], 'action' => $blocked ? 'unblock' : 'block'], 'inline')
            . ($t !== null ? action_form($base . '/leave', '<button class="chat-menu-item is-danger">' . messages_t('Delete chat') . '</button>', [], 'inline', messages_t('Delete this chat? The messages are deleted for you now, and erased completely once the other member deletes the chat too.')) : '')
            . '</div></details>';
        $send = '<button type="submit" class="btn btn-primary chat-send" title="' . h(messages_t('Send')) . '" aria-label="' . h(messages_t('Send')) . '"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>';
        $pane = '<div class="chat-pane" data-chat="' . h($base) . '" data-last="' . $last . '" data-first="' . $first . '"' . (count($posts) >= MESSAGES_PAGE ? ' data-more' : '') . ' data-read="' . $their . '" data-now="' . now() . '"' . (messages_persian() ? ' data-fa' : '')
            . ' data-label-read="' . h(messages_t('Read')) . '" data-label-loading="' . h(messages_t('Loading earlier messages…')) . '" data-label-latest="' . h(messages_t('Latest messages')) . '" data-confirm-recall="' . h(messages_t('Recall this message? It disappears for both of you.')) . '">'
            . '<div class="chat-head"><a class="chat-back" href="' . h(url('/messages')) . '" title="' . h(messages_t('Messages')) . '">' . icon('arrow-left') . '</a>' . avatar($partner, 40)
            . '<div class="chat-who">' . user_link($partner) . ($seen !== '' ? '<small' . ($seen === messages_t('online') ? ' class="is-online"' : '') . '>' . h($seen) . '</small>' : '') . '</div>' . $menu . '</div>'
            . '<div class="chat-log" data-log>' . $bubbles . '</div>'
            . ($why !== '' ? '<div class="chat-input muted">' . h($why) . '</div>'
                : '<div class="chat-editing" data-chat-editing hidden>' . icon('edit') . '<span>' . messages_t('Editing message') . '</span><button type="button" class="btn btn-sm btn-ghost" data-chat-cancel>' . messages_t('Cancel') . '</button></div>'
                . '<form class="chat-input" data-chat-form action="' . h($base . '/send') . '" method="post">' . csrf_field() . '<textarea name="body" rows="1" maxlength="4000" placeholder="' . h(messages_t('Message')) . '" dir="auto" required></textarea>' . $send . '</form>')
            . '</div>';
    }
    $html = '<div class="chat' . ($partner !== null ? ' chat-open' : '') . '"><div class="chat-list"><div class="chat-list-head"><h3>' . messages_t('Messages') . '</h3><input type="search" class="chat-search" data-chat-search placeholder="' . h(messages_t('Search chats')) . '" autocomplete="off"></div>' . $list . '</div>' . $pane . '</div>';
    page($partner !== null ? messages_name($partner) . ' · ' . messages_t('Messages') : messages_t('Messages'), $html, ['class' => 'page-messages' . ($partner !== null ? ' page-messages-chat' : ''), 'robots' => 'noindex', 'left' => false, 'right' => false]);
}

/** POST /messages/{name}/send (AJAX): store the message, answer with its bubble. */
function messages_send_route(string $name): never
{
    $me = need_login();
    require_post();
    $to = messages_partner($name, $me);
    $why = messages_can_send($me);
    if ($why === '' && messages_blocked((int)$me['id'], (int)$to['id'])) $why = messages_t('You blocked %s.', messages_name($to)); // I blocked them: unblock first
    if ($why === '' && !messages_accepts($to, $me)) $why = messages_t('%s does not accept messages.', messages_name($to));
    if ($why !== '') json_error($why);
    $body = trim(post_str('body', 4000));
    if ($body === '') json_error(messages_t('Write a message.'));
    $t = messages_thread((int)$me['id'], (int)$to['id'], true);
    if ($t === null) json_error(messages_t('Message not found.'), 404);
    $p = messages_send($t, $me, $to, $body);
    $prev = one('SELECT created_at FROM plugin_messages_posts WHERE thread_id=? AND id<? AND id>? ORDER BY id DESC LIMIT 1', [(int)$t['id'], (int)$p['id'], messages_floor((int)$t['id'], (int)$me['id'])]);
    json_ok(['html' => messages_render([$p], (int)$me['id'], messages_their_read((int)$t['id'], (int)$to['id']), messages_name($to), $prev ? date('Y-m-d', (int)$prev['created_at']) : ''), 'last' => (int)$p['id']]);
}

/**
 * GET /messages/{name}/poll?after=id&since=ts (every few seconds): bubbles newer than id, bubbles edited or recalled since ts
 * (as id => html), when the other member last read the chat, and the server time to send as since next time.
 */
function messages_poll(string $name): never
{
    $me = need_login();
    $to = messages_partner($name, $me);
    $t = messages_thread((int)$me['id'], (int)$to['id'], false);
    if ($t === null) json_ok(['html' => '', 'last' => 0, 'changed' => new stdClass(), 'read' => 0, 'now' => now()]);
    $floor = messages_floor((int)$t['id'], (int)$me['id']);
    $after = get_int('after', 0, 0);
    $since = get_int('since', 0, 0);
    $posts = all('SELECT * FROM plugin_messages_posts WHERE thread_id=? AND id>? ORDER BY id ASC LIMIT 50', [(int)$t['id'], max($after, $floor)]);
    if ($posts !== []) db_update('plugin_messages_members', ['last_read' => now()], 'thread_id=? AND user_id=?', [(int)$t['id'], (int)$me['id']]);
    $their = messages_their_read((int)$t['id'], (int)$to['id']);
    $prev = $after > $floor ? one('SELECT created_at FROM plugin_messages_posts WHERE thread_id=? AND id<=? AND id>? ORDER BY id DESC LIMIT 1', [(int)$t['id'], $after, $floor]) : null;
    $html = messages_render($posts, (int)$me['id'], $their, messages_name($to), $prev ? date('Y-m-d', (int)$prev['created_at']) : '');
    $changed = new stdClass(); // an object even when empty or when the ids happen to be 0, 1, 2…
    if ($since > 0 && $after > $floor) {
        foreach (all('SELECT * FROM plugin_messages_posts WHERE thread_id=? AND id<=? AND id>? AND updated_at>=? ORDER BY id ASC LIMIT 100', [(int)$t['id'], $after, $floor, $since]) as $c) {
            $changed->{(string)(int)$c['id']} = messages_bubble($c, (int)$me['id'], $their, messages_name($to));
        }
    }
    json_ok(['html' => $html, 'last' => $posts !== [] ? (int)end($posts)['id'] : $after, 'changed' => $changed, 'read' => $their, 'now' => now()]);
}

/**
 * GET /messages/{name}/older?before=id (scrolling up): the MESSAGES_PAGE messages before id. The run starts with its own date
 * line; same_day says the message at id falls on the same day as the last of the run, so the date line above it goes.
 */
function messages_older(string $name): never
{
    $me = need_login();
    $to = messages_partner($name, $me);
    $t = messages_thread((int)$me['id'], (int)$to['id'], false);
    $before = get_int('before', 0, 0);
    if ($t === null || $before <= 0) json_ok(['html' => '', 'first' => $before, 'more' => false, 'same_day' => false]);
    $floor = messages_floor((int)$t['id'], (int)$me['id']);
    $posts = array_reverse(all('SELECT * FROM plugin_messages_posts WHERE thread_id=? AND id<? AND id>? ORDER BY id DESC LIMIT ' . MESSAGES_PAGE, [(int)$t['id'], $before, $floor]));
    if ($posts === []) json_ok(['html' => '', 'first' => $before, 'more' => false, 'same_day' => false]);
    $next = (int)(val('SELECT created_at FROM plugin_messages_posts WHERE thread_id=? AND id=?', [(int)$t['id'], $before]) ?? 0);
    json_ok([
        'html' => messages_render($posts, (int)$me['id'], messages_their_read((int)$t['id'], (int)$to['id']), messages_name($to)),
        'first' => (int)$posts[0]['id'],
        'more' => count($posts) >= MESSAGES_PAGE,
        'same_day' => $next > 0 && date('Y-m-d', $next) === date('Y-m-d', (int)end($posts)['created_at']),
    ]);
}

/** POST /messages/{name}/leave: delete the chat for me. Its history never comes back for me; when the other member has deleted it too, it is erased from the database. */
function messages_leave(string $name): never
{
    $me = need_login();
    require_post();
    $to = messages_partner($name, $me);
    $t = messages_thread((int)$me['id'], (int)$to['id'], false);
    if ($t !== null) messages_clear($t, (int)$me['id']);
    redirect(url('/messages'));
}

/** POST /messages/block: block or unblock a member. */
function messages_block(): never
{
    $me = need_login();
    require_post();
    $who = user_by_id(post_int('id'));
    if ($who === null || (int)$who['id'] === (int)$me['id']) fail(messages_t('User not found.'), url('/messages'));
    if (post_str('action', 10) === 'unblock') db_delete('plugin_messages_blocks', 'user_id=? AND blocked_id=?', [(int)$me['id'], (int)$who['id']]);
    else db_insert_ignore('plugin_messages_blocks', ['user_id' => (int)$me['id'], 'blocked_id' => (int)$who['id']]);
    redirect(url('/messages/' . rawurlencode((string)$who['username'])));
}

/* ---------------------------------------------------------------- assets */
function messages_css(): string
{
    return <<<'CSS'
.page-messages .layout-main{max-width:100%!important;padding:0!important}
.chat{--chat-bg:var(--panel-2);display:grid;grid-template-columns:340px 1fr;height:min(80vh,880px);min-height:460px;overflow:hidden;background:var(--panel);border:1px solid var(--line);border-radius:14px}
.chat .avatar{flex-shrink:0;border-radius:50%}
.chat span.avatar,.chat div.avatar{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;font-weight:700;display:flex;align-items:center;justify-content:center}
/* list */
.chat-list{border-inline-end:1px solid var(--line);overflow-y:auto;background:var(--panel);scrollbar-width:thin}
.chat-list-head{position:sticky;top:0;z-index:2;padding:14px 16px 12px;background:var(--panel);border-bottom:1px solid var(--line)}
.chat-list-head h3{margin:0;font-size:19px;font-weight:700;color:var(--text)}
.chat-search{display:block;width:100%;margin-top:10px;padding:9px 16px;border:1px solid transparent;border-radius:20px;background:var(--panel-2);color:var(--text);font:inherit;font-size:14px;transition:border-color .15s,background .15s}
.chat-search:focus{outline:none;border-color:var(--brand);background:var(--panel)}
.chat-item{display:flex;gap:12px;align-items:center;padding:10px 14px;color:inherit;text-decoration:none;transition:background .12s}
.chat-item[hidden]{display:none}
.chat-item:hover{background:var(--panel-2);text-decoration:none}
.chat-item.active{background:var(--brand-soft)}
.chat-item-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:4px}
.chat-item-top,.chat-item-bottom{display:flex;align-items:center;justify-content:space-between;gap:8px}
.chat-item-top b{font-size:15px;font-weight:600;color:var(--text);text-overflow:ellipsis;overflow:hidden;white-space:nowrap}
.chat-item-top time{font-size:12px;color:var(--text-muted);white-space:nowrap}
.chat-item-last{flex:1;min-width:0;font-size:13.5px;color:var(--text-muted);text-overflow:ellipsis;overflow:hidden;white-space:nowrap}
.chat-item-last em{font-style:italic}
.chat-item.unread .chat-item-last{color:var(--text)}
.chat-item.unread .chat-item-top time{color:var(--brand);font-weight:600}
.chat-badge{min-width:21px;height:21px;padding:0 6px;border-radius:11px;background:var(--brand);color:#fff;font-size:12px;font-weight:700;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.chat-item-last .chat-tick{margin-inline-end:4px;vertical-align:-1px;color:var(--text-muted)}
.chat-item-last .chat-tick.is-read{color:var(--brand)}
/* pane and header */
.chat-pane{display:flex;flex-direction:column;min-width:0;min-height:0;position:relative;overflow:hidden}
.chat-head{display:flex;align-items:center;gap:12px;padding:8px 14px;border-bottom:1px solid var(--line);background:var(--panel);flex-shrink:0;position:relative;z-index:3}
.chat-back{display:none;color:inherit;width:36px;height:36px;border-radius:50%;align-items:center;justify-content:center}
.chat-back:hover{background:var(--panel-2)}
.chat-who{display:flex;flex-direction:column;min-width:0;line-height:1.25;flex:1;}
.chat-who a{font-size:15.5px;font-weight:600;color:var(--text);text-decoration:none;text-overflow:ellipsis;overflow:hidden;white-space:nowrap}
.chat-who a:hover{text-decoration:underline}
.chat-who small{color:var(--text-muted);font-size:12.5px;margin-top:2px}
.chat-who small.is-online{color:var(--brand)}
.chat-menu{position:relative}
.chat-menu-btn{list-style:none;display:flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;color:var(--text-muted);cursor:pointer;transition:background .12s}
.chat-menu-btn::-webkit-details-marker{display:none}
.chat-menu-btn:hover,.chat-menu[open] .chat-menu-btn{background:var(--panel-2);color:var(--text)}
.chat-menu-pop{position:absolute;inset-inline-end:0;top:calc(100% + 6px);min-width:180px;padding:6px;background:var(--panel);border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 28px rgba(0,0,0,.16);z-index:10}
.chat-menu-pop form{display:block;margin:0}
.chat-menu-item{display:block;width:100%;text-align:start;padding:10px 12px;border:0;border-radius:8px;background:none;color:var(--text);font:inherit;font-size:14px;cursor:pointer}
.chat-menu-item:hover{background:var(--panel-2)}
.chat-menu-item.is-danger{color:#d32f2f}
.chat-more{text-align:center;color:var(--text-muted);font-size:12.5px;padding:6px 0;font-weight:500}
/* log: my messages on the right, theirs on the left, whatever the page direction */
.chat-log{background-color:var(--chat-bg);background-image:radial-gradient(circle at 15% 12%,rgba(127,127,127,.07) 0,transparent 38%),radial-gradient(circle at 85% 88%,rgba(127,127,127,.07) 0,transparent 38%);flex:1;min-height:0;overflow-y:scroll;padding:14px 20px;display:flex;flex-direction:column;gap:8px;scrollbar-width:thin;scrollbar-color:var(--line) transparent;direction:ltr;overscroll-behavior:contain}
.chat-log::-webkit-scrollbar{width:5px}.chat-log::-webkit-scrollbar-thumb{background:var(--line);border-radius:3px}
.chat-day{text-align:center;margin:10px 0 4px;position:sticky;top:0;z-index:1}
.chat-day span{display:inline-block;padding:4px 12px;background:rgba(127,127,127,.22);color:var(--text);border-radius:12px;font-size:12px;font-weight:600;backdrop-filter:blur(4px)}
.chat-msg{display:flex;align-items:flex-end;gap:8px;max-width:100%}
.chat-msg.me{justify-content:flex-end}
.chat-msg.me+.chat-msg.me,.chat-msg:not(.me)+.chat-msg:not(.me){margin-top:-5px}
/* bubbles with a tail on the last one of a run */
.bubble{max-width:min(70%,560px);padding:8px 12px 7px;border-radius:18px 18px 18px 5px;line-height:1.55;font-size:14.5px;position:relative;overflow-wrap:anywhere;background:var(--panel);color:var(--text);box-shadow:0 1px 1px rgba(0,0,0,.08);text-align:start}
.chat-msg.me .bubble{background:var(--brand);color:#fff;border-radius:18px 18px 5px 18px}
.chat-msg:not(.me):not(.chat-recalled):has(+ .chat-msg:not(.me):not(.chat-recalled)) .bubble{border-bottom-left-radius:18px}
.chat-msg.me:has(+ .chat-msg.me) .bubble{border-bottom-right-radius:18px}
.bubble .post-content{display:inline;line-height:1.55}
.bubble .post-content p{display:inline;margin:0}
.bubble .post-content p+p::before{content:"\A";white-space:pre}
.bubble a{text-decoration:underline}
.chat-msg.me .bubble a{color:#e3f2fd}
.chat-meta{display:inline-flex;align-items:center;gap:4px;margin-inline-start:10px;white-space:nowrap;font-weight:500;font-size:11px;color:var(--text-muted);vertical-align:bottom;position:relative;top:3px;opacity:.85}
.chat-msg.me .chat-meta{color:rgba(255,255,255,.82)}
.chat-edited{font-style:italic;font-size:10.5px}
.chat-tick{display:inline-flex;line-height:0}
.chat-tick svg{width:14px;height:10px}
.chat-tick .chat-tick-2{display:none}
.chat-tick.is-read .chat-tick-2{display:inline}
.chat-msg.me .chat-meta:has(.is-read){opacity:1}
.chat-clock svg{width:12px;height:12px}
.chat-pending .bubble{opacity:.72;white-space:pre-wrap}
/* tools on my own message */
.chat-tools{display:none;gap:4px}
.chat-msg.me:hover .chat-tools,.chat-msg.show-tools .chat-tools,.chat-tools:focus-within{display:inline-flex}
.chat-tool{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;border:0;border-radius:50%;background:var(--panel);color:var(--text-muted);cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.15);transition:transform .12s}
.chat-tool:hover{color:var(--text);transform:scale(1.08)}
.chat-tool svg{width:14px;height:14px}
.chat-recalled{justify-content:center}
.chat-note{padding:4px 12px;border-radius:12px;background:rgba(127,127,127,.2);color:var(--text);font-size:12.5px;font-weight:500;text-align:center}
/* new messages slide in */
@keyframes chat-pop{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:none}}
.chat-new{animation:chat-pop .18s ease-out}
.chat-msg.me.chat-new{transform-origin:100% 100%}
.chat-msg.chat-new:not(.me){transform-origin:0 100%}
@media (prefers-reduced-motion:reduce){.chat-new{animation:none}}
/* jump to the latest message */
.chat-jump{position:absolute;inset-inline-end:16px;z-index:4;width:42px;height:42px;border-radius:50%;border:1px solid var(--line);background:var(--panel);color:var(--text-muted);box-shadow:0 3px 10px rgba(0,0,0,.18);display:flex;align-items:center;justify-content:center;cursor:pointer;transition:transform .12s}
.chat-jump[hidden]{display:none}
.chat-jump:hover{color:var(--text);transform:translateY(-1px)}
.chat-jump-n{position:absolute;top:-7px;inset-inline-start:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:var(--brand);color:#fff;font-size:11.5px;font-weight:700;display:flex;align-items:center;justify-content:center}
.chat-jump-n[hidden]{display:none}
/* input */
.chat-editing{display:flex;align-items:center;gap:10px;padding:8px 16px;border-top:1px solid var(--line);background:var(--panel);color:var(--text-muted);font-size:13px;font-weight:500}
.chat-editing svg{width:15px;height:15px;color:var(--brand)}
.chat-editing span{flex:1}
.chat-editing[hidden]{display:none}
.chat-editing+.chat-input{border-top:0}
.chat-input{display:flex;gap:10px;padding:10px 14px;border-top:1px solid var(--line);align-items:flex-end;background:var(--panel);flex-shrink:0}
.chat-input.muted{justify-content:center;padding:16px;font-size:14px}
.chat-input textarea { flex: 1; resize: none; min-height: 44px; max-height: 140px; padding: 11px 18px; border: 2px solid transparent;font: inherit; font-size: 15px; background: var(--panel-2); color: var(--text); line-height: 1.5; transition: border-color .15s, background .15s; }
.chat-input textarea:focus { outline: none; border-color: var(--brand); }
.chat-input textarea::placeholder{color:var(--text-muted)}
.chat-send{flex-shrink:0;width:44px;height:44px;padding:0;border-radius:50%;border:0;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.45;transform:scale(.92);transition:opacity .15s,transform .15s}
.chat-input.has-text .chat-send{opacity:1;transform:none}
.chat-send:active{transform:scale(.94)}
.chat-empty{padding:50px 20px;text-align:center;color:var(--text-muted);display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100%}
.chat-empty svg{width:50px;height:50px;margin-bottom:14px;opacity:.3}
.chat-empty p{margin:0;font-size:14px;font-weight:500}
.chat-none .chat-empty{margin:auto}
/* phones: an open chat fills the screen */
@media (max-width:760px){
.chat{grid-template-columns:1fr;height:100vh;border:0;border-radius:0}
.chat:not(.chat-open) .chat-list{overflow:visible}
.chat-open .chat-list{display:none}
.chat:not(.chat-open) .chat-pane{display:none}
.chat-open{position:fixed;left:0;right:0;top:0;z-index:85;height:100vh;height:100dvh;height:var(--chat-vh,100dvh)}
.chat-back{display:flex}
.chat-log{padding:12px 12px calc(12px + env(safe-area-inset-bottom))}
.chat-open .bubble{max-width:82%}
body.page-messages-chat,.body.page-messages-chat{overflow:hidden}
.chat-input{padding:8px 10px calc(8px + env(safe-area-inset-bottom))}
.chat-input textarea{font-size:16px}
.chat-msg.me:hover .chat-tools{display:none}
.chat-msg.show-tools .chat-tools{display:inline-flex}
}
CSS;
}

/**
 * Send without reloading and without waiting: Enter sends (Shift+Enter breaks the line), the message shows at once with a
 * clock and turns into the real bubble when the server has it. Poll every 4 s, keep the log scrolled down (a button with a
 * counter appears when new messages arrive while reading further up). Edit puts my message back into the input (Escape or
 * Cancel stops); Recall asks first; poll brings the other side's edits, recalls and the moment they read the chat (ticks turn
 * double); the edit and recall buttons go away when their time is up.
 */
function messages_js(): string
{
    return <<<'JS'
(function () {
  var search = document.querySelector('[data-chat-search]');
  if (search) search.addEventListener('input', function () {
    var v = search.value.trim().toLowerCase();
    Array.prototype.forEach.call(document.querySelectorAll('.chat-list .chat-item'), function (it) {
      var b = it.querySelector('b');
      it.hidden = !!v && (b ? b.textContent : '').toLowerCase().indexOf(v) < 0;
    });
  });
  var pane = document.querySelector('[data-chat]'); if (!pane) return;
  var log = pane.querySelector('[data-log]'), form = pane.querySelector('[data-chat-form]'), bar = pane.querySelector('[data-chat-editing]');
  var base = pane.getAttribute('data-chat'), last = parseInt(pane.getAttribute('data-last') || '0', 10);
  var since = parseInt(pane.getAttribute('data-now') || '0', 10), read = parseInt(pane.getAttribute('data-read') || '0', 10);
  var skew = since - Math.floor(Date.now() / 1000); // server clock minus this one, for the edit and recall windows
  var ta = form ? form.querySelector('textarea') : null, editing = 0, busy = false, polling = false, sending = 0, chain = Promise.resolve(), unseen = 0;
  var first = parseInt(pane.getAttribute('data-first') || '0', 10), more = pane.hasAttribute('data-more'), loading = false;
  var fa = pane.hasAttribute('data-fa');
  var chat = pane.closest('.chat'), phone = window.matchMedia('(max-width: 760px)');
  function digits(s) { return fa ? String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(d); }) : String(s); }
  function failed() { return (window.FB && FB.i18n && FB.i18n.failed) || 'Error'; }
  function down(smooth) { if (smooth && log.scrollTo) log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' }); else log.scrollTop = log.scrollHeight; }
  function atBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 80; }

  /* the button that jumps to the newest message, with a count of what arrived meanwhile */
  var jump = document.createElement('button');
  jump.type = 'button'; jump.className = 'chat-jump'; jump.hidden = true;
  jump.setAttribute('aria-label', pane.getAttribute('data-label-latest') || '');
  jump.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg><span class="chat-jump-n" hidden></span>';
  pane.appendChild(jump);
  var jumpN = jump.querySelector('.chat-jump-n');
  function placeJump() { var inp = pane.querySelector('.chat-input'); jump.style.bottom = ((inp ? inp.offsetHeight : 0) + 14) + 'px'; }
  function updJump() {
    var far = log.scrollHeight - log.scrollTop - log.clientHeight > 240;
    jump.hidden = !far;
    if (!far) { unseen = 0; jumpN.hidden = true; } else placeJump();
  }
  jump.addEventListener('click', function () { down(true); });
  log.addEventListener('scroll', updJump);

  /* phones: the chat is as tall as what is visible, so the input stays on top of the keyboard */
  function fit() {
    var v = window.visualViewport;
    if (!v || !phone.matches) { chat.style.removeProperty('--chat-vh'); chat.style.top = ''; return; }
    var stick = atBottom();
    chat.style.setProperty('--chat-vh', Math.round(v.height) + 'px');
    chat.style.top = Math.round(v.offsetTop) + 'px';
    if (stick) down();
  }
  if (window.visualViewport) { visualViewport.addEventListener('resize', fit); visualViewport.addEventListener('scroll', fit); }
  fit();

  /* put a chunk of bubbles at the end of the log, skipping the ones already there (a message I just sent may come back through poll) */
  function addChunk(html, track) {
    if (!html) return [];
    var tpl = document.createElement('template'), day = null, added = [];
    tpl.innerHTML = html;
    Array.prototype.slice.call(tpl.content.children).forEach(function (n) {
      if (n.classList.contains('chat-day')) { day = n; return; }
      var id = n.getAttribute('data-id');
      if (id && log.querySelector('.chat-msg[data-id="' + id + '"]')) { day = null; return; }
      if (sending > 0 && n.classList.contains('me')) { day = null; return; } // my own: the send answer puts it in place
      if (day) { log.appendChild(day); added.push(day); day = null; }
      n.classList.add('chat-new');
      log.appendChild(n); added.push(n);
      var i = parseInt(id, 10);
      if (track && i > last) last = i;
    });
    setTimeout(function () { added.forEach(function (n) { n.classList.remove('chat-new'); }); }, 400);
    return added;
  }

  /* scrolling to the top brings the earlier messages, keeping the one in view where it was */
  log.addEventListener('scroll', function () {
    if (!more || loading || first <= 0 || log.scrollTop > 80) return;
    loading = true;
    var tip = document.createElement('div');
    tip.className = 'chat-more';
    tip.textContent = pane.getAttribute('data-label-loading');
    log.insertBefore(tip, log.firstChild);
    FB.request(base + '/older?before=' + first).then(function (r) {
      if (tip.parentNode) tip.parentNode.removeChild(tip);
      loading = false;
      if (!r.ok) return;
      more = !!r.more;
      if (!r.html) return;
      var top = log.firstElementChild;
      if (r.same_day && top && top.classList.contains('chat-day')) top.parentNode.removeChild(top);
      var h = log.scrollHeight, y = log.scrollTop;
      log.insertAdjacentHTML('afterbegin', r.html);
      log.scrollTop = y + (log.scrollHeight - h);
      first = r.first;
    }, function () { loading = false; if (tip.parentNode) tip.parentNode.removeChild(tip); });
  });
  function replace(id, html) {
    var el = log.querySelector('.chat-msg[data-id="' + id + '"]');
    if (!el || !html) return;
    el.insertAdjacentHTML('afterend', html);
    el.parentNode.removeChild(el);
  }
  function ticks() {
    Array.prototype.forEach.call(log.querySelectorAll('.chat-msg.me .chat-tick:not(.is-read):not(.chat-clock)'), function (tk) {
      if (parseInt(tk.closest('.chat-msg').getAttribute('data-at'), 10) > read) return;
      tk.classList.add('is-read');
      tk.title = pane.getAttribute('data-label-read');
      tk.setAttribute('aria-label', tk.title);
    });
  }
  function expire() {
    var now = Math.floor(Date.now() / 1000) + skew;
    Array.prototype.forEach.call(log.querySelectorAll('.chat-msg[data-edit-until],.chat-msg[data-recall-until]'), function (m) {
      ['edit', 'recall'].forEach(function (k) {
        var until = parseInt(m.getAttribute('data-' + k + '-until') || '0', 10);
        if (!until || until >= now) return;
        var b = m.querySelector('[data-chat-' + k + ']');
        if (b) b.parentNode.removeChild(b);
        m.removeAttribute('data-' + k + '-until');
      });
      var tools = m.querySelector('.chat-tools');
      if (tools && !tools.children.length) tools.parentNode.removeChild(tools);
    });
  }
  function stopEdit() {
    editing = 0;
    if (bar) bar.hidden = true;
    if (ta) { ta.value = ''; ta.style.height = 'auto'; form.classList.remove('has-text'); }
  }
  down();

  /* a message shows at once (with a clock) and is swapped for the real bubble when the server answers; they go one after another */
  var clockSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
  function unsend(el, text, msg) {
    if (el.parentNode) el.parentNode.removeChild(el);
    FB.toast(msg || failed(), 'error');
    if (!ta.value) { ta.value = text; ta.dispatchEvent(new Event('input')); }
  }
  function sendNew(text, fd) {
    var el = document.createElement('div'), b = document.createElement('div'), s = document.createElement('span'), m = document.createElement('span'), d = new Date();
    el.className = 'chat-msg me chat-pending chat-new';
    b.className = 'bubble'; b.setAttribute('dir', 'auto');
    s.className = 'post-content'; s.textContent = text;
    m.className = 'chat-meta';
    m.innerHTML = '<time>' + digits(('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2)) + '</time><span class="chat-tick chat-clock">' + clockSvg + '</span>';
    b.appendChild(s); b.appendChild(m); el.appendChild(b);
    log.appendChild(el); down();
    sending++;
    chain = chain.then(function () { return FB.request(form.getAttribute('action'), { method: 'POST', body: fd }); }).then(function (r) {
      sending--;
      if (!r.ok) { unsend(el, text, r.error); return; }
      if (log.querySelector('.chat-msg[data-id="' + r.last + '"]')) { if (el.parentNode) el.parentNode.removeChild(el); }
      else if (el.parentNode) { el.insertAdjacentHTML('beforebegin', r.html); el.parentNode.removeChild(el); }
      down();
      if (!document.querySelector('.chat-item.active')) window.location.reload(); // first message: the list on the left does not know this chat yet
    }, function () { sending--; unsend(el, text); }); // a lost connection gives the text back instead of losing it
  }

  if (form) {
    ta.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); }
      if (e.key === 'Escape' && editing) { e.preventDefault(); stopEdit(); }
    });
    ta.addEventListener('input', function () {
      ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px';
      form.classList.toggle('has-text', !!ta.value.trim());
      placeJump();
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = ta.value.trim();
      if (!text) return;
      var fd = new FormData(form), id = editing;
      if (!id) { ta.value = ''; ta.style.height = 'auto'; form.classList.remove('has-text'); sendNew(text, fd); ta.focus(); return; }
      if (busy) return;
      fd.append('id', id);
      busy = true;
      ta.readOnly = true; // readOnly, not disabled: the keyboard on a phone stays up
      function done() { busy = false; ta.readOnly = false; }
      FB.request(base + '/edit', { method: 'POST', body: fd }).then(function (r) {
        done();
        if (!r.ok) { FB.toast(r.error || failed(), 'error'); return; }
        replace(r.id, r.html); stopEdit(); ta.focus();
      }, function () { done(); FB.toast(failed(), 'error'); });
    });
    if (bar) bar.querySelector('[data-chat-cancel]').addEventListener('click', function () { stopEdit(); ta.focus(); });
    ta.addEventListener('focus', function () { setTimeout(fit, 300); }); // the keyboard is up by then
    if (!phone.matches) ta.focus(); // on a phone the keyboard would cover the conversation before it is read
  }
  log.addEventListener('click', function (e) {
    var msg = e.target.closest('.chat-msg');
    if (!msg) return;
    if (e.target.closest('[data-chat-edit]') && ta) {
      editing = parseInt(msg.getAttribute('data-id'), 10);
      ta.value = msg.getAttribute('data-raw') || '';
      if (bar) bar.hidden = false;
      ta.dispatchEvent(new Event('input'));
      ta.focus();
      return;
    }
    if (e.target.closest('[data-chat-recall]')) {
      if (!window.confirm(pane.getAttribute('data-confirm-recall'))) return;
      var fd = new FormData();
      fd.append('id', msg.getAttribute('data-id'));
      FB.request(base + '/recall', { method: 'POST', body: fd }).then(function (r) {
        if (!r.ok) { FB.toast(r.error || failed(), 'error'); return; }
        if (editing === r.id) stopEdit();
        replace(r.id, r.html);
      }, function () { FB.toast(failed(), 'error'); });
      return;
    }
    if (msg.classList.contains('me') && e.target.closest('.bubble') && !e.target.closest('a')) { // a tap shows the tools on a phone
      var open = log.querySelector('.chat-msg.show-tools');
      if (open && open !== msg) open.classList.remove('show-tools');
      msg.classList.toggle('show-tools');
    }
  });
  document.addEventListener('click', function (e) { // the header menu closes when you click elsewhere
    var m = pane.querySelector('.chat-menu[open]');
    if (m && !m.contains(e.target)) m.removeAttribute('open');
  });
  function poll() {
    if (document.hidden || polling) return; // one request at a time: a slow server is not hit by a pile of them
    polling = true;
    FB.request(base + '/poll?after=' + last + '&since=' + since).then(function (r) {
      polling = false;
      if (!r.ok) return;
      var stick = atBottom(), added = addChunk(r.html, true), fresh = 0;
      added.forEach(function (n) { if (n.classList.contains('chat-msg') && !n.classList.contains('me')) fresh++; });
      if (sending === 0 && r.last > last) last = r.last; // nothing in flight: whatever the answer held is now shown
      if (stick) down();
      else if (fresh) { unseen += fresh; jumpN.textContent = digits(unseen); jumpN.hidden = false; jump.hidden = false; placeJump(); }
      Object.keys(r.changed || {}).forEach(function (id) { if (parseInt(id, 10) !== editing) replace(id, r.changed[id]); });
      if (r.now) since = r.now;
      if (r.read > read) read = r.read;
      ticks();
    }, function () { polling = false; });
  }
  setInterval(poll, 4000);
  setInterval(expire, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();
JS;
}
