<?php
/**
 * Messages — read receipts, editing and recalling a message, the way messengers do it:
 * one tick when a message is sent, two when the other member has read the chat; my own message can be edited for a while
 * (it then says "edited") and recalled for a shorter while (both sides then see "… recalled a message"). The text as first
 * sent is kept in the column original for Admin → Messages (admin.php); the members never see it. Loaded by plugin.php.
 */
if (!defined('FLATBB')) exit;

/** How long (minutes) a message can be edited ('edit') or recalled ('recall'); 0 turns it off. */
function messages_window(string $what): int
{
    return $what === 'edit' ? max(0, (int)plugin_setting('messages', 'edit_minutes', 15)) : max(0, (int)plugin_setting('messages', 'recall_minutes', 2));
}

/** Whether $uid may still edit or recall this message. */
function messages_can_change(array $p, int $uid, string $what): bool
{
    $minutes = messages_window($what);
    return (int)$p['user_id'] === $uid && (int)($p['recalled_at'] ?? 0) === 0 && $minutes > 0 && now() - (int)$p['created_at'] <= $minutes * 60;
}

/** When the other member last read the chat (0 when unknown). */
function messages_their_read(int $thread_id, int $partner_id): int
{
    return (int)(val('SELECT last_read FROM plugin_messages_members WHERE thread_id=? AND user_id=?', [$thread_id, $partner_id]) ?? 0);
}

/** The tick after my message: one (sent) or two (read). */
function messages_tick(bool $read): string
{
    $label = $read ? messages_t('Read') : messages_t('Sent');
    return '<span class="chat-tick' . ($read ? ' is-read' : '') . '" title="' . h($label) . '" aria-label="' . h($label) . '"><svg viewBox="0 0 18 12" width="16" height="11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1.5 6.5l3 3L11 3"/><path class="chat-tick-2" d="M7.5 8.5l1 1L16 3"/></svg></span>';
}

/** One bubble. $their_read: when the other member last read the chat; $partner: their name as shown (for "… recalled a message"). */
function messages_bubble(array $p, int $uid, int $their_read, string $partner): string
{
    $mine = (int)$p['user_id'] === $uid;
    $at = (int)$p['created_at'];
    if ((int)($p['recalled_at'] ?? 0) > 0) {
        return '<div class="chat-msg chat-recalled" data-id="' . (int)$p['id'] . '" data-at="' . $at . '"><div class="chat-note">' . h($mine ? messages_t('You recalled a message') : messages_t('%s recalled a message', $partner)) . '</div></div>';
    }
    $edit = $mine && messages_can_change($p, $uid, 'edit');
    $recall = $mine && messages_can_change($p, $uid, 'recall');
    $attrs = $edit ? ' data-raw="' . h((string)$p['body']) . '" data-edit-until="' . ($at + messages_window('edit') * 60) . '"' : '';
    $attrs .= $recall ? ' data-recall-until="' . ($at + messages_window('recall') * 60) . '"' : '';
    $tools = $edit || $recall ? '<span class="chat-tools">'
        . ($edit ? '<button type="button" class="chat-tool" data-chat-edit title="' . h(messages_t('Edit')) . '" aria-label="' . h(messages_t('Edit')) . '">' . icon('edit') . '</button>' : '')
        . ($recall ? '<button type="button" class="chat-tool" data-chat-recall title="' . h(messages_t('Recall')) . '" aria-label="' . h(messages_t('Recall')) . '">' . icon('trash') . '</button>' : '') . '</span>' : '';
    $meta = '<span class="chat-meta">' . ((int)($p['edited_at'] ?? 0) > 0 ? '<span class="chat-edited">' . messages_t('edited') . '</span>' : '')
        . '<time title="' . h(messages_date($at) . ' ' . messages_time($at)) . '">' . messages_time($at) . '</time>' . ($mine ? messages_tick($their_read >= $at) : '') . '</span>';
    // dir="auto": a Persian message lines up to the right, an English one to the left, whatever the page direction
    return '<div class="chat-msg' . ($mine ? ' me' : '') . '" data-id="' . (int)$p['id'] . '" data-at="' . $at . '"' . $attrs . '>' . $tools
        . '<div class="bubble" dir="auto"><span class="post-content">' . md((string)$p['body']) . '</span>' . $meta . '</div></div>';
}

/** My message $id in the chat with {name}: [thread, post, partner]; anything else (also a message I deleted the chat of) answers a JSON 404. */
function messages_own_post(string $name, array $me, int $id): array
{
    $to = messages_partner($name, $me);
    $t = messages_thread((int)$me['id'], (int)$to['id'], false);
    $p = $t !== null && $id > 0 ? one('SELECT * FROM plugin_messages_posts WHERE id=? AND thread_id=?', [$id, (int)$t['id']]) : null;
    if ($p === null || (int)$p['user_id'] !== (int)$me['id'] || (int)$p['id'] <= messages_floor((int)$t['id'], (int)$me['id'])) json_error(messages_t('Message not found.'), 404);
    return [$t, $p, $to];
}

/** The text as first sent, kept when a message is edited or recalled: only Admin → Messages shows it. */
function messages_original(array $p): string
{
    return (string)($p['original'] ?? '') !== '' ? (string)$p['original'] : (string)$p['body'];
}

/** Whether $post_id is the newest message of the thread (its text is the preview in the conversation list). */
function messages_is_last(array $t, int $post_id): bool
{
    return (int)val('SELECT MAX(id) FROM plugin_messages_posts WHERE thread_id=?', [(int)$t['id']]) === $post_id;
}

/** POST /messages/{name}/edit (AJAX: id, body): change my own message within the edit window; answers the new bubble. */
function messages_edit_route(string $name): never
{
    $me = need_login();
    require_post();
    [$t, $p, $to] = messages_own_post($name, $me, post_int('id'));
    if (!messages_can_change($p, (int)$me['id'], 'edit')) json_error(messages_t('This message can no longer be edited.'));
    $body = trim(post_str('body', 4000));
    if ($body === '') json_error(messages_t('Write a message.'));
    if ($body !== (string)$p['body']) {
        db_update('plugin_messages_posts', ['body' => $body, 'edited_at' => now(), 'updated_at' => now(), 'original' => messages_original($p)], 'id=?', [(int)$p['id']]);
        if (messages_is_last($t, (int)$p['id'])) db_update('plugin_messages_threads', ['last_text' => cut(md_excerpt($body, 80), 80)], 'id=?', [(int)$t['id']]);
        // the notification that announced this message quotes it: it follows the edit
        q("UPDATE fb_notifications SET content=? WHERE user_id=? AND from_user_id=? AND kind='message' AND content=?", [cut(md_excerpt($body, 120), 500, ''), (int)$to['id'], (int)$me['id'], cut(md_excerpt((string)$p['body'], 120), 500, '')]);
        $p = one('SELECT * FROM plugin_messages_posts WHERE id=?', [(int)$p['id']]) ?? $p;
    }
    json_ok(['id' => (int)$p['id'], 'html' => messages_bubble($p, (int)$me['id'], messages_their_read((int)$t['id'], (int)$to['id']), messages_name($to))]);
}

/** POST /messages/{name}/recall (AJAX: id): take back my own message within the recall window; its text is removed for both sides. */
function messages_recall_route(string $name): never
{
    $me = need_login();
    require_post();
    [$t, $p, $to] = messages_own_post($name, $me, post_int('id'));
    if (!messages_can_change($p, (int)$me['id'], 'recall')) json_error(messages_t('A message can be recalled only within %d minutes of sending it.', messages_window('recall')));
    db_update('plugin_messages_posts', ['body' => '', 'recalled_at' => now(), 'updated_at' => now(), 'original' => messages_original($p)], 'id=?', [(int)$p['id']]);
    if (messages_is_last($t, (int)$p['id'])) db_update('plugin_messages_threads', ['last_text' => ''], 'id=?', [(int)$t['id']]);
    q("UPDATE fb_notifications SET content='' WHERE user_id=? AND from_user_id=? AND kind='message' AND content=?", [(int)$to['id'], (int)$me['id'], cut(md_excerpt((string)$p['body'], 120), 500, '')]);
    $p = one('SELECT * FROM plugin_messages_posts WHERE id=?', [(int)$p['id']]) ?? $p;
    json_ok(['id' => (int)$p['id'], 'html' => messages_bubble($p, (int)$me['id'], 0, messages_name($to))]);
}
