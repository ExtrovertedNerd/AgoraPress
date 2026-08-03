<?php

/**
 * Core version checker — public version.json only, no site identification.
 *
 * Fetches https://agorapress.extrovertednerd.com/version.json (or a filterable
 * URL), compares the remote SemVer to AP_VERSION, and surfaces an admin-only
 * “Update available” notice with download + changelog links when newer.
 *
 * Privacy invariants:
 * - GET only; no request body
 * - No domain, site URL, email, or other identifying query/header data
 * - Transient-cached (success and soft failure) so checks are infrequent
 * - Network/parse failures fail silently (no admin error noise)
 * - Optional site option `version_check_enabled` (default on) and constant
 *   AP_TELEMETRY is unrelated — this path never phones home with identity
 *
 * One-click auto-update is handled by {@see AP_Core_Updater} (admin
 * update-core.php) using the download_url (+ optional sha256) from this check.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Version check against the project public JSON endpoint.
 */
class AP_Version_Check
{
    /** Default public endpoint (no site data appended). */
    public const DEFAULT_ENDPOINT = 'https://agorapress.extrovertednerd.com/version.json';

    /** Transient key for cached remote payload (options-backed). */
    public const TRANSIENT_KEY = 'ap_version_check';

    /** How long a successful remote response is cached (12 hours). */
    public const CACHE_TTL_SUCCESS = 43200;

    /** How long a soft failure is cached so we do not hammer the endpoint (1 hour). */
    public const CACHE_TTL_FAILURE = 3600;

    /** Option: '1' = enabled (default), '0' = disabled (offline / privacy). */
    public const OPTION_ENABLED = 'version_check_enabled';

    /**
     * Optional HTTP transport for tests:
     * function(string $method, string $url): array{ok:bool,status:int,body:string,error:string}
     *
     * @var callable|null
     */
    private static $httpTransport = null;

    /**
     * Whether automatic version checks are enabled for this site.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $enabled = true;
        if (class_exists('AP_Options', false)) {
            $raw = (string) AP_Options::get(self::OPTION_ENABLED, '1', $db);
            $enabled = $raw !== '0' && strtolower($raw) !== 'false' && $raw !== '';
        }

        if (function_exists('ap_apply_filters')) {
            return (bool) ap_apply_filters('ap_version_check_enabled', $enabled, $db);
        }

        return $enabled;
    }

    /**
     * Public endpoint URL (filterable). Never includes site-identifying query args.
     */
    public static function endpointUrl(): string
    {
        $url = self::DEFAULT_ENDPOINT;
        if (function_exists('ap_apply_filters')) {
            $url = (string) ap_apply_filters('ap_version_check_url', $url);
        }
        $url = trim($url);

        return $url !== '' ? $url : self::DEFAULT_ENDPOINT;
    }

    /**
     * Installed core version string (AP_VERSION).
     */
    public static function currentVersion(): string
    {
        return defined('AP_VERSION') ? (string) AP_VERSION : '';
    }

    /**
     * Compare two SemVer-ish strings (PHP version_compare).
     *
     * @return int -1 if $left < $right, 0 if equal, 1 if $left > $right
     */
    public static function compareVersions(string $left, string $right): int
    {
        $left = self::normalizeVersion($left);
        $right = self::normalizeVersion($right);
        if ($left === '' || $right === '') {
            return 0;
        }

        return version_compare($left, $right);
    }

    /**
     * Whether $remote is strictly newer than $current.
     */
    public static function isNewer(string $remote, string $current): bool
    {
        return self::compareVersions($remote, $current) > 0;
    }

    /**
     * Cached remote info (fetches when cache miss). Fails silently on network errors.
     *
     * @return array{
     *   ok: bool,
     *   version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string,
     *   checked_at: int,
     *   from_cache: bool
     * }
     */
    public static function getRemoteInfo(?AP_DB $db = null, bool $force = false): array
    {
        $empty = self::emptyResult(false);

        if (!self::isEnabled($db)) {
            return $empty;
        }

        if (!$force && class_exists('AP_Transient', false)) {
            $cached = AP_Transient::get(self::TRANSIENT_KEY, false, $db);
            if (is_array($cached) && isset($cached['checked_at'])) {
                return [
                    'ok' => (bool) ($cached['ok'] ?? false),
                    'version' => (string) ($cached['version'] ?? ''),
                    'download_url' => (string) ($cached['download_url'] ?? ''),
                    'changelog_url' => (string) ($cached['changelog_url'] ?? ''),
                    'sha256' => (string) ($cached['sha256'] ?? ''),
                    'checked_at' => (int) ($cached['checked_at'] ?? 0),
                    'from_cache' => true,
                ];
            }
        }

        $fetched = self::fetchRemote();
        $ttl = $fetched['ok'] ? self::CACHE_TTL_SUCCESS : self::CACHE_TTL_FAILURE;
        $store = [
            'ok' => $fetched['ok'],
            'version' => $fetched['version'],
            'download_url' => $fetched['download_url'],
            'changelog_url' => $fetched['changelog_url'],
            'sha256' => $fetched['sha256'],
            'checked_at' => time(),
        ];

        if (class_exists('AP_Transient', false)) {
            try {
                AP_Transient::set(self::TRANSIENT_KEY, $store, $ttl, $db);
            } catch (Throwable) {
                // Cache write must never break admin.
            }
        }

        return [
            'ok' => $store['ok'],
            'version' => $store['version'],
            'download_url' => $store['download_url'],
            'changelog_url' => $store['changelog_url'],
            'sha256' => $store['sha256'],
            'checked_at' => $store['checked_at'],
            'from_cache' => false,
        ];
    }

    /**
     * Whether a newer core version is available (uses cache).
     */
    public static function hasUpdate(?AP_DB $db = null): bool
    {
        $info = self::getRemoteInfo($db);
        if (!$info['ok'] || $info['version'] === '') {
            return false;
        }

        return self::isNewer($info['version'], self::currentVersion());
    }

    /**
     * Build a safe HTML notice body (escaped text + safe links) when an update exists.
     * Returns empty string when no update or check disabled/failed.
     */
    public static function buildNoticeHtml(?AP_DB $db = null): string
    {
        $info = self::getRemoteInfo($db);
        if (!$info['ok'] || $info['version'] === '') {
            return '';
        }

        $current = self::currentVersion();
        if (!self::isNewer($info['version'], $current)) {
            return '';
        }

        $remoteEsc = self::esc($info['version']);
        $currentEsc = self::esc($current !== '' ? $current : 'unknown');

        $html = 'Update available: AgoraPress <strong>' . $remoteEsc . '</strong>';
        $html .= ' (you have ' . $currentEsc . ').';

        $links = [];
        // One-click path (admin Update Core screen).
        if (class_exists('AP_Admin', false)) {
            $updateUrl = AP_Admin::url('update-core.php');
            $links[] = '<a href="' . self::escAttr($updateUrl) . '"><strong>Update now</strong></a>';
        }
        $download = self::sanitizeUrl($info['download_url']);
        $changelog = self::sanitizeUrl($info['changelog_url']);
        if ($download !== '') {
            $links[] = '<a href="' . self::escAttr($download) . '" target="_blank" rel="noopener noreferrer">Download</a>';
        }
        if ($changelog !== '') {
            $links[] = '<a href="' . self::escAttr($changelog) . '" target="_blank" rel="noopener noreferrer">Changelog</a>';
        }
        if ($links !== []) {
            $html .= ' ' . implode(' · ', $links) . '.';
        }

        return $html;
    }

    /**
     * Queue an admin notice when a newer version is available.
     *
     * Only for users with update_core or manage_options. Never throws; never
     * surfaces fetch errors. Does not run on the front end.
     */
    public static function maybeQueueAdminNotice(?AP_DB $db = null, ?int $userId = null): bool
    {
        try {
            if (!self::isEnabled($db)) {
                return false;
            }

            if (!defined('AP_ADMIN') || !AP_ADMIN) {
                return false;
            }

            if (!class_exists('AP_Admin', false)) {
                return false;
            }

            if ($userId === null && function_exists('ap_get_current_user_id')) {
                $userId = (int) ap_get_current_user_id($db);
            }
            $userId = (int) ($userId ?? 0);
            if ($userId < 1 || !self::userCanSeeUpdate($userId, $db)) {
                return false;
            }

            $html = self::buildNoticeHtml($db);
            if ($html === '') {
                return false;
            }

            AP_Admin::addNotice($html, 'warning', false);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Force a fresh remote fetch (clears transient first).
     *
     * @return array{
     *   ok: bool,
     *   version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string,
     *   checked_at: int,
     *   from_cache: bool
     * }
     */
    public static function forceCheck(?AP_DB $db = null): array
    {
        if (class_exists('AP_Transient', false)) {
            try {
                AP_Transient::delete(self::TRANSIENT_KEY, $db);
            } catch (Throwable) {
                // Ignore.
            }
        }

        return self::getRemoteInfo($db, true);
    }

    /**
     * Inject HTTP transport (tests). Signature: (method, url) => result array.
     */
    public static function setHttpTransport(?callable $transport): void
    {
        self::$httpTransport = $transport;
    }

    /**
     * Clear injected transport (tests).
     */
    public static function resetHttpTransport(): void
    {
        self::$httpTransport = null;
    }

    /**
     * Documented privacy invariant: this checker never sends site identity.
     */
    public static function sendsSiteIdentity(): bool
    {
        return false;
    }

    /**
     * Parse version.json body into a normalized structure (pure; for tests).
     *
     * Expected keys: version (required), download_url / download / package (optional),
     * changelog_url / changelog (optional), sha256 / package_sha256 (optional).
     *
     * @return array{ok: bool, version: string, download_url: string, changelog_url: string, sha256: string}
     */
    public static function parseResponseBody(string $body): array
    {
        $empty = [
            'ok' => false,
            'version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
        ];

        $body = trim($body);
        if ($body === '') {
            return $empty;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $empty;
        }

        $version = self::normalizeVersion((string) ($decoded['version'] ?? $decoded['latest'] ?? ''));
        if ($version === '') {
            return $empty;
        }

        $download = (string) (
            $decoded['download_url']
            ?? $decoded['download']
            ?? $decoded['package']
            ?? $decoded['url']
            ?? ''
        );
        $changelog = (string) (
            $decoded['changelog_url']
            ?? $decoded['changelog']
            ?? ''
        );
        $sha256 = self::normalizeSha256((string) (
            $decoded['sha256']
            ?? $decoded['package_sha256']
            ?? $decoded['hash']
            ?? ''
        ));

        return [
            'ok' => true,
            'version' => $version,
            'download_url' => self::sanitizeUrl($download),
            'changelog_url' => self::sanitizeUrl($changelog),
            'sha256' => $sha256,
        ];
    }

    /**
     * @return array{
     *   ok: bool,
     *   version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string
     * }
     */
    private static function fetchRemote(): array
    {
        $fail = [
            'ok' => false,
            'version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
        ];

        try {
            $url = self::endpointUrl();
            // Refuse non-http(s) schemes.
            if (!preg_match('#^https?://#i', $url)) {
                return $fail;
            }

            $response = self::httpGet($url);
            if (!$response['ok'] || $response['body'] === '') {
                return $fail;
            }

            return self::parseResponseBody($response['body']);
        } catch (Throwable) {
            return $fail;
        }
    }

    /**
     * GET request only — no body, no identifying query string.
     *
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpGet(string $url): array
    {
        if (self::$httpTransport !== null) {
            $result = (self::$httpTransport)('GET', $url);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'error' => 'Invalid version-check transport response.',
                ];
            }

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        }

        if (function_exists('curl_init')) {
            return self::httpViaCurl($url);
        }

        return self::httpViaStream($url);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaCurl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Could not initialize HTTP client.',
            ];
        }

        $ua = 'AgoraPress/' . (self::currentVersion() !== '' ? self::currentVersion() : 'dev')
            . ' (VersionCheck; no-site-id)';

        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $ua,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => defined('CURLPROTO_HTTPS')
                ? (CURLPROTO_HTTPS | CURLPROTO_HTTP)
                : 3,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => $err !== '' ? $err : 'HTTP request failed.',
            ];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => (string) $body,
            'error' => $ok ? '' : 'HTTP ' . $status,
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaStream(string $url): array
    {
        $ua = 'AgoraPress/' . (self::currentVersion() !== '' ? self::currentVersion() : 'dev')
            . ' (VersionCheck; no-site-id)';
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: {$ua}\r\n",
                'timeout' => 8,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m) === 1) {
                    $status = (int) $m[1];
                    break;
                }
            }
        }

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => 'Could not reach the version endpoint.',
            ];
        }

        $ok = $status === 0 || ($status >= 200 && $status < 300);

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => (string) $body,
            'error' => $ok ? '' : 'HTTP ' . $status,
        ];
    }

    private static function normalizeVersion(string $version): string
    {
        $version = trim($version);
        // Strip leading "v" / "V".
        if ($version !== '' && ($version[0] === 'v' || $version[0] === 'V')) {
            $version = substr($version, 1);
        }
        // Allow SemVer core + optional pre-release / build (version_compare friendly).
        if ($version === '' || preg_match('/^[0-9]+(\.[0-9A-Za-z\-+.]+)*$/', $version) !== 1) {
            return '';
        }

        return $version;
    }

    /**
     * Normalize an optional package SHA-256 hex digest (empty if invalid).
     */
    private static function normalizeSha256(string $hash): string
    {
        $hash = strtolower(trim($hash));
        // Allow optional "sha256:" prefix.
        if (str_starts_with($hash, 'sha256:')) {
            $hash = substr($hash, 7);
        }
        if ($hash === '' || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            return '';
        }

        return $hash;
    }

    /**
     * Validate an absolute http(s) URL for storage (not HTML-escaped).
     */
    private static function sanitizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        // Reject control characters / spaces that break attribute context.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $url) === 1) {
            return '';
        }
        // Soft length cap.
        if (strlen($url) > 2048) {
            return '';
        }

        return $url;
    }

    private static function esc(string $text): string
    {
        if (function_exists('ap_esc_html')) {
            return ap_esc_html($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $text): string
    {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function userCanSeeUpdate(int $userId, ?AP_DB $db): bool
    {
        if (function_exists('ap_user_can')) {
            return ap_user_can($userId, 'update_core', null, $db)
                || ap_user_can($userId, 'manage_options', null, $db);
        }
        if (class_exists('AP_Roles', false)) {
            return AP_Roles::userCan($userId, 'update_core', null, $db)
                || AP_Roles::userCan($userId, 'manage_options', null, $db);
        }

        return false;
    }

    /**
     * @return array{
     *   ok: bool,
     *   version: string,
     *   download_url: string,
     *   changelog_url: string,
     *   sha256: string,
     *   checked_at: int,
     *   from_cache: bool
     * }
     */
    private static function emptyResult(bool $fromCache): array
    {
        return [
            'ok' => false,
            'version' => '',
            'download_url' => '',
            'changelog_url' => '',
            'sha256' => '',
            'checked_at' => 0,
            'from_cache' => $fromCache,
        ];
    }
}
