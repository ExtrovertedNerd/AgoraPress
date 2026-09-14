<?php

/**
 * Topic email notification storage: site options, user master, subscriptions.
 *
 * Three gates (all default off) control reply mail. This class owns the
 * **site** options, the per-user master usermeta, and `{prefix}topic_subscriptions`
 * rows (Subscribe / Unsubscribe). Unread tracking (`topic_track` / `forum_track`)
 * is a different table and is not reused here.
 *
 * | Key                            | Default | Meaning                                      |
 * |--------------------------------|---------|----------------------------------------------|
 * | `forum_topic_notify_enabled`   | `'0'`   | Site master (Settings → Forums): allow topic email notifications |
 * | `forum_notify_max_per_minute`  | `4`     | Own send cap (does not consume rate_limit_mail) |
 * | usermeta `forum_notify_email`  | `'0'`   | User master: email me about subscribed topics |
 *
 * Missing options and missing usermeta are treated as these defaults so
 * upgrades never start sending until an administrator turns the site
 * switch on **and** the member opts in.
 *
 * Subscription rows drop when the member unsubscribes, the user is deleted,
 * or the topic is hard-deleted. Soft-delete leaves watches in place.
 *
 * First Subscribe while the user master is off: **flip the master on**
 * (do not refuse). Chrome is shown with the master off on purpose, so a
 * refuse-and-point-at-profile would make the visible Subscribe button a
 * trap. The topic Subscribe POST and compose **Notify me of replies**
 * call {@see enableUserNotifyOnSubscribe()} after a successful watch.
 * Topic Subscribe shows `topic_subscribed_email_on`. Compose keeps the
 * create/reply outcome and uses `topic_created_email_on` /
 * `reply_posted_email_on` when this call flipped the master. Storage
 * {@see subscribe()} does not flip. Unsubscribe never turns the master off.
 *
 * An approved reply POST enqueues `topic_id` + `reply_post_id` on
 * {@see AP_Cron} and does not send mail in that request. Pending replies
 * enqueue when they are later approved. `createReply()` itself does not
 * enqueue (imports stay silent).
 *
 * The cron worker ({@see processQueuedReply()}) drops the poster, users
 * with the master off, members who lost `view_forum`, and bad addresses,
 * then {@see AP_Mail::send()} one `text/plain` message at a time with a
 * signed unsubscribe link. That send skips `rate_limit_mail`. Notify
 * uses its own per-minute bucket ({@see OPTION_MAX_PER_MINUTE}, default
 * 4, 60-second window). Several unsent replies on the same `(user, topic)`
 * collapse into one digest; if that grouping slips, per-reply mail still
 * goes through the same cap. A failed send does not count as success
 * (no digest claim, notify cap slot refunded) and does not delete the
 * subscription.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Site options, per-user master, per-topic subscription rows, reply enqueue,
 * and the cron mail worker.
 */
class AP_Forum_Notify
{
    /**
     * Site master switch. Stored as '1' / '0'. Missing or '0' = off.
     */
    public const OPTION_ENABLED = 'forum_topic_notify_enabled';

    /**
     * Own per-minute send cap. Stored as a decimal string. Default 4.
     * Does not share the verification / reset / test `rate_limit_mail` bucket.
     */
    public const OPTION_MAX_PER_MINUTE = 'forum_notify_max_per_minute';

    /**
     * Per-user master. Stored as '1' / '0'. Missing or '0' = off.
     * New users are seeded to `'0'`; upgrades with no row are treated as off.
     */
    public const META_NOTIFY_EMAIL = 'forum_notify_email';

    /** Default for {@see OPTION_ENABLED}: no chrome, enqueue, or send. */
    public const DEFAULT_ENABLED = false;

    /** Default mails per minute when the option is missing or invalid. */
    public const DEFAULT_MAX_PER_MINUTE = 4;

    /** Default for {@see META_NOTIFY_EMAIL}: no mail until the member opts in. */
    public const DEFAULT_USER_ENABLED = false;

    /** Minimum allowed per-minute cap (0 / negative fall back to default). */
    public const MIN_PER_MINUTE = 1;

    /** Soft ceiling so a crafted value cannot enqueue unbounded SMTP. */
    public const MAX_PER_MINUTE = 60;

    /**
     * Site-wide notify send window in seconds for {@see OPTION_MAX_PER_MINUTE}.
     * Tumbling window; not the verification / reset / test hour-long mail lockout.
     */
    public const RATE_WINDOW_SECONDS = 60;

    /**
     * Transient key for the notify send window. Distinct from `rate_limit_mail`
     * (`ap_rl_*` / {@see AP_Rate_Limit::ACTION_MAIL}).
     */
    public const RATE_BUCKET_TRANSIENT = 'ap_fn_rpm';

    /**
     * Cron hook for a queued reply notify (`topic_id`, `reply_post_id`).
     * {@see processQueuedReply()} is the worker. Enqueue still no-ops when
     * the site master is off. The reply POST schedules only — it does not
     * spawn cron or send mail.
     */
    public const CRON_HOOK = 'ap_forum_topic_notify';

    /**
     * Query argument on one-click unsubscribe URLs (`user_id` + `topic_id`).
     * HMAC token; no session required.
     */
    public const QUERY_UNSUBSCRIBE = 'ap_forum_unsub';

    /**
     * Signed unsubscribe TTL in seconds (45 days; SPEC requires 30+).
     */
    public const UNSUBSCRIBE_TTL = 3888000;

    /**
     * Transient of reply ids already bundled into a digest (siblings only).
     * Distinct from `rate_limit_mail` and from {@see RATE_BUCKET_TRANSIENT}.
     */
    public const DIGEST_CLAIM_TRANSIENT = 'ap_fn_dg';

    /** How long a digest claim suppresses a sibling cron fire (seconds). */
    public const DIGEST_CLAIM_TTL = 3600;

    /** Word cap for the spoiler-stripped reply excerpt in notify mail. */
    public const EXCERPT_WORDS = 40;

    /**
     * Compose POST field for "Notify me of replies". Absent / empty = off.
     * Start or reply never auto-watches; only this checkbox (or Subscribe) does.
     */
    public const POST_NOTIFY_REPLIES = 'notify_replies';

    /**
     * Stable callables for {@see registerHooks()} (same instances for has_action).
     *
     * @var array<string, callable>|null
     */
    private static ?array $hookCallbacks = null;

    /**
     * In-request copy of the notify send window (also used when transients
     * are not loaded). Null means load from storage.
     *
     * @var array{window_start: int, count: int}|null
     */
    private static ?array $rateBucket = null;

    /**
     * Reply ids already included in a digest this window (siblings only).
     * Null means load from {@see DIGEST_CLAIM_TRANSIENT}.
     *
     * @var array<int, true>|null
     */
    private static ?array $digestClaims = null;

    /** Frozen unix time for tests; null uses {@see time()}. */
    private static ?int $nowForTests = null;

    /**
     * Whether the site allows topic email notifications.
     *
     * Default is **off** when the option is missing.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = strtolower(trim(self::optionValue(
            self::OPTION_ENABLED,
            self::sanitizeEnabled(self::DEFAULT_ENABLED),
            $db
        )));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Whether Subscribe / notify chrome may render (site master on).
     *
     * Site master off → no chrome. Per-viewer chrome also requires a logged-in
     * member with `view_forum` — see {@see viewerMaySubscribe()}.
     */
    public static function shouldShowChrome(?AP_DB $db = null): bool
    {
        return self::isEnabled($db);
    }

    /**
     * Whether this viewer may Subscribe / Unsubscribe on a topic in $forumId.
     *
     * All of: site master on, logged in (user id ≥ 1), and `view_forum` on
     * that board. Guests never qualify. Does not require the user master
     * (`forum_notify_email`) — that gate is for mail, not chrome.
     */
    public static function viewerMaySubscribe(int $userId, int $forumId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $forumId < 1) {
            return false;
        }
        if (!self::isEnabled($db)) {
            return false;
        }
        if (class_exists('AP_Forum_Permissions', false)) {
            return AP_Forum_Permissions::userCanViewForum($userId, $forumId, $db);
        }

        return true;
    }

    /**
     * Queue notify work for an approved reply. Site master off → no enqueue
     * (does not schedule {@see AP_Cron}).
     *
     * Does not send mail in this request and does not spawn cron (no N SMTP
     * in the reply POST). A duplicate `(topic, reply)` pair that is already
     * scheduled is treated as success. Queue args are only `topic_id` and
     * `reply_post_id`.
     */
    public static function enqueueReply(int $topicId, int $replyPostId, ?AP_DB $db = null): bool
    {
        if ($topicId < 1 || $replyPostId < 1) {
            return false;
        }
        if (!self::isEnabled($db)) {
            return false;
        }

        $args = [$topicId, $replyPostId];
        if (class_exists('AP_Cron', false)) {
            if (AP_Cron::nextScheduled(self::CRON_HOOK, $args, $db) !== false) {
                return true;
            }

            // Schedule only. Never spawn / runDue here — that would send N mails
            // in the reply POST. Bootstrap already spawned at request start.
            return AP_Cron::scheduleSingle(time(), self::CRON_HOOK, $args, $db);
        }
        if (function_exists('ap_schedule_single_event')) {
            return ap_schedule_single_event(time(), self::CRON_HOOK, $args, $db);
        }

        return false;
    }

    /**
     * Enqueue when $post is an approved reply (not the topic starter).
     *
     * Unapproved rows, missing ids, and first posts are no-ops. Does not
     * send mail. Used from the reply POST and from {@see registerHooks()}
     * when a pending reply is later approved.
     */
    public static function maybeEnqueueApprovedReply(?object $post, ?AP_DB $db = null): bool
    {
        if ($post === null) {
            return false;
        }
        $postId = (int) ($post->post_id ?? 0);
        $topicId = (int) ($post->topic_id ?? 0);
        if ($postId < 1 || $topicId < 1) {
            return false;
        }
        if ((int) ($post->post_approved ?? 0) !== 1) {
            return false;
        }
        if (class_exists('AP_Forum', false)) {
            $topic = AP_Forum::getTopic($topicId, $db);
            if ($topic !== null && (int) ($topic->first_post_id ?? 0) === $postId) {
                return false;
            }
        }

        return self::enqueueReply($topicId, $postId, $db);
    }

    /**
     * Listen for pending replies that become approved.
     *
     * Idempotent. Safe after {@see ap_reset_hooks()} (tests). Does not hook
     * `ap_forum_post_inserted` so importers / `createReply()` do not enqueue.
     */
    public static function registerHooks(): void
    {
        if (!function_exists('ap_add_action')) {
            return;
        }
        if (self::$hookCallbacks === null) {
            self::$hookCallbacks = [
                'approved' => static function (int $postId, mixed $post = null): void {
                    $row = is_object($post) ? $post : null;
                    $db = (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB)
                        ? $GLOBALS['apdb']
                        : null;
                    if ($row === null && $postId > 0 && class_exists('AP_Forum', false)) {
                        $row = AP_Forum::getPost($postId, $db);
                    }
                    try {
                        self::maybeEnqueueApprovedReply($row, $db);
                    } catch (Throwable) {
                        // Enqueue must never break moderation approval.
                    }
                },
                'cron' => static function (mixed $topicId = 0, mixed $replyPostId = 0): void {
                    $db = (isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB)
                        ? $GLOBALS['apdb']
                        : null;
                    try {
                        self::processQueuedReply((int) $topicId, (int) $replyPostId, $db);
                    } catch (Throwable) {
                        // Worker must never break cron.
                    }
                },
            ];
        }
        if (
            !function_exists('ap_has_action')
            || !ap_has_action('ap_forum_post_approved', self::$hookCallbacks['approved'])
        ) {
            ap_add_action('ap_forum_post_approved', self::$hookCallbacks['approved'], 10, 2);
        }
        if (
            !function_exists('ap_has_action')
            || !ap_has_action(self::CRON_HOOK, self::$hookCallbacks['cron'])
        ) {
            ap_add_action(self::CRON_HOOK, self::$hookCallbacks['cron'], 10, 2);
        }
    }

    /**
     * Cron worker: filter subscribers and send one text/plain mail each.
     *
     * Drops the poster, user-master off, lost `view_forum`, and unusable
     * addresses. Honors {@see OPTION_MAX_PER_MINUTE} via the notify bucket
     * and does not consume `rate_limit_mail`. Stops when the per-minute
     * cap is exhausted (remaining subscribers keep their watch). Failed
     * {@see AP_Mail::send()} does not increment the return value, does not
     * claim digest siblings, does not keep the notify cap slot, and does
     * not delete the subscription. Site master off, missing/unapproved
     * replies, and the topic starter are no-ops.
     *
     * Several still-queued replies for the same `(user, topic)` become one
     * digest. If grouping cannot run (one valid reply, compose failure),
     * per-reply mail for this event still goes through {@see send()} and
     * the same cap.
     *
     * @return int Number of successful sends.
     */
    public static function processQueuedReply(int $topicId, int $replyPostId, ?AP_DB $db = null): int
    {
        if ($topicId < 1 || $replyPostId < 1) {
            return 0;
        }
        if (self::wasClaimedInDigest($replyPostId, $db)) {
            return 0;
        }
        if (!self::isEnabled($db)) {
            return 0;
        }
        if (!class_exists('AP_Forum', false)) {
            return 0;
        }

        $topic = AP_Forum::getTopic($topicId, $db);
        $post = AP_Forum::getPost($replyPostId, $db);
        if ($topic === null || $post === null) {
            return 0;
        }
        if ((int) ($post->topic_id ?? 0) !== $topicId) {
            return 0;
        }
        if ((int) ($post->post_approved ?? 0) !== 1) {
            return 0;
        }
        if ((int) ($topic->first_post_id ?? 0) === $replyPostId) {
            return 0;
        }

        $forumId = (int) ($topic->forum_id ?? 0);
        $posts = self::unsentRepliesForTopic($topicId, $replyPostId, $db);
        if ($posts === []) {
            return 0;
        }

        $sent = 0;
        $sentDigest = false;
        foreach (self::listForTopic($topicId, $db) as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $eligible = self::eligibleRepliesForUser($userId, $forumId, $posts, $db);
            if ($eligible === []) {
                continue;
            }
            $email = self::usableRecipientEmail($userId, $db);
            if ($email === '') {
                continue;
            }

            $composed = null;
            $thisIsDigest = false;
            if (count($eligible) > 1) {
                $composed = self::composeDigestMail($topic, $eligible, $userId, $db);
                $thisIsDigest = $composed !== null;
            }
            if ($composed === null) {
                $one = self::singleReplyForFallback($eligible, $replyPostId);
                if ($one === null) {
                    continue;
                }
                $composed = self::composeReplyMail($topic, $one, $userId, $db);
            }
            if ($composed === null) {
                continue;
            }
            if (self::remainingSends($db) < 1) {
                break;
            }
            $ok = self::send(
                $email,
                $composed['subject'],
                $composed['message'],
                ['Content-Type' => 'text/plain; charset=UTF-8'],
                $db
            );
            if ($ok) {
                $sent++;
                if ($thisIsDigest) {
                    $sentDigest = true;
                }
            }
        }

        if ($sentDigest) {
            self::claimDigestSiblings($posts, $replyPostId, $topicId, $db);
        }

        return $sent;
    }

    /**
     * Whether this subscriber should receive mail for a reply in $forumId.
     *
     * False for guests, the poster, user-master off, lost `view_forum`,
     * and unusable addresses. Does not inspect the site master.
     */
    public static function isEligibleRecipient(
        int $userId,
        int $forumId,
        int $posterId,
        ?AP_DB $db = null
    ): bool {
        if ($userId < 1 || $forumId < 1) {
            return false;
        }
        if ($posterId > 0 && $userId === $posterId) {
            return false;
        }
        if (!self::isUserNotifyEnabled($userId, $db)) {
            return false;
        }
        if (
            class_exists('AP_Forum_Permissions', false)
            && !AP_Forum_Permissions::userCanViewForum($userId, $forumId, $db)
        ) {
            return false;
        }

        return self::usableRecipientEmail($userId, $db) !== '';
    }

    /**
     * Usable RFC address for $userId, or empty when missing / invalid.
     *
     * Deleted users, blank `user_email`, and non-addresses are dropped.
     */
    public static function usableRecipientEmail(int $userId, ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return '';
        }

        $email = '';
        if (class_exists('AP_User', false)) {
            $user = AP_User::getById($userId, $db);
            if ($user === null) {
                return '';
            }
            $email = trim($user->user_email);
        } else {
            try {
                $db = self::resolveDb($db);
                $raw = $db->getVar(
                    'SELECT user_email FROM ' . $db->quoteIdentifier($db->table('users'))
                    . ' WHERE ID = ? LIMIT 1',
                    [$userId]
                );
                $email = is_string($raw) ? trim($raw) : '';
            } catch (Throwable) {
                return '';
            }
        }
        if ($email === '') {
            return '';
        }
        if (class_exists('AP_User', false) && !AP_User::isValidEmail($email)) {
            return '';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return '';
        }

        return $email;
    }

    /**
     * Outbound notify choke point. Site master off → no send (does not call
     * {@see AP_Mail::send()} and does not consume `rate_limit_mail`).
     *
     * When the site master is on, reserves one slot from the notify per-minute
     * bucket then sends `text/plain` via {@see AP_Mail::send()} with
     * `skip_rate_limit` so verification / reset / test quota is untouched.
     * Cap exhausted → false, no SMTP. Transport failure refunds the slot
     * and returns false (does not claim the mail went out).
     *
     * @param string|list<string>   $to
     * @param array<string, string> $headers
     */
    public static function send(
        string|array $to,
        string $subject,
        string $message,
        array $headers = [],
        ?AP_DB $db = null
    ): bool {
        if (!self::isEnabled($db)) {
            return false;
        }

        $recipients = is_array($to) ? $to : [$to];
        $hasRecipient = false;
        foreach ($recipients as $addr) {
            if (is_string($addr) && trim($addr) !== '') {
                $hasRecipient = true;
                break;
            }
        }
        if (!$hasRecipient || trim($subject) === '' || $message === '') {
            return false;
        }

        if (!class_exists('AP_Mail', false)) {
            return false;
        }

        if (!self::consumeNotifyQuota($db)) {
            return false;
        }

        $headers['Content-Type'] = 'text/plain; charset=UTF-8';

        $ok = AP_Mail::send($to, $subject, $message, $headers, [
            'skip_rate_limit' => true,
        ]);
        if (!$ok) {
            self::refundNotifyQuota($db);
        }

        return $ok;
    }

    /**
     * Subject + text/plain body for one reply, including a signed unsubscribe URL.
     *
     * @return array{subject: string, message: string}|null
     */
    public static function composeReplyMail(
        object $topic,
        object $post,
        int $userId,
        ?AP_DB $db = null
    ): ?array {
        $topicId = (int) ($topic->topic_id ?? 0);
        if ($topicId < 1 || $userId < 1) {
            return null;
        }

        $title = trim((string) ($topic->topic_title ?? ''));
        if ($title === '') {
            $title = 'Topic #' . $topicId;
        }
        $unsubUrl = self::unsubscribeUrl($userId, $topicId, $db);
        if ($unsubUrl === '') {
            return null;
        }

        $site = self::siteName($db);
        $author = self::replyAuthorLabel($post, $db);
        $excerpt = self::replyExcerpt($post);
        $topicUrl = '';
        if (class_exists('AP_Forum', false)) {
            $topicUrl = self::absolutizeUrl(AP_Forum::topicUrl($topic), $db);
        }

        $lines = [
            $title,
            '',
            $author . ' posted a reply.',
        ];
        if ($excerpt !== '') {
            $lines[] = '';
            $lines[] = $excerpt;
        }
        if ($topicUrl !== '') {
            $lines[] = '';
            $lines[] = $topicUrl;
        }
        $lines[] = '';
        $lines[] = 'Unsubscribe from this topic (no sign-in required):';
        $lines[] = $unsubUrl;

        return [
            'subject' => '[' . $site . '] New reply in ' . $title,
            'message' => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * Subject + text/plain body for several unsent replies on one topic.
     *
     * Fewer than two usable posts → null (caller may fall back to
     * {@see composeReplyMail()}). Same unsubscribe token as a single reply.
     *
     * @param list<object> $posts Approved replies, oldest first.
     *
     * @return array{subject: string, message: string}|null
     */
    public static function composeDigestMail(
        object $topic,
        array $posts,
        int $userId,
        ?AP_DB $db = null
    ): ?array {
        $topicId = (int) ($topic->topic_id ?? 0);
        if ($topicId < 1 || $userId < 1) {
            return null;
        }

        $clean = [];
        foreach ($posts as $post) {
            if (is_object($post) && (int) ($post->post_id ?? 0) > 0) {
                $clean[] = $post;
            }
        }
        if (count($clean) < 2) {
            return null;
        }

        $title = trim((string) ($topic->topic_title ?? ''));
        if ($title === '') {
            $title = 'Topic #' . $topicId;
        }
        $unsubUrl = self::unsubscribeUrl($userId, $topicId, $db);
        if ($unsubUrl === '') {
            return null;
        }

        $site = self::siteName($db);
        $topicUrl = '';
        if (class_exists('AP_Forum', false)) {
            $topicUrl = self::absolutizeUrl(AP_Forum::topicUrl($topic), $db);
        }

        $n = count($clean);
        $lines = [
            $title,
            '',
            $n . ' new replies.',
        ];
        foreach ($clean as $post) {
            $author = self::replyAuthorLabel($post, $db);
            $excerpt = self::replyExcerpt($post);
            $lines[] = '';
            $lines[] = $author . ' posted a reply.';
            if ($excerpt !== '') {
                $lines[] = $excerpt;
            }
        }
        if ($topicUrl !== '') {
            $lines[] = '';
            $lines[] = $topicUrl;
        }
        $lines[] = '';
        $lines[] = 'Unsubscribe from this topic (no sign-in required):';
        $lines[] = $unsubUrl;

        return [
            'subject' => '[' . $site . '] ' . $n . ' new replies in ' . $title,
            'message' => implode("\n", $lines) . "\n",
        ];
    }

    /**
     * Approved unsent notify replies for a topic: this event plus queued siblings.
     *
     * Oldest first. Drops the topic starter, unapproved rows, other topics,
     * and reply ids already bundled into a digest. Missing cron → only
     * $currentReplyId (when it is a valid approved reply).
     *
     * @return list<object>
     */
    public static function unsentRepliesForTopic(
        int $topicId,
        int $currentReplyId,
        ?AP_DB $db = null
    ): array {
        if ($topicId < 1 || !class_exists('AP_Forum', false)) {
            return [];
        }

        $ids = [];
        if ($currentReplyId > 0) {
            $ids[] = $currentReplyId;
        }
        foreach (self::queuedReplyIdsFromCron($topicId, $db) as $queuedId) {
            $ids[] = $queuedId;
        }
        $ids = array_values(array_unique(array_filter(
            $ids,
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        $map = AP_Forum::getPostsByIds($ids, $db);
        $topic = AP_Forum::getTopic($topicId, $db);
        $firstId = $topic !== null ? (int) ($topic->first_post_id ?? 0) : 0;

        $posts = [];
        foreach ($ids as $id) {
            if ($id !== $currentReplyId && self::wasClaimedInDigest($id, $db)) {
                continue;
            }
            $post = $map[$id] ?? null;
            if (!is_object($post)) {
                continue;
            }
            if ((int) ($post->topic_id ?? 0) !== $topicId) {
                continue;
            }
            if ((int) ($post->post_approved ?? 0) !== 1) {
                continue;
            }
            if ($firstId > 0 && $id === $firstId) {
                continue;
            }
            $posts[] = $post;
        }

        usort(
            $posts,
            static function (object $a, object $b): int {
                $ta = (string) ($a->post_time ?? '');
                $tb = (string) ($b->post_time ?? '');
                $cmp = strcmp($ta, $tb);
                if ($cmp !== 0) {
                    return $cmp;
                }

                return (int) ($a->post_id ?? 0) <=> (int) ($b->post_id ?? 0);
            }
        );

        return $posts;
    }

    /**
     * Absolute one-click unsubscribe URL (`user_id` + `topic_id`, HMAC, TTL).
     *
     * Does not require a session. Token unsubscribes this watch only.
     */
    public static function unsubscribeUrl(int $userId, int $topicId, ?AP_DB $db = null): string
    {
        $token = self::createUnsubscribeToken($userId, $topicId);
        if ($token === '') {
            return '';
        }
        $home = self::absoluteHomeUrl($db);
        $sep = str_contains($home, '?') ? '&' : '?';

        return $home . $sep . self::QUERY_UNSUBSCRIBE . '=' . rawurlencode($token);
    }

    /**
     * HMAC token for one-click unsubscribe. Pass $now to freeze expiry in tests.
     */
    public static function createUnsubscribeToken(int $userId, int $topicId, ?int $now = null): string
    {
        if ($userId < 1 || $topicId < 1) {
            return '';
        }
        $now = $now ?? time();
        $exp = $now + self::UNSUBSCRIBE_TTL;
        $hmac = self::unsubscribeHmac($userId, $topicId, $exp);
        $payload = $userId . ':' . $topicId . ':' . $exp . ':' . $hmac;

        return self::toBase64Url($payload);
    }

    /**
     * Parse and verify a signed unsubscribe token. Expired / tampered → null.
     *
     * @return array{user_id: int, topic_id: int, expires: int}|null
     */
    public static function parseUnsubscribeToken(string $token, ?int $now = null): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $payload = self::fromBase64Url($token);
        if ($payload === null || $payload === '') {
            return null;
        }
        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4) {
            return null;
        }
        [$userRaw, $topicRaw, $expRaw, $hmac] = $parts;
        if (
            !ctype_digit($userRaw)
            || !ctype_digit($topicRaw)
            || !ctype_digit($expRaw)
            || $hmac === ''
        ) {
            return null;
        }
        $userId = (int) $userRaw;
        $topicId = (int) $topicRaw;
        $exp = (int) $expRaw;
        if ($userId < 1 || $topicId < 1 || $exp < 1) {
            return null;
        }
        $now = $now ?? time();
        if ($exp < $now) {
            return null;
        }
        $expected = self::unsubscribeHmac($userId, $topicId, $exp);
        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'topic_id' => $topicId,
            'expires' => $exp,
        ];
    }

    /**
     * Honor `ap_forum_unsub` when present. No session required.
     *
     * Missing query arg → null (caller continues). Present (valid or not) →
     * redirect URL. Valid tokens drop that `(user, topic)` watch only and
     * never turn the user master off.
     *
     * @param array<string, mixed>|null $get
     */
    public static function maybeHandleSignedUnsubscribe(?array $get = null, ?AP_DB $db = null): ?string
    {
        $get ??= $_GET;
        $raw = $get[self::QUERY_UNSUBSCRIBE] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        $parsed = self::parseUnsubscribeToken($raw);
        if ($parsed === null) {
            return self::signedUnsubscribeRedirect(null, 'topic_unsubscribe_invalid', $db);
        }
        self::unsubscribe($parsed['user_id'], $parsed['topic_id'], $db);
        $topic = null;
        if (class_exists('AP_Forum', false)) {
            $topic = AP_Forum::getTopic($parsed['topic_id'], $db);
        }

        return self::signedUnsubscribeRedirect(
            is_object($topic) ? $topic : null,
            'topic_unsubscribed',
            $db
        );
    }

    /**
     * Per-minute send cap (clamped). Default 4.
     */
    public static function getMaxPerMinute(?AP_DB $db = null): int
    {
        $raw = self::optionValue(
            self::OPTION_MAX_PER_MINUTE,
            (string) self::DEFAULT_MAX_PER_MINUTE,
            $db
        );

        return self::sanitizeMaxPerMinute($raw);
    }

    /**
     * Notify sends still allowed in the current minute (own bucket).
     *
     * Does not inspect `rate_limit_mail`. Site master off does not zero this
     * figure; {@see send()} still refuses until the site switch is on.
     */
    public static function remainingSends(?AP_DB $db = null): int
    {
        $max = self::getMaxPerMinute($db);
        $state = self::readRateBucket($db);

        return max(0, $max - $state['count']);
    }

    /**
     * Clear the notify send window (tests). Does not touch `rate_limit_mail`.
     *
     * Also drops in-request digest claims so a later worker case can
     * re-bundle the same reply ids.
     */
    public static function resetRateBucketForTests(?AP_DB $db = null): void
    {
        self::$rateBucket = null;
        self::$digestClaims = null;
        self::$nowForTests = null;
        if (!class_exists('AP_Transient', false)) {
            return;
        }
        if ($db === null && isset($GLOBALS['apdb']) && $GLOBALS['apdb'] instanceof AP_DB) {
            $db = $GLOBALS['apdb'];
        }
        AP_Transient::delete(self::RATE_BUCKET_TRANSIENT, $db);
        AP_Transient::delete(self::DIGEST_CLAIM_TRANSIENT, $db);
    }

    /**
     * Drop the in-request copy so the next read loads the stored window (tests).
     *
     * Does not delete the transient and does not touch `rate_limit_mail`.
     */
    public static function forgetInMemoryRateBucketForTests(): void
    {
        self::$rateBucket = null;
    }

    /**
     * Freeze {@see now()} for tests. Pass null to use the real clock.
     */
    public static function setNowForTests(?int $timestamp): void
    {
        self::$nowForTests = $timestamp;
    }

    /**
     * Normalize an enable flag to '1' or '0' for storage.
     */
    public static function sanitizeEnabled(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1') {
            return '1';
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'yes', 'on'], true)) {
                return '1';
            }
        }

        return '0';
    }

    /**
     * Normalize a per-minute cap. Invalid / out of range → default 4.
     */
    public static function sanitizeMaxPerMinute(mixed $value): int
    {
        if (is_bool($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '' || !is_numeric($value)) {
                return self::DEFAULT_MAX_PER_MINUTE;
            }
        }
        if (!is_numeric($value)) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        $n = (int) $value;
        if ($n < self::MIN_PER_MINUTE) {
            return self::DEFAULT_MAX_PER_MINUTE;
        }

        return min(self::MAX_PER_MINUTE, $n);
    }

    /**
     * Whether the user wants email about topics they subscribe to.
     *
     * Default is **off** when the usermeta row is missing or empty.
     * Guests (user id below 1) are always off.
     */
    public static function isUserNotifyEnabled(int $userId, ?AP_DB $db = null): bool
    {
        return self::userNotifyStoredValue($userId, $db) === '1';
    }

    /**
     * Stored `'1'` / `'0'` for the user master. Missing meta → `'0'`.
     */
    public static function userNotifyStoredValue(int $userId, ?AP_DB $db = null): string
    {
        if ($userId < 1) {
            return self::sanitizeEnabled(self::DEFAULT_USER_ENABLED);
        }

        $raw = self::userMetaValue($userId, $db);
        if ($raw === null || $raw === '') {
            return self::sanitizeEnabled(self::DEFAULT_USER_ENABLED);
        }

        return self::sanitizeEnabled($raw);
    }

    /**
     * Persist the user master switch as `'1'` or `'0'`.
     */
    public static function setUserNotifyEnabled(int $userId, mixed $value, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        return self::writeUserMeta($userId, self::sanitizeEnabled($value), $db);
    }

    /**
     * Turn the user master on when a Subscribe action succeeds and it is off.
     *
     * Choice (SPEC C): first Subscribe with the master off **flips it on**
     * rather than refusing and pointing at Profile. Subscribe chrome is
     * visible with the master off; refusing would silently no-op the button
     * for mail. Returns true only when this call changed the stored value
     * from off to on. Guests, already-on, and write failures return false.
     *
     * Does not insert a subscription row. Does not turn the master off.
     */
    public static function enableUserNotifyOnSubscribe(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (self::isUserNotifyEnabled($userId, $db)) {
            return false;
        }
        if (!self::setUserNotifyEnabled($userId, '1', $db)) {
            return false;
        }

        return self::isUserNotifyEnabled($userId, $db);
    }

    /**
     * Insert `'0'` when the key is missing. Does not overwrite a stored value.
     */
    public static function seedUserDefault(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }

        $existing = self::userMetaValue($userId, $db);
        if ($existing !== null && $existing !== '') {
            return true;
        }

        return self::writeUserMeta(
            $userId,
            self::sanitizeEnabled(self::DEFAULT_USER_ENABLED),
            $db
        );
    }

    /**
     * Persist notify site options. Omitted keys are left unchanged.
     *
     * @param array{
     *   forum_topic_notify_enabled?: mixed,
     *   forum_notify_max_per_minute?: mixed,
     *   enabled?: mixed,
     *   max_per_minute?: mixed
     * } $settings
     */
    public static function updateSettings(array $settings, ?AP_DB $db = null): bool
    {
        if (!class_exists('AP_Options', false)) {
            return false;
        }

        $ok = true;
        $enabledKey = array_key_exists(self::OPTION_ENABLED, $settings)
            ? self::OPTION_ENABLED
            : (array_key_exists('enabled', $settings) ? 'enabled' : null);
        if ($enabledKey !== null) {
            $enabledOk = AP_Options::update(
                self::OPTION_ENABLED,
                self::sanitizeEnabled($settings[$enabledKey]),
                $db
            );
            if (!$enabledOk) {
                $ok = false;
            }
        }

        $capKey = array_key_exists(self::OPTION_MAX_PER_MINUTE, $settings)
            ? self::OPTION_MAX_PER_MINUTE
            : (array_key_exists('max_per_minute', $settings) ? 'max_per_minute' : null);
        if ($capKey !== null) {
            $capOk = AP_Options::update(
                self::OPTION_MAX_PER_MINUTE,
                (string) self::sanitizeMaxPerMinute($settings[$capKey]),
                $db
            );
            if (!$capOk) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Insert the two options when missing. Does not overwrite stored values.
     */
    public static function seedDefaults(AP_DB $db): void
    {
        foreach (self::defaultOptionMap() as $name => $value) {
            if (class_exists('AP_Options', false)) {
                AP_Options::add($name, $value, $db, 'yes');
                continue;
            }

            $table = $db->quoteIdentifier($db->table('options'));
            $existing = $db->getVar(
                'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                [$name]
            );
            if ($existing !== null && $existing !== '') {
                continue;
            }

            $ok = $db->insert('options', [
                'option_name' => $name,
                'option_value' => $value,
                'autoload' => 'yes',
            ]);
            if ($ok === false) {
                $again = $db->getVar(
                    'SELECT option_id FROM ' . $table . ' WHERE option_name = ? LIMIT 1',
                    [$name]
                );
                if ($again !== null && $again !== '') {
                    continue;
                }
                throw new RuntimeException(
                    'Failed to seed forum notify option ' . $name . ': '
                    . ($db->lastError() ?? 'unknown error')
                );
            }
        }
    }

    /**
     * Default option map for installer / migration (name => stored string).
     *
     * @return array<string, string>
     */
    public static function defaultOptionMap(): array
    {
        return [
            self::OPTION_ENABLED => self::sanitizeEnabled(self::DEFAULT_ENABLED),
            self::OPTION_MAX_PER_MINUTE => (string) self::DEFAULT_MAX_PER_MINUTE,
        ];
    }

    // -------------------------------------------------------------------------
    // Per-topic subscriptions ({prefix}topic_subscriptions)
    // -------------------------------------------------------------------------

    /**
     * Whether the user is subscribed to the topic.
     *
     * Guests (user id below 1) are never subscribed.
     */
    public static function isSubscribed(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        try {
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
            $n = (int) $db->getVar(
                'SELECT COUNT(*) FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
                . ' AND ' . $db->quoteIdentifier('topic_id') . ' = ?',
                [$userId, $topicId]
            );
        } catch (Throwable) {
            return false;
        }

        return $n > 0;
    }

    /**
     * Whether compose POST asked to watch this topic.
     *
     * Default **off**: missing key, empty, `'0'`, or any non-truthy value.
     * Does not inspect the site or user master.
     *
     * @param array<string, mixed> $post Typically $_POST from start/reply.
     */
    public static function wantsNotifyOnCompose(array $post): bool
    {
        $raw = $post[self::POST_NOTIFY_REPLIES] ?? null;
        if ($raw === null && array_key_exists('ap_notify_replies', $post)) {
            $raw = $post['ap_notify_replies'];
        }

        return self::sanitizeEnabled($raw) === '1';
    }

    /**
     * Subscribe after a successful start or reply only when the compose
     * checkbox was on and the poster may Subscribe.
     *
     * Never auto-watches. Never unsubscribes. First Subscribe while the
     * user master is off flips it on ({@see enableUserNotifyOnSubscribe()})
     * so mail is not a silent no-op. Site master off, guests, and no
     * `view_forum` are no-ops.
     *
     * @param array<string, mixed> $post Typically $_POST from start/reply.
     */
    public static function maybeSubscribeFromCompose(
        int $userId,
        int $topicId,
        int $forumId,
        array $post,
        ?AP_DB $db = null
    ): bool {
        if ($userId < 1 || $topicId < 1 || $forumId < 1) {
            return false;
        }
        if (!self::wantsNotifyOnCompose($post)) {
            return false;
        }
        if (!self::viewerMaySubscribe($userId, $forumId, $db)) {
            return false;
        }

        $ok = self::subscribe($userId, $topicId, $db);
        if ($ok) {
            self::enableUserNotifyOnSubscribe($userId, $db);
        }

        return $ok;
    }

    /**
     * Watch a topic (idempotent). Unique `(user_id, topic_id)`.
     *
     * Storage only: does not flip the user master. User-facing Subscribe
     * (topic button, compose checkbox) calls {@see enableUserNotifyOnSubscribe()}
     * after a successful watch. Does not auto-watch on start or reply, and
     * does not write unread `topic_track` rows. Guests and missing user/topic
     * ids are rejected. Compose opt-in is {@see maybeSubscribeFromCompose()}.
     */
    public static function subscribe(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);
        if (self::isSubscribed($userId, $topicId, $db)) {
            return true;
        }
        if (!self::userExists($userId, $db) || !self::topicExists($topicId, $db)) {
            return false;
        }

        $ok = $db->insert('topic_subscriptions', [
            'user_id' => $userId,
            'topic_id' => $topicId,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($ok !== false) {
            return true;
        }

        // Unique race: another request inserted the same pair.
        return self::isSubscribed($userId, $topicId, $db);
    }

    /**
     * Drop one `(user, topic)` watch (idempotent). Missing rows succeed.
     */
    public static function unsubscribe(int $userId, int $topicId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $topicId < 1) {
            return false;
        }

        $db = self::resolveDb($db);

        try {
            $ok = $db->delete('topic_subscriptions', [
                'user_id' => $userId,
                'topic_id' => $topicId,
            ]);
        } catch (Throwable) {
            return false;
        }

        return $ok !== false;
    }

    /**
     * Subscription rows for a user, oldest first.
     *
     * @return list<object>
     */
    public static function listForUser(int $userId, ?AP_DB $db = null): array
    {
        if ($userId < 1) {
            return [];
        }

        $db = self::resolveDb($db);

        try {
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
            $rows = $db->getResults(
                'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('user_id') . ' = ?'
                . ' ORDER BY ' . $db->quoteIdentifier('created_at') . ' ASC, '
                . $db->quoteIdentifier('topic_id') . ' ASC',
                [$userId]
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Subscription rows for a topic, oldest first.
     *
     * @return list<object>
     */
    public static function listForTopic(int $topicId, ?AP_DB $db = null): array
    {
        if ($topicId < 1) {
            return [];
        }

        $db = self::resolveDb($db);

        try {
            $table = $db->quoteIdentifier($db->table('topic_subscriptions'));
            $rows = $db->getResults(
                'SELECT * FROM ' . $table
                . ' WHERE ' . $db->quoteIdentifier('topic_id') . ' = ?'
                . ' ORDER BY ' . $db->quoteIdentifier('created_at') . ' ASC, '
                . $db->quoteIdentifier('user_id') . ' ASC',
                [$topicId]
            );
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Subscriptions for a user with topic titles (profile / account list).
     *
     * Oldest first. Missing topics keep a fallback title so the member can
     * still unsubscribe an orphaned row.
     *
     * @return list<array{
     *   user_id: int,
     *   topic_id: int,
     *   created_at: string,
     *   topic_title: string,
     *   topic_url: string
     * }>
     */
    public static function listForUserWithTitles(int $userId, ?AP_DB $db = null): array
    {
        $rows = self::listForUser($userId, $db);
        if ($rows === []) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            $tid = (int) ($row->topic_id ?? 0);
            if ($tid > 0) {
                $ids[] = $tid;
            }
        }

        $topics = [];
        if ($ids !== [] && class_exists('AP_Forum', false)) {
            $topics = AP_Forum::getTopicsByIds($ids, $db);
        }

        $out = [];
        foreach ($rows as $row) {
            $topicId = (int) ($row->topic_id ?? 0);
            if ($topicId < 1) {
                continue;
            }
            $topic = $topics[$topicId] ?? null;
            $title = '';
            if (is_object($topic)) {
                $title = trim((string) ($topic->topic_title ?? ''));
            }
            if ($title === '') {
                $title = 'Topic #' . $topicId;
            }
            $url = '';
            if (class_exists('AP_Forum', false)) {
                $url = AP_Forum::topicUrl(is_object($topic) ? $topic : $topicId);
            }
            $out[] = [
                'user_id' => (int) ($row->user_id ?? $userId),
                'topic_id' => $topicId,
                'created_at' => (string) ($row->created_at ?? ''),
                'topic_title' => $title,
                'topic_url' => $url,
            ];
        }

        return $out;
    }

    /**
     * Drop every watch for a user (account delete). Returns rows removed.
     */
    public static function deleteForUser(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1) {
            return 0;
        }

        return self::deleteWhere(['user_id' => $userId], $db);
    }

    /**
     * Drop every watch for a topic (hard-delete). Returns rows removed.
     */
    public static function deleteForTopic(int $topicId, ?AP_DB $db = null): int
    {
        if ($topicId < 1) {
            return 0;
        }

        return self::deleteWhere(['topic_id' => $topicId], $db);
    }

    /**
     * @param array<string, int> $where
     */
    private static function deleteWhere(array $where, ?AP_DB $db): int
    {
        $db = self::resolveDb($db);

        try {
            $ok = $db->delete('topic_subscriptions', $where);
        } catch (Throwable) {
            return 0;
        }

        return $ok === false ? 0 : max(0, $ok);
    }

    private static function userExists(int $userId, AP_DB $db): bool
    {
        if (class_exists('AP_User', false)) {
            return AP_User::getById($userId, $db) !== null;
        }

        try {
            $raw = $db->getVar(
                'SELECT ID FROM ' . $db->quoteIdentifier($db->table('users'))
                . ' WHERE ID = ? LIMIT 1',
                [$userId]
            );
        } catch (Throwable) {
            return false;
        }

        return $raw !== null && $raw !== '' && (int) $raw === $userId;
    }

    private static function topicExists(int $topicId, AP_DB $db): bool
    {
        if (class_exists('AP_Forum', false)) {
            return AP_Forum::getTopic($topicId, $db) !== null;
        }

        try {
            $raw = $db->getVar(
                'SELECT topic_id FROM ' . $db->quoteIdentifier($db->table('topics'))
                . ' WHERE topic_id = ? LIMIT 1',
                [$topicId]
            );
        } catch (Throwable) {
            return false;
        }

        return $raw !== null && $raw !== '' && (int) $raw === $topicId;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('Database not available for topic notify.');
    }

    private static function optionValue(string $name, string $default, ?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $val = AP_Options::get($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }
        if (function_exists('ap_get_option')) {
            $val = ap_get_option($name, $default, $db);
            if (is_bool($val)) {
                return $val ? '1' : '0';
            }
            if (is_scalar($val)) {
                return (string) $val;
            }
        }

        return $default;
    }

    private static function userMetaValue(int $userId, ?AP_DB $db): ?string
    {
        if (class_exists('AP_User', false)) {
            return AP_User::getMeta($userId, self::META_NOTIFY_EMAIL, $db);
        }
        if ($db === null) {
            return null;
        }

        try {
            $raw = $db->getVar(
                'SELECT meta_value FROM ' . $db->quoteIdentifier($db->table('usermeta'))
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_NOTIFY_EMAIL]
            );
        } catch (Throwable) {
            return null;
        }

        if ($raw === null) {
            return null;
        }

        return (string) $raw;
    }

    private static function writeUserMeta(int $userId, string $value, ?AP_DB $db): bool
    {
        if (class_exists('AP_User', false)) {
            return AP_User::updateMeta($userId, self::META_NOTIFY_EMAIL, $value, $db);
        }
        if ($db === null) {
            return false;
        }

        try {
            $table = $db->quoteIdentifier($db->table('usermeta'));
            $existing = $db->getVar(
                'SELECT umeta_id FROM ' . $table
                . ' WHERE user_id = ? AND meta_key = ? LIMIT 1',
                [$userId, self::META_NOTIFY_EMAIL]
            );
            if ($existing !== null && $existing !== '') {
                return $db->update(
                    'usermeta',
                    ['meta_value' => $value],
                    [
                        'user_id' => $userId,
                        'meta_key' => self::META_NOTIFY_EMAIL,
                    ]
                ) !== false;
            }

            return $db->insert('usermeta', [
                'user_id' => $userId,
                'meta_key' => self::META_NOTIFY_EMAIL,
                'meta_value' => $value,
            ]) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function siteName(?AP_DB $db): string
    {
        $name = trim(self::optionValue('blogname', '', $db));
        if ($name === '') {
            $name = 'AgoraPress';
        }

        return $name;
    }

    private static function replyAuthorLabel(object $post, ?AP_DB $db): string
    {
        $posterId = (int) ($post->poster_id ?? 0);
        if ($posterId > 0 && class_exists('AP_User', false)) {
            $user = AP_User::getById($posterId, $db);
            if ($user !== null) {
                $name = trim($user->display_name);
                if ($name === '') {
                    $name = trim($user->user_login);
                }
                if ($name !== '') {
                    return $name;
                }
            }
        }
        $guest = trim((string) ($post->poster_name ?? ''));

        return $guest !== '' ? $guest : 'Guest';
    }

    private static function replyExcerpt(object $post): string
    {
        $content = (string) ($post->post_content ?? '');
        if ($content === '') {
            return '';
        }
        if (function_exists('ap_strip_spoilers')) {
            $content = ap_strip_spoilers($content);
        } elseif (class_exists('AP_Content_Format', false)) {
            $content = AP_Content_Format::stripSpoilers($content);
        }
        $text = trim(strip_tags($content));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/u', $text, self::EXCERPT_WORDS + 1) ?: [];
        if (count($parts) > self::EXCERPT_WORDS) {
            $parts = array_slice($parts, 0, self::EXCERPT_WORDS);
            $text = implode(' ', $parts) . '…';
        } else {
            $text = implode(' ', $parts);
        }

        return $text;
    }

    private static function absoluteHomeUrl(?AP_DB $db): string
    {
        $home = trim(self::optionValue('home', '', $db));
        if ($home === '') {
            $home = trim(self::optionValue('siteurl', '', $db));
        }
        if ($home === '' && function_exists('ap_home_url')) {
            try {
                $home = (string) ap_home_url('/', $db);
            } catch (Throwable) {
                $home = '';
            }
        }
        if ($home === '' && defined('AP_HOME') && is_string(AP_HOME) && AP_HOME !== '') {
            $home = (string) AP_HOME;
        }
        if ($home === '') {
            $home = '/';
        }

        return rtrim($home, '/') . '/';
    }

    private static function absolutizeUrl(string $url, ?AP_DB $db): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }
        $home = self::absoluteHomeUrl($db);
        if (str_starts_with($url, '/')) {
            $parts = parse_url($home);
            $scheme = is_array($parts) && isset($parts['scheme']) ? (string) $parts['scheme'] : '';
            $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
            if ($scheme !== '' && $host !== '') {
                $port = '';
                if (isset($parts['port'])) {
                    $port = ':' . (string) $parts['port'];
                }

                return $scheme . '://' . $host . $port . $url;
            }
        }

        return $home . ltrim($url, '/');
    }

    private static function signedUnsubscribeRedirect(
        ?object $topic,
        string $notice,
        ?AP_DB $db
    ): string {
        $url = '';
        if ($topic !== null && class_exists('AP_Forum', false)) {
            $url = self::absolutizeUrl(AP_Forum::topicUrl($topic), $db);
        }
        if ($url === '') {
            $url = self::absoluteHomeUrl($db);
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . 'ap_forum_notice=' . $notice;
    }

    private static function unsubscribeHmac(int $userId, int $topicId, int $exp): string
    {
        return hash_hmac(
            'sha256',
            'forum-unsub|' . $userId . '|' . $topicId . '|' . $exp,
            self::unsubscribeSigningSecret()
        );
    }

    private static function unsubscribeSigningSecret(): string
    {
        $key = defined('AP_AUTH_KEY') ? (string) AP_AUTH_KEY : '';
        $salt = defined('AP_AUTH_SALT') ? (string) AP_AUTH_SALT : '';
        if ($key === '' && $salt === '') {
            $key = defined('AP_LOGGED_IN_KEY') ? (string) AP_LOGGED_IN_KEY : 'agorapress-auth';
            $salt = defined('AP_LOGGED_IN_SALT') ? (string) AP_LOGGED_IN_SALT : 'agorapress-salt';
        }

        return $key . $salt;
    }

    private static function toBase64Url(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    private static function fromBase64Url(string $token): ?string
    {
        $b64 = strtr($token, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $payload = base64_decode($b64, true);
        if ($payload === false) {
            return null;
        }

        return $payload;
    }

    /**
     * Subset of $posts this subscriber may be mailed about.
     *
     * @param list<object> $posts
     *
     * @return list<object>
     */
    private static function eligibleRepliesForUser(
        int $userId,
        int $forumId,
        array $posts,
        ?AP_DB $db
    ): array {
        if ($userId < 1 || $forumId < 1) {
            return [];
        }

        $out = [];
        foreach ($posts as $post) {
            if (!is_object($post)) {
                continue;
            }
            $posterId = (int) ($post->poster_id ?? 0);
            if (!self::isEligibleRecipient($userId, $forumId, $posterId, $db)) {
                continue;
            }
            $out[] = $post;
        }

        return $out;
    }

    /**
     * Per-reply fallback when a digest cannot be composed.
     *
     * Prefers the triggering reply when the user is eligible for it.
     *
     * @param list<object> $eligible
     */
    private static function singleReplyForFallback(array $eligible, int $preferPostId): ?object
    {
        foreach ($eligible as $post) {
            if ((int) ($post->post_id ?? 0) === $preferPostId) {
                return $post;
            }
        }

        $first = $eligible[0] ?? null;

        return is_object($first) ? $first : null;
    }

    /**
     * Reply post ids still scheduled for this topic on {@see CRON_HOOK}.
     *
     * @return list<int>
     */
    private static function queuedReplyIdsFromCron(int $topicId, ?AP_DB $db): array
    {
        if ($topicId < 1 || !class_exists('AP_Cron', false)) {
            return [];
        }

        $ids = [];
        foreach (AP_Cron::getCronArray($db) as $ts => $hooks) {
            if ($ts === 'version' || !is_array($hooks)) {
                continue;
            }
            $events = $hooks[self::CRON_HOOK] ?? null;
            if (!is_array($events)) {
                continue;
            }
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $args = is_array($event['args'] ?? null) ? $event['args'] : [];
                $queuedTopic = (int) ($args[0] ?? 0);
                $queuedReply = (int) ($args[1] ?? 0);
                if ($queuedTopic === $topicId && $queuedReply > 0) {
                    $ids[] = $queuedReply;
                }
            }
        }

        return $ids;
    }

    /**
     * Whether this reply was already bundled into a digest as a sibling.
     */
    private static function wasClaimedInDigest(int $replyPostId, ?AP_DB $db): bool
    {
        if ($replyPostId < 1) {
            return false;
        }

        $state = self::readDigestClaims($db);

        return isset($state[$replyPostId]);
    }

    /**
     * @return array<int, true>
     */
    private static function readDigestClaims(?AP_DB $db): array
    {
        if (self::$digestClaims !== null) {
            return self::$digestClaims;
        }

        $state = [];
        if (class_exists('AP_Transient', false)) {
            $raw = AP_Transient::get(self::DIGEST_CLAIM_TRANSIENT, false, $db);
            if (is_array($raw)) {
                foreach ($raw as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $state[$id] = true;
                    }
                }
            }
        }
        self::$digestClaims = $state;

        return $state;
    }

    /**
     * Mark sibling reply ids as mailed in this digest and drop their cron events.
     *
     * The triggering reply is unscheduled too so a later cron fire does not
     * send a second per-reply mail. It is not stored in the claim set, so a
     * direct second call with the same id (tests) can still send.
     *
     * @param list<object> $posts
     */
    private static function claimDigestSiblings(
        array $posts,
        int $currentReplyId,
        int $topicId,
        ?AP_DB $db
    ): void {
        $ids = [];
        foreach ($posts as $post) {
            $id = (int) ($post->post_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));

        $state = self::readDigestClaims($db);
        foreach ($ids as $id) {
            if ($id === $currentReplyId) {
                continue;
            }
            $state[$id] = true;
        }
        self::$digestClaims = $state;

        if (class_exists('AP_Transient', false)) {
            AP_Transient::set(
                self::DIGEST_CLAIM_TRANSIENT,
                array_map('intval', array_keys($state)),
                self::DIGEST_CLAIM_TTL,
                $db
            );
        }
        if (!class_exists('AP_Cron', false) || $topicId < 1) {
            return;
        }
        foreach ($ids as $id) {
            AP_Cron::clearHook(self::CRON_HOOK, [$topicId, $id], $db);
        }
    }

    /**
     * Reserve one notify send in the current minute. False when the cap is full.
     *
     * Does not call {@see AP_Mail::send()} and does not touch `rate_limit_mail`.
     */
    private static function consumeNotifyQuota(?AP_DB $db): bool
    {
        $max = self::getMaxPerMinute($db);
        $now = self::now();
        $state = self::readRateBucket($db);

        if ($state['window_start'] < 1) {
            $state = [
                'window_start' => $now,
                'count' => 0,
            ];
        }

        if ($state['count'] >= $max) {
            self::$rateBucket = $state;

            return false;
        }

        $state['count']++;
        self::writeRateBucket($state, $db);

        return true;
    }

    /**
     * Give back a reserved notify slot after {@see AP_Mail::send()} fails.
     *
     * Does not touch `rate_limit_mail`. No-op when the window is empty.
     */
    private static function refundNotifyQuota(?AP_DB $db): void
    {
        $state = self::readRateBucket($db);
        if ($state['count'] < 1) {
            return;
        }

        $state['count']--;
        self::writeRateBucket($state, $db);
    }

    /**
     * @return array{window_start: int, count: int}
     */
    private static function readRateBucket(?AP_DB $db): array
    {
        $empty = [
            'window_start' => 0,
            'count' => 0,
        ];
        $state = self::$rateBucket;
        if ($state === null && class_exists('AP_Transient', false)) {
            $raw = AP_Transient::get(self::RATE_BUCKET_TRANSIENT, false, $db);
            if (is_array($raw)) {
                $state = [
                    'window_start' => max(0, (int) ($raw['window_start'] ?? 0)),
                    'count' => max(0, (int) ($raw['count'] ?? 0)),
                ];
            }
        }
        if ($state === null) {
            $state = $empty;
        }

        $now = self::now();
        if (
            $state['window_start'] < 1
            || ($now - $state['window_start']) >= self::RATE_WINDOW_SECONDS
        ) {
            $state = $empty;
        }

        self::$rateBucket = $state;

        return $state;
    }

    /**
     * @param array{window_start: int, count: int} $state
     */
    private static function writeRateBucket(array $state, ?AP_DB $db): void
    {
        self::$rateBucket = $state;
        if (!class_exists('AP_Transient', false)) {
            return;
        }

        $ttl = ($state['window_start'] + self::RATE_WINDOW_SECONDS) - self::now();
        AP_Transient::set(
            self::RATE_BUCKET_TRANSIENT,
            [
                'window_start' => (int) $state['window_start'],
                'count' => (int) $state['count'],
            ],
            max(1, $ttl + 5),
            $db
        );
    }

    private static function now(): int
    {
        return self::$nowForTests ?? time();
    }
}
