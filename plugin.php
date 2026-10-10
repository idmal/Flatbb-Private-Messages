<?php
/**
 * Messages — private chat between two members, Telegram-style: a list of conversations, bubbles, one input at the bottom,
 * new messages appear without reloading, your own message shows at once while it is being sent.
 * Read receipts (one tick sent, two read), editing and recalling a message live in edit.php. English-only texts and
 * Gregorian dates are built in below. Members can opt out or block; sending can be limited for new members.
 * Data: one thread per pair of members. Administrators can read the conversations under Admin → Messages (admin.php), logged.
 *
 * Deleting is real: "Delete chat" clears the history for the member (the other side keeps theirs) and when both have
 * deleted it the messages are erased from the database; a conversation with a member who no longer exists is erased
 * for good (the next time anyone opens Messages, and from time to time while members send); an administrator can erase
 * a conversation; the admin copy of edited and recalled texts is erased after a number of days.
 */
if (!defined('FLATBB')) exit;

const MESSAGES_PAGE = 100; // messages a chat opens with, and each run that scrolling up brings

require __DIR__ . '/chat.php'; // pages: list, chat, send, poll, older, leave, block
require __DIR__ . '/edit.php'; // read receipts, edit, recall
require __DIR__ . '/admin.php'; // Admin → Messages

/* ------------------------------------------------- i18n (English only) */

/** Always false: this plugin is English-only, no Persian texts or Jalali dates. */
function messages_persian(): bool
{
    return false;
}

/** English text via the site's own t(). */
function messages_t(string $s, ...$args): string
{
    return t($s, ...$args);
}

/** Returns the string unchanged (no Persian digits). */
function messages_digits(string $s): string
{
    return $s;
}

/** Returns the Gregorian [year, month, day] instead of Jalali. */
function messages_jalali(int $ts): array
{
    return [(int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts)];
}

/** English date: "Oct 9, 2026", or "Oct 9" for the current year. */
function messages_date(int $ts, bool $year = true): string
{
    return date($year ? 'M j, Y' : 'M j', $ts);
}

function messages_time(int $ts): string
{
    return date('H:i', $ts);
}

/** The label of a date line between messages: Today, Yesterday, or the date. */
function messages_day_label(int $ts): string
{
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) return messages_t('Today');
    if ($day === date('Y-m-d', now() - 86400)) return messages_t('Yesterday');
    return messages_date($ts);
}

/** Short time for the conversation list (the hour today, otherwise the day); $full adds the hour to "Yesterday". */
function messages_when(int $ts, bool $full = false): string
{
    $day = date('Y-m-d', $ts);
    if ($day === date('Y-m-d')) return $full ? messages_t('Today') . ' ' . messages_time($ts) : messages_time($ts);
    if ($day === date('Y-m-d', now() - 86400)) return messages_t('Yesterday') . ($full ? ' ' . messages_time($ts) : '');
    return messages_date($ts);
}

/** The line under a member's name in the chat header. */
function messages_seen(int $ts): string
{
    if ($ts <= 0) return '';
    return now() - $ts < 300 ? messages_t('online') : messages_t('Seen %s', messages_when($ts, true));
}

/** Empty array: no Persian translations are used. */
function messages_fa(): array
{
    return [];
}

/* ------------------------------------------------- install / uninstall */

function messages_install(array $m): void
{
    db_create_table('plugin_messages_threads', ['id' => 'id', 'pair' => 'key', 'created_at' => 'uint', 'last_at' => 'uint', 'last_by' => 'uint', 'last_text' => 'string', 'msg_count' => 'uint']);
    db_create_index('plugin_messages_threads', 'ux_messages_pair', ['pair'], true);
    db_create_table('plugin_messages_members', ['id' => 'id', 'thread_id' => 'uint', 'user_id' => 'uint', 'last_read' => 'uint', 'hidden' => 'uint']);
    db_create_index('plugin_messages_members', 'ux_messages_member', ['thread_id', 'user_id'], true);
    db_create_index('plugin_messages_members', 'ix_messages_user', ['user_id', 'hidden']);
    db_create_table('plugin_messages_posts', ['id' => 'id', 'thread_id' => 'uint', 'user_id' => 'uint', 'body' => 'text', 'created_at' => 'uint']);
    db_create_index('plugin_messages_posts', 'ix_messages_thread', ['thread_id', 'id']);
    db_create_table('plugin_messages_blocks', ['id' => 'id', 'user_id' => 'uint', 'blocked_id' => 'uint']);
    db_create_index('plugin_messages_blocks', 'ux_messages_block', ['user_id', 'blocked_id'], true);
    db_ensure_columns('plugin_messages_posts', ['edited_at' => 'uint', 'recalled_at' => 'uint', 'updated_at' => 'uint']); // 1.2: edit, recall, and what poll sends again
    db_ensure_columns('plugin_messages_posts', ['original' => 'text']); // 1.4: the text as first sent of an edited or recalled message, for Admin → Messages
    db_ensure_columns('plugin_messages_members', ['cleared_to' => 'uint']); // 1.5: newest message id this member deleted ("Delete chat")
}

function messages_uninstall(array $m): void
{
    foreach (['plugin_messages_posts', 'plugin_messages_members', 'plugin_messages_threads', 'plugin_messages_blocks'] as $t) db_drop_table($t);
}

/** The name shown for a member: their display name on FlatBB 0.1.99+ (user_name()), else the username. */
function messages_name(?array $u): string
{
    if (function_exists('user_name')) return user_name($u);
    return $u !== null ? (string)$u['username'] : messages_t('deleted');
}

/* ---------------------------------------------------------------- data */

/** Unread conversations (one query per request, shown in the header). */
function messages_unread(int $uid): int
{
    if ($uid <= 0) return 0;
    return (int)(request_cache('messages_unread_' . $uid, static fn(): int => (int)val('SELECT COUNT(*) FROM plugin_messages_members m JOIN plugin_messages_threads t ON t.id=m.thread_id WHERE m.user_id=? AND m.hidden=0 AND t.last_at>m.last_read AND t.last_by<>?', [$uid, $uid])) ?? 0);
}

/** The conversation between two members, created on demand (safe when two requests create it at the same moment). */
function messages_thread(int $a, int $b, bool $create): ?array
{
    $pair = min($a, $b) . ':' . max($a, $b);
    $t = one('SELECT * FROM plugin_messages_threads WHERE pair=?', [$pair]);
    if (!$create) return $t;
    if ($t === null) {
        db_insert_ignore('plugin_messages_threads', ['pair' => $pair, 'created_at' => now(), 'last_at' => 0, 'last_by' => 0, 'last_text' => '', 'msg_count' => 0]);
        $t = one('SELECT * FROM plugin_messages_threads WHERE pair=?', [$pair]);
    }
    if ($t !== null) foreach ([$a, $b] as $u) db_insert_ignore('plugin_messages_members', ['thread_id' => (int)$t['id'], 'user_id' => $u, 'last_read' => 0, 'hidden' => 0, 'cleared_to' => 0]);
    return $t;
}

function messages_blocked(int $by, int $who): bool
{
    return val('SELECT 1 FROM plugin_messages_blocks WHERE user_id=? AND blocked_id=?', [$by, $who]) !== null;
}

/** Whether $to accepts messages from $from (preference, blocks; staff reaches everyone). */
function messages_accepts(array $to, array $from): bool
{
    if ((int)$to['status'] !== 1 || (int)$to['id'] === (int)$from['id']) return false;
    if (messages_blocked((int)$to['id'], (int)$from['id'])) return false;
    if (is_admin() || is_mod()) return true;
    $prefs = json_decode_array((string)($to['prefs'] ?? ''));
    return (int)($prefs['messages_allow'] ?? 1) === 1;
}

/** Why the current member cannot send right now, or ''. */
function messages_can_send(array $me): string
{
    if (is_admin() || is_mod()) return '';
    $min = (int)plugin_setting('messages', 'min_posts', 0);
    if ((int)$me['post_count'] + (int)$me['topic_count'] < $min) return messages_t('You need %d posts before you can send messages.', $min);
    $limit = (int)plugin_setting('messages', 'per_hour', 60);
    if ($limit > 0 && (int)val('SELECT COUNT(*) FROM plugin_messages_posts WHERE user_id=? AND created_at>?', [(int)$me['id'], now() - 3600]) >= $limit) return messages_t('You have sent many messages in the last hour. Please wait a little.');
    return '';
}

/** Store a message; the other side gets one notification per unread burst, not per message. */
function messages_send(array $thread, array $me, array $to, string $body): array
{
    $tid = (int)$thread['id'];
    $pid = db_insert('plugin_messages_posts', ['thread_id' => $tid, 'user_id' => (int)$me['id'], 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
    $their = one('SELECT last_read FROM plugin_messages_members WHERE thread_id=? AND user_id=?', [$tid, (int)$to['id']]);
    $quiet = $their === null || (int)$their['last_read'] >= (int)$thread['last_at']; // nothing unread yet: this message starts a burst
    // msg_count is incremented in SQL so two messages sent at the same moment are both counted
    q('UPDATE plugin_messages_threads SET last_at=?, last_by=?, last_text=?, msg_count=msg_count+1 WHERE id=?', [now(), (int)$me['id'], cut(md_excerpt($body, 80), 80), $tid]);
    db_update('plugin_messages_members', ['hidden' => 0], 'thread_id=?', [$tid]);
    db_update('plugin_messages_members', ['last_read' => now()], 'thread_id=? AND user_id=?', [$tid, (int)$me['id']]);
    if ($quiet) notify((int)$to['id'], (int)$me['id'], 'message', md_excerpt($body, 120));
    request_cache('messages_unread_' . (int)$me['id'], null, true);
    if (mt_rand(1, 100) === 1) messages_gc();
    return one('SELECT * FROM plugin_messages_posts WHERE id=?', [$pid]) ?? [];
}

/* ---------------------------------------------------------------- deleting */

/** The newest message id $uid has deleted in this thread: they never see messages up to it again. */
function messages_floor(int $thread_id, int $uid): int
{
    return (int)(val('SELECT cleared_to FROM plugin_messages_members WHERE thread_id=? AND user_id=?', [$thread_id, $uid]) ?? 0);
}

/** Erase a conversation from the database: messages, both members' rows, the thread, and the notifications that quote it. */
function messages_purge(int $thread_id): void
{
    $t = one('SELECT pair FROM plugin_messages_threads WHERE id=?', [$thread_id]);
    if ($t !== null) {
        [$a, $b] = array_map('intval', explode(':', (string)$t['pair']));
        q("DELETE FROM fb_notifications WHERE kind='message' AND ((user_id=? AND from_user_id=?) OR (user_id=? AND from_user_id=?))", [$a, $b, $b, $a]);
    }
    db_delete('plugin_messages_posts', 'thread_id=?', [$thread_id]);
    db_delete('plugin_messages_members', 'thread_id=?', [$thread_id]);
    db_delete('plugin_messages_threads', 'id=?', [$thread_id]);
}

/** "Delete chat": the history is gone for $uid (the other member keeps theirs); once both deleted it, it is erased for good. */
function messages_clear(array $thread, int $uid): void
{
    $tid = (int)$thread['id'];
    $max = (int)val('SELECT MAX(id) FROM plugin_messages_posts WHERE thread_id=?', [$tid]);
    db_update('plugin_messages_members', ['hidden' => 1, 'cleared_to' => $max], 'thread_id=? AND user_id=?', [$tid, $uid]);
    [$a, $b] = array_map('intval', explode(':', (string)$thread['pair']));
    // the notifications I got from them quote their messages: they go with the chat
    q("DELETE FROM fb_notifications WHERE kind='message' AND user_id=? AND from_user_id=?", [$uid, $a === $uid ? $b : $a]);
    $open = (int)val('SELECT COUNT(*) FROM plugin_messages_members WHERE thread_id=? AND COALESCE(cleared_to,0)<?', [$tid, $max]);
    if ($max > 0 && $open === 0) messages_purge($tid);
    request_cache('messages_unread_' . $uid, null, true);
}

/**
 * Erase everything of members who no longer exist: their conversations (the other side's copy too), their blocks.
 * $threads: rows with id and pair to check; by default the 100 whose last message is oldest. Returns how many conversations went.
 */
function messages_purge_orphans(?array $threads = null): int
{
    $threads ??= all('SELECT id, pair FROM plugin_messages_threads ORDER BY last_at ASC LIMIT 100');
    $ids = [];
    foreach ($threads as $t) foreach (explode(':', (string)$t['pair']) as $id) $ids[] = (int)$id;
    if ($ids === []) return 0;
    $users = users_by_ids(array_values(array_unique($ids)));
    $gone = [];
    $n = 0;
    foreach ($threads as $t) {
        foreach (explode(':', (string)$t['pair']) as $id) {
            if (!isset($users[(int)$id])) { $gone[(int)$id] = true; messages_purge((int)$t['id']); $n++; break; }
        }
    }
    foreach (array_keys($gone) as $id) db_delete('plugin_messages_blocks', 'user_id=? OR blocked_id=?', [$id, $id]);
    return $n;
}

/** Housekeeping, run now and then while someone sends: orphans, and the admin copy of edited/recalled texts does not live forever. */
function messages_gc(): void
{
    messages_purge_orphans();
    $days = (int)plugin_setting('messages', 'original_days', 30);
    if ($days > 0) q("UPDATE plugin_messages_posts SET original='' WHERE original<>'' AND updated_at>0 AND updated_at<?", [now() - $days * 86400]);
}

/* ---------------------------------------------------------------- hooks */

/** header.user_menu: Messages with the unread count, the only way in from the header (a new message also rings the notification bell). */
function messages_user_menu(array $items, array $ctx): array
{
    $n = messages_unread(uid());
    $items['messages'] = ['label' => messages_t('Messages'), 'count' => $n > 0 ? $n : '', 'url' => url('/messages'), 'icon' => 'message', 'weight' => 5];
    return $items;
}

function messages_profile_button(string $html, array $ctx): string
{
    $u = (array)($ctx['user'] ?? []);
    if (uid() <= 0 || !empty($ctx['self']) || (int)($u['id'] ?? 0) <= 0) return $html;
    return $html . '<a class="btn btn-sm btn-primary" href="' . h(url('/messages/' . rawurlencode((string)$u['username']))) . '" title="' . h(messages_t('Message')) . '">' . icon('message') . '<span>' . messages_t('Message') . '</span></a>';
}

function messages_notification_kinds(array $kinds, array $ctx): array
{
    $kinds['message'] = ['message', messages_t('sent you a message')];
    return $kinds;
}

/** notifications.rows: a message notification links to the chat with its sender. */
function messages_notification_rows(array $rows, array $ctx): array
{
    foreach ($rows as &$n) if (($n['kind'] ?? '') === 'message' && !empty($n['from'])) $n['url'] = url('/messages/' . rawurlencode((string)$n['from']['username']));
    unset($n);
    return $rows;
}

function messages_prefs_tab(string $html, array $ctx): string
{
    if (($ctx['tab'] ?? '') !== 'preferences') return $html;
    $prefs = json_decode_array((string)($ctx['user']['prefs'] ?? ''));
    return $html . '<div class="form-row">' . checkbox('messages_allow', (int)($prefs['messages_allow'] ?? 1) === 1, messages_t('Members may send me private messages')) . '</div>';
}

function messages_prefs_save(array $prefs, array $ctx): array
{
    $prefs['messages_allow'] = post_int('messages_allow') ? 1 : 0;
    return $prefs;
}

/* ---------------------------------------------------------------- manifest */

return [
    'id' => 'messages',
    'name' => 'Private Messages',
    'version' => '1.6.0',
    'description' => 'Private chat between members, Telegram style: live messages, instant sending, read receipts, edit and recall, real delete, English-only texts and Gregorian dates, full screen on phones, blocking. Administrators can read conversations (logged).',
    'author' => 'flatbb',
    'url' => 'https://www.flatbb.com',
    'requires' => ['flatbb' => '0.1.94'],
    'install' => 'messages_install',
    'uninstall' => 'messages_uninstall',
    'routes' => [
        '/messages' => 'messages_page',
        '/messages/block' => 'messages_block',
        '/messages/{name}' => 'messages_page',
        '/messages/{name}/send' => 'messages_send_route',
        '/messages/{name}/poll' => 'messages_poll',
        '/messages/{name}/older' => 'messages_older',
        '/messages/{name}/leave' => 'messages_leave',
        '/messages/{name}/edit' => 'messages_edit_route',
        '/messages/{name}/recall' => 'messages_recall_route',
        '/messages-admin/purge' => 'messages_admin_purge',
    ],
    'hooks' => [
        'region.header.user_menu' => 'messages_user_menu',
        'region.user.profile.actions' => 'messages_profile_button',
        'notification.kinds' => 'messages_notification_kinds',
        'notifications.rows' => 'messages_notification_rows',
        'user.settings_tab' => 'messages_prefs_tab',
        'user.prefs_save' => 'messages_prefs_save',
    ],
    'admin_pages' => ['list' => ['label' => 'Messages', 'callback' => 'messages_admin']],
    'assets' => ['css' => ['messages_css'], 'js' => ['messages_js']],
    'settings' => [
        'min_posts' => ['type' => 'number', 'label' => 'Posts a member needs before sending messages', 'default' => 0, 'min' => 0, 'max' => 1000, 'help' => 'Administrators and moderators can always send.'],
        'per_hour' => ['type' => 'number', 'label' => 'Messages a member may send per hour (0 = no limit)', 'default' => 60, 'min' => 0, 'max' => 5000],
        'edit_minutes' => ['type' => 'number', 'label' => 'A message can be edited for (minutes, 0 = never)', 'default' => 15, 'min' => 0, 'max' => 10080],
        'recall_minutes' => ['type' => 'number', 'label' => 'A message can be recalled for (minutes, 0 = never)', 'default' => 2, 'min' => 0, 'max' => 10080, 'help' => 'Recalling removes the text for both members; they see that a message was recalled.'],
        'original_days' => ['type' => 'number', 'label' => 'Keep the original text of edited or recalled messages for admins (days, 0 = forever)', 'default' => 30, 'min' => 0, 'max' => 3650, 'help' => 'After this time the original text is erased from the database.'],
    ],
];