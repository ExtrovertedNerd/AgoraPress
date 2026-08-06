<?php

/**
 * ACP Analytics screen helpers — report data + settings for local privacy-respecting analytics.
 *
 * Uses {@see AP_Analytics} for config and aggregation. Access is gated by
 * {@see CAPABILITY} on the entry script (Tools → Analytics).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Admin analytics report builder and settings save.
 */
class AP_Admin_Analytics
{
    /** Capability required to view/save the Analytics ACP screen. */
    public const CAPABILITY = 'manage_options';

    /** Nonce action for the Analytics settings form. */
    public const NONCE_ACTION = 'ap_analytics_settings';

    /** POST field name for the settings submit button. */
    public const SETTINGS_SUBMIT = 'ap_save_analytics';

    /** Default window for top paths / referrers / daily table (calendar days including today). */
    public const DEFAULT_DAYS = 30;

    /** Allowed day-window choices for the daily table / top lists. */
    public const ALLOWED_DAYS = [7, 14, 30, 90];

    /** Default row limit for top paths and top referrers. */
    public const DEFAULT_TOP_LIMIT = 10;

    /**
     * Brief screen intro: local-only collection, no third parties, not HoF / version check.
     *
     * Shown under the Analytics page title so admins understand privacy posture at a glance.
     */
    public const PRIVACY_INTRO =
        'Local pageview reports for this site only. Data is stored in your database '
        . 'and is never sent to third parties or the AgoraPress project site. '
        . 'Collection is separate from Hall of Fame and the version check.';

    /**
     * Short help under the “Enable pageview collection” checkbox.
     */
    public const PRIVACY_COLLECTION_HELP =
        'When on, public GET/HEAD page views are recorded server-side into this site’s '
        . 'database only. No third-party scripts, pixels, or external analytics endpoints. '
        . 'This is not Hall of Fame registration and not the public version check. '
        . 'Admin screens, feeds, and logged-in administrators are excluded by default. '
        . 'Off by default so nothing is collected until you opt in.';

    /**
     * Whether the current user may view the Analytics screen.
     */
    public static function currentUserCanView(?AP_DB $db = null): bool
    {
        if (class_exists('AP_Admin', false)) {
            return AP_Admin::currentUserCan(self::CAPABILITY, $db);
        }
        if (function_exists('ap_current_user_can')) {
            return ap_current_user_can(self::CAPABILITY, null, $db);
        }

        return false;
    }

    /**
     * Whether the request is a settings form POST for this screen.
     *
     * @param array<string, mixed> $post Typically $_POST.
     * @param array<string, mixed> $server Typically $_SERVER.
     */
    public static function isSettingsPost(array $post = [], array $server = []): bool
    {
        if ($post === [] && isset($_POST) && is_array($_POST)) {
            $post = $_POST;
        }
        if ($server === [] && isset($_SERVER) && is_array($_SERVER)) {
            $server = $_SERVER;
        }
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST') {
            return false;
        }

        return isset($post[self::SETTINGS_SUBMIT])
            || isset($post['ap_settings_submit']);
    }

    /**
     * Persist analytics settings from a form POST payload.
     *
     * Checkbox: absent or empty → collection off. Retention days sanitized via
     * {@see AP_Analytics::sanitizeRetentionDays()}.
     *
     * @param array<string, mixed> $post Form fields (typically $_POST).
     * @param int|null             $userId Acting user for nonce verification (null = current).
     *
     * @return array{
     *     ok: bool,
     *     message_key: string,
     *     error: string,
     *     enabled?: bool,
     *     retention_days?: int
     * }
     */
    public static function saveSettingsFromPost(array $post, ?int $userId = null, ?AP_DB $db = null): array
    {
        if ($userId === null && function_exists('ap_get_current_user_id')) {
            $userId = (int) ap_get_current_user_id();
        }
        $userId = (int) ($userId ?? 0);

        $nonce = (string) ($post['_ap_nonce'] ?? '');
        $nonceOk = false;
        if (function_exists('ap_check_nonce')) {
            $nonceOk = ap_check_nonce($nonce, self::NONCE_ACTION, $userId > 0 ? $userId : null);
        }
        if (!$nonceOk) {
            return [
                'ok' => false,
                'message_key' => 'nonce',
                'error' => 'Security check failed. Please try again.',
            ];
        }

        $can = false;
        if (class_exists('AP_Admin', false) && $userId > 0) {
            $can = AP_Admin::userCan($userId, self::CAPABILITY, null, $db);
        } elseif (class_exists('AP_Roles', false) && $userId > 0) {
            $can = AP_Roles::userCan($userId, self::CAPABILITY, null, $db);
        } elseif (function_exists('ap_user_can') && $userId > 0) {
            $can = ap_user_can($userId, self::CAPABILITY, null, $db);
        }
        if (!$can) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'error' => 'You do not have permission to change analytics settings.',
            ];
        }

        if (!class_exists('AP_Analytics', false)) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'error' => 'Analytics is not available.',
            ];
        }

        // Unchecked checkbox is absent from POST.
        $enabledRaw = $post['analytics_enabled'] ?? $post['ap_analytics_enabled'] ?? '0';
        $retentionRaw = $post['analytics_retention_days']
            ?? $post['ap_analytics_retention_days']
            ?? AP_Analytics::DEFAULT_RETENTION_DAYS;

        $ok = AP_Analytics::updateSettings([
            'analytics_enabled' => $enabledRaw,
            'analytics_retention_days' => $retentionRaw,
        ], $db);

        if (!$ok) {
            return [
                'ok' => false,
                'message_key' => 'error',
                'error' => 'Could not save analytics settings.',
            ];
        }

        return [
            'ok' => true,
            'message_key' => 'analytics_saved',
            'error' => '',
            'enabled' => AP_Analytics::isEnabled($db),
            'retention_days' => AP_Analytics::getRetentionDays($db),
        ];
    }

    /**
     * Normalize a requested day window to an allowed value.
     */
    public static function sanitizeDays(mixed $value): int
    {
        if (!is_numeric($value)) {
            return self::DEFAULT_DAYS;
        }
        $n = (int) $value;
        if (in_array($n, self::ALLOWED_DAYS, true)) {
            return $n;
        }

        return self::DEFAULT_DAYS;
    }

    /**
     * Build the full report payload for the ACP Analytics screen.
     *
     * @param array<string, mixed> $args {
     *     @type int $days Window for top paths, referrers, daily table (7|14|30|90).
     *     @type int $limit Max rows for top lists (default 10).
     *     @type int $now   Unix timestamp for relative ranges (tests).
     * }
     *
     * @return array{
     *     enabled: bool,
     *     retention_days: int,
     *     days: int,
     *     summary: array{today: int, last_7_days: int, last_30_days: int},
     *     top_paths: list<array{path: string, object_id: int, hits: int}>,
     *     top_referrers: list<array{referrer: string, hits: int}>,
     *     daily: list<array{day: string, hits: int}>,
     *     has_hits: bool,
     *     range: array{since: string, until: string}
     * }
     */
    public static function getReport(?AP_DB $db = null, array $args = []): array
    {
        $days = self::sanitizeDays($args['days'] ?? self::DEFAULT_DAYS);
        $limit = 10;
        if (isset($args['limit']) && is_numeric($args['limit'])) {
            $limit = max(1, min(100, (int) $args['limit']));
        }
        $now = isset($args['now']) ? (int) $args['now'] : time();
        if ($now <= 0) {
            $now = time();
        }

        $enabled = false;
        $retention = 90;
        if (class_exists('AP_Analytics', false)) {
            $enabled = AP_Analytics::isEnabled($db);
            $retention = AP_Analytics::getRetentionDays($db);
        }

        $summary = ['today' => 0, 'last_7_days' => 0, 'last_30_days' => 0];
        $topPaths = [];
        $topReferrers = [];
        $daily = [];

        $since = date('Y-m-d 00:00:00', $now - (($days - 1) * 86400));
        $until = date('Y-m-d 00:00:00', $now + 86400);

        if (class_exists('AP_Analytics', false)) {
            $summary = AP_Analytics::getSummary($db, $now);
            $rangeArgs = [
                'since' => $since,
                'until' => $until,
                'exclude_admin' => true,
                'limit' => $limit,
            ];
            $topPaths = AP_Analytics::getTopPaths($db, $rangeArgs);
            $topReferrers = AP_Analytics::getTopReferrers($db, $rangeArgs);
            $daily = AP_Analytics::getDailyTotals($db, [
                'days' => $days,
                'now' => $now,
                'exclude_admin' => true,
                'fill_gaps' => true,
            ]);
        } elseif (function_exists('ap_analytics_summary')) {
            $summary = ap_analytics_summary($db, $now);
        }

        $hasHits = ((int) ($summary['last_30_days'] ?? 0)) > 0
            || ((int) ($summary['today'] ?? 0)) > 0
            || ((int) ($summary['last_7_days'] ?? 0)) > 0
            || $topPaths !== []
            || $topReferrers !== [];

        if (!$hasHits && $daily !== []) {
            foreach ($daily as $row) {
                if ((int) ($row['hits'] ?? 0) > 0) {
                    $hasHits = true;
                    break;
                }
            }
        }

        return [
            'enabled' => $enabled,
            'retention_days' => $retention,
            'days' => $days,
            'summary' => $summary,
            'top_paths' => $topPaths,
            'top_referrers' => $topReferrers,
            'daily' => $daily,
            'has_hits' => $hasHits,
            'range' => [
                'since' => $since,
                'until' => $until,
            ],
        ];
    }

    /**
     * Max hit count in a daily series (for simple bar scaling). 0 when empty.
     *
     * @param list<array{day?: string, hits?: int}> $daily
     */
    public static function maxDailyHits(array $daily): int
    {
        $max = 0;
        foreach ($daily as $row) {
            $hits = (int) ($row['hits'] ?? 0);
            if ($hits > $max) {
                $max = $hits;
            }
        }

        return $max;
    }

    /**
     * Truncate a path or referrer for table display.
     */
    public static function truncateLabel(string $label, int $max = 64): string
    {
        $label = trim($label);
        if ($label === '') {
            return '—';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($label, 'UTF-8') <= $max) {
                return $label;
            }

            return mb_substr($label, 0, $max - 1, 'UTF-8') . '…';
        }
        if (strlen($label) <= $max) {
            return $label;
        }

        return substr($label, 0, $max - 1) . '…';
    }

    /**
     * Classify the overall empty / disabled state for the Analytics screen.
     *
     * @return 'disabled_no_data'|'disabled_with_history'|'enabled_no_data'|'has_data'
     */
    public static function emptyStateKind(bool $enabled, bool $hasHits): string
    {
        if ($hasHits) {
            return $enabled ? 'has_data' : 'disabled_with_history';
        }

        return $enabled ? 'enabled_no_data' : 'disabled_no_data';
    }

    /**
     * User-facing empty copy for a report widget (paths, referrers, daily, page).
     *
     * When the list for the selected window is empty, messages distinguish:
     * collection off with no history, collection on but nothing recorded yet,
     * and “no activity in this window” when history exists elsewhere.
     *
     * @param string $widget  One of: paths, referrers, daily, page.
     * @param int    $days    Selected report window (for “this window” copy).
     * @param bool   $listEmpty True when the widget’s current list has no rows / no hits.
     *
     * @return array{kind: string, title: string, message: string, show_settings_link: bool}
     */
    public static function emptyStateFor(
        string $widget,
        bool $enabled,
        bool $hasHits,
        int $days = self::DEFAULT_DAYS,
        bool $listEmpty = true
    ): array {
        $kind = self::emptyStateKind($enabled, $hasHits);
        $days = self::sanitizeDays($days);
        $widget = strtolower(trim($widget));
        if (!in_array($widget, ['paths', 'referrers', 'daily', 'page'], true)) {
            $widget = 'page';
        }

        // Widget has rows — caller should not render empty UI.
        if (!$listEmpty) {
            return [
                'kind' => $kind,
                'title' => '',
                'message' => '',
                'show_settings_link' => false,
            ];
        }

        // History exists (possibly outside this window): window-specific empty.
        if ($hasHits) {
            $title = 'No activity in this window';
            if ($widget === 'referrers') {
                $message = 'No referrers recorded in the last ' . $days . ' days.';
            } elseif ($widget === 'paths') {
                $message = 'No pageviews in the last ' . $days . ' days.';
            } elseif ($widget === 'daily') {
                $message = 'No pageviews in the last ' . $days . ' days.';
            } else {
                $message = 'No pageviews in the selected window.';
            }

            return [
                'kind' => $kind,
                'title' => $title,
                'message' => $message,
                'show_settings_link' => false,
            ];
        }

        // No hits at all.
        if (!$enabled) {
            $title = 'No data yet';
            $message = 'Collection is off. Enable pageview collection in Analytics settings '
                . 'to start recording public visits. Nothing is stored until you opt in.';
            if ($widget === 'referrers') {
                $message = 'No referrers yet. Turn on collection in Analytics settings to record public page views.';
            } elseif ($widget === 'paths') {
                $message = 'No pageviews yet. Turn on collection in Analytics settings to start recording public visits.';
            } elseif ($widget === 'daily') {
                $message = 'No data yet. Turn on collection in Analytics settings to start recording public page views.';
            }

            return [
                'kind' => 'disabled_no_data',
                'title' => $title,
                'message' => $message,
                'show_settings_link' => true,
            ];
        }

        // Enabled, never recorded a hit (or all pruned).
        $title = 'Waiting for pageviews';
        $message = 'Collection is on. Public GET/HEAD visits will appear here after the next page load. '
            . 'Admin screens and logged-in administrators are excluded by default.';
        if ($widget === 'referrers') {
            $message = 'Collection is on, but no referrers have been recorded yet. '
                . 'Referrers appear when public visitors arrive from other sites.';
        } elseif ($widget === 'paths') {
            $message = 'Collection is on, but no pageviews have been recorded yet. '
                . 'Visit a public page (while not logged in as an administrator) to generate the first hit.';
        } elseif ($widget === 'daily') {
            $message = 'Collection is on, but no pageviews have been recorded yet. '
                . 'Daily totals fill in as public traffic is recorded.';
        }

        return [
            'kind' => 'enabled_no_data',
            'title' => $title,
            'message' => $message,
            'show_settings_link' => false,
        ];
    }

    /**
     * Render a compact empty-state block for an Analytics widget.
     *
     * @param array{kind?: string, title?: string, message?: string, show_settings_link?: bool} $state
     */
    public static function renderEmptyState(array $state, string $extraClass = ''): string
    {
        $message = trim((string) ($state['message'] ?? ''));
        if ($message === '') {
            return '';
        }
        $title = trim((string) ($state['title'] ?? ''));
        $kind = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($state['kind'] ?? 'empty'))) ?: 'empty';
        $showLink = !empty($state['show_settings_link']);
        $class = 'ap-analytics-empty ap-analytics-empty--' . $kind;
        if ($extraClass !== '') {
            $class .= ' ' . trim($extraClass);
        }

        $html = '<div class="' . ap_esc_attr($class) . '" role="status" data-empty-kind="' . ap_esc_attr($kind) . '">';
        if ($title !== '') {
            $html .= '<p class="ap-analytics-empty-title"><strong>' . ap_esc_html($title) . '</strong></p>';
        }
        $html .= '<p class="ap-analytics-empty-message">' . ap_esc_html($message) . '</p>';
        if ($showLink) {
            $html .= '<p class="ap-analytics-empty-action">'
                . '<a href="#ap-analytics-settings">Open Analytics settings</a>'
                . '</p>';
        }
        $html .= '</div>';

        return $html;
    }
}
