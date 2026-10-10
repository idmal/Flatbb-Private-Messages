<?php
/**
 * Admin → Messages: every conversation, and one conversation read-only (the text as first sent of an edited or recalled
 * message included, marked). Administrators only, behind the password confirmation, and every conversation opened is
 * written to the admin log (who read whose messages). An administrator can also erase a conversation for good (logged).
 * Loaded by plugin.php.
 */
if (!defined('FLATBB')) exit;

/** GET /admin/ext/messages/list[?q=name][&thread=id] */
function messages_admin(string $page): never
{
    need_admin();
    need_sudo();
    $base = url('/admin/ext/messages/list');
    $tid = get_int('thread');
    if ($tid > 0) messages_admin_thread($tid, $base);
    $q = trim(get_str('q', 60));
    $who = $q !== '' ? user_by_name($q) : null;
    $where = 'msg_count>0';
    $params = [];
    if ($who !== null) { $where .= ' AND (pair LIKE ? OR pair LIKE ?)'; $params = [(int)$who['id'] . ':%', '%:' . (int)$who['id']]; }
    $pg = paginate_calc($q !== '' && $who === null ? 0 : (int)val("SELECT COUNT(*) FROM plugin_messages_threads WHERE {$where}", $params), get_int('page', 1, 1, 100000), 30);
    $rows = $q !== '' && $who === null ? [] : all("SELECT * FROM plugin_messages_threads WHERE {$where} ORDER BY last_at DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
    // conversations of members who no longer exist are erased, not listed
    if ($rows !== [] && messages_purge_orphans($rows) > 0) {
        $rows = $q !== '' && $who === null ? [] : all("SELECT * FROM plugin_messages_threads WHERE {$where} ORDER BY last_at DESC LIMIT " . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], $params);
    }
    $ids = [];
    foreach ($rows as $r) foreach (explode(':', (string)$r['pair']) as $id) $ids[] = (int)$id;
    $users = users_by_ids($ids);
    $html = '<form class="messages-admin-search" method="get" action="' . h($base) . '"><input type="search" name="q" value="' . h($q) . '" placeholder="' . h(messages_t('Member name')) . '"><button class="btn">' . messages_t('Search') . '</button>'
        . ($q !== '' ? '<a class="btn btn-ghost" href="' . h($base) . '">' . messages_t('Show all') . '</a>' : '') . '</form>';
    if ($q !== '' && $who === null) $html .= '<p class="muted">' . h(messages_t('No member is called %s.', $q)) . '</p>';
    $list = '';
    foreach ($rows as $r) {
        [$a, $b] = array_map('intval', explode(':', (string)$r['pair']));
        $last = (string)$r['last_text'] !== '' ? h((string)$r['last_text']) : '<em>' . messages_t('Recalled') . '</em>';
        $list .= '<a class="messages-admin-row" href="' . h($base . '?thread=' . (int)$r['id']) . '"><div class="messages-admin-who"><span><b>' . h(messages_admin_name($users[$a] ?? null)) . '</b> <span class="muted">↔</span> <b>' . h(messages_admin_name($users[$b] ?? null)) . '</b></span>'
            . '<small class="muted">' . $last . ' · ' . messages_t('%d messages', (int)$r['msg_count']) . '</small></div><time class="muted">' . h(messages_when((int)$r['last_at'], true)) . '</time></a>';
    }
    $html .= $list !== '' ? '<div class="card messages-admin-list">' . $list . '</div>' : ($q === '' ? '<div class="empty">' . icon('message') . '<p>' . messages_t('No conversations yet.') . '</p></div>' : '');
    $html .= pagination($pg, static fn(int $n): string => $base . '?' . http_build_query(array_filter(['q' => $q, 'page' => $n > 1 ? $n : null])));
    admin_page(messages_t('Messages'), messages_admin_css() . '<p class="muted">' . messages_t('Each conversation you open is written to the admin log.') . '</p>' . $html, 'ext.messages.list');
}

function messages_admin_name(?array $u): string
{
    return $u === null ? messages_t('deleted') : (messages_name($u) !== (string)$u['username'] ? messages_name($u) . ' (@' . $u['username'] . ')' : (string)$u['username']); // an admin sees which account
}

/** POST /messages-admin/purge (thread, action=purge): erase a conversation for both members; logged. */
function messages_admin_purge(): never
{
    need_admin();
    need_sudo();
    require_post();
    $t = one('SELECT * FROM plugin_messages_threads WHERE id=?', [post_int('thread')]);
    if ($t !== null && post_str('action', 10) === 'purge') {
        [$a, $b] = array_map('intval', explode(':', (string)$t['pair']));
        $u = users_by_ids([$a, $b]);
        admin_log('messages.purge', 'thread:' . (int)$t['id'], messages_admin_name($u[$a] ?? null) . ' ↔ ' . messages_admin_name($u[$b] ?? null));
        messages_purge((int)$t['id']);
    }
    redirect(url('/admin/ext/messages/list'));
}

/** One conversation, oldest first, 200 a page (the newest page opens first); logged. */
function messages_admin_thread(int $tid, string $base): never
{
    $t = one('SELECT * FROM plugin_messages_threads WHERE id=?', [$tid]);
    if ($t === null) not_found();
    [$a, $b] = array_map('intval', explode(':', (string)$t['pair']));
    $users = users_by_ids([$a, $b]);
    $names = [$a => messages_admin_name($users[$a] ?? null), $b => messages_admin_name($users[$b] ?? null)];
    $total = (int)val('SELECT COUNT(*) FROM plugin_messages_posts WHERE thread_id=?', [$tid]);
    $per = 200;
    $pages = max(1, (int)ceil($total / $per));
    $pg = paginate_calc($total, get_int('page', $pages, 1, 100000), $per);
    $posts = all('SELECT * FROM plugin_messages_posts WHERE thread_id=? ORDER BY id ASC LIMIT ' . (int)$pg['per_page'] . ' OFFSET ' . (int)$pg['offset'], [$tid]);
    admin_log('messages.view', 'thread:' . $tid, $names[$a] . ' ↔ ' . $names[$b] . ($pages > 1 ? ' (page ' . (int)$pg['page'] . ')' : ''));
    $log = '';
    $day = '';
    foreach ($posts as $p) {
        $d = date('Y-m-d', (int)$p['created_at']);
        if ($d !== $day) { $log .= '<div class="chat-day"><span>' . h(messages_date((int)$p['created_at'])) . '</span></div>'; $day = $d; }
        $right = (int)$p['user_id'] === $b;
        $recalled = (int)($p['recalled_at'] ?? 0) > 0;
        $text = $recalled ? (string)($p['original'] ?? '') : (string)$p['body'];
        $body = $text !== '' ? '<span class="post-content">' . md($text) . '</span>' : '<em class="muted">' . messages_t('The text was not kept.') . '</em>';
        $tags = $recalled ? '<span class="messages-admin-tag">' . messages_t('Recalled') . '</span>' : '';
        if (!$recalled && (int)($p['edited_at'] ?? 0) > 0 && (string)($p['original'] ?? '') !== '') $tags .= '<span class="messages-admin-was">' . h(messages_t('Edited · was: %s', cut((string)$p['original'], 300))) . '</span>';
        $log .= '<div class="messages-admin-msg' . ($right ? ' is-right' : '') . ($recalled ? ' is-recalled' : '') . '"><div class="messages-admin-bubble" dir="auto">' . $body
            . '<div class="messages-admin-meta">' . h($names[(int)$p['user_id']] ?? '?') . ' · <time title="' . h(messages_date((int)$p['created_at']) . ' ' . date('H:i:s', (int)$p['created_at'])) . '">' . h(messages_time((int)$p['created_at'])) . '</time>' . $tags . '</div></div></div>';
    }
    $erase = action_form(url('/messages-admin/purge'), '<button class="btn btn-sm btn-ghost">' . messages_t('Erase conversation') . '</button>', ['thread' => $tid, 'action' => 'purge'], 'inline', messages_t('Erase this conversation for both members, permanently?'));
    $head = '<p><a href="' . h($base) . '">' . icon('arrow-left') . ' ' . messages_t('All conversations') . '</a></p>'
        . '<div class="messages-admin-head"><div><b>' . h($names[$a]) . '</b> ↔ <b>' . h($names[$b]) . '</b> <span class="muted">· ' . messages_t('%d messages', $total) . '</span></div><span>' . $erase . '</span></div>';
    $html = $head . '<div class="card messages-admin-log">' . ($log !== '' ? $log : '<div class="empty"><p>' . messages_t('No messages.') . '</p></div>') . '</div>'
        . pagination($pg, static fn(int $n): string => $base . '?thread=' . $tid . '&page=' . $n);
    admin_page($names[$a] . ' ↔ ' . $names[$b], messages_admin_css() . $html, 'ext.messages.list');
}

function messages_admin_css(): string
{
return '<style>.messages-admin-search{display:flex;gap:8px;margin:0 0 14px}.messages-admin-search input{max-width:280px}'
. '.messages-admin-row{display:flex;align-items:center;gap:12px;padding:10px 14px;border-top:1px solid var(--line-soft);color:inherit;text-decoration:none}.messages-admin-row:first-child{border-top:0}.messages-admin-row:hover{background:var(--panel-2);text-decoration:none}'
. '.messages-admin-who{flex:1;min-width:0;display:flex;flex-direction:column}.messages-admin-who small{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.messages-admin-row time{font-size:var(--font-size-xs);white-space:nowrap}'
. '.messages-admin-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}'
. '.messages-admin-log{padding:14px;display:flex;flex-direction:column;gap:6px;direction:ltr}.messages-admin-log .chat-day{text-align:center;color:var(--text-muted);font-size:var(--font-size-xs);margin:8px 0 2px}'
. '.messages-admin-msg{display:flex}.messages-admin-msg.is-right{justify-content:flex-end}'
. '.messages-admin-bubble{max-width:70%;padding:8px 12px;border-radius:16px;background:var(--panel-2);line-height:1.45;overflow-wrap:anywhere;text-align:start}.messages-admin-msg.is-right .messages-admin-bubble{background:var(--brand-soft)}'
. '.messages-admin-msg.is-recalled .messages-admin-bubble{background:transparent;border:1px dashed var(--line)}.messages-admin-bubble .post-content p{margin:0}'
. '.messages-admin-meta{margin-top:3px;color:var(--text-muted);font-size:var(--font-size-xs);display:flex;flex-wrap:wrap;gap:4px 8px;align-items:center}'
. '.messages-admin-tag{color:var(--danger);font-weight:600}.messages-admin-was{font-style:italic}</style>';
}
