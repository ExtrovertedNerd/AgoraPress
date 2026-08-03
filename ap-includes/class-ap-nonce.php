<?php

/**
 * AgoraPress nonces — CSRF tokens for state-changing forms.
 *
 * Tokens are HMAC-SHA256 of action|user_id|tick using AP_NONCE_KEY + AP_NONCE_SALT.
 * Each tick lasts {@see AP_Nonce::TICK_SECONDS}; tokens remain valid for two ticks
 * (current + previous) so forms submitted near a boundary still verify.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Nonce create / verify helpers.
 */
class AP_Nonce
{
    /** Lifetime of one nonce tick in seconds (12 hours). */
    public const TICK_SECONDS = 43200;

    /**
     * Create a nonce for an action (and optional user id).
     *
     * When $userId is null, the current session user is used (0 if logged out).
     */
    public static function create(string $action = '-1', ?int $userId = null): string
    {
        $uid = $userId ?? self::resolveUserId();
        $tick = self::tick();

        return self::hash($tick, $action, $uid);
    }

    /**
     * Verify a nonce. Returns 1 if valid for the current tick, 2 for the previous
     * tick, or false when invalid / empty.
     *
     * @return int|false
     */
    public static function verify(string $nonce, string $action = '-1', ?int $userId = null): int|false
    {
        $nonce = trim($nonce);
        if ($nonce === '' || strlen($nonce) < 10) {
            return false;
        }

        $uid = $userId ?? self::resolveUserId();
        $tick = self::tick();

        // Current tick.
        $expected = self::hash($tick, $action, $uid);
        if (hash_equals($expected, $nonce)) {
            return 1;
        }

        // Previous tick (grace window).
        $expectedPrev = self::hash($tick - 1, $action, $uid);
        if (hash_equals($expectedPrev, $nonce)) {
            return 2;
        }

        return false;
    }

    /**
     * Whether a nonce is valid for the action (boolean convenience).
     */
    public static function check(string $nonce, string $action = '-1', ?int $userId = null): bool
    {
        return self::verify($nonce, $action, $userId) !== false;
    }

    /**
     * HTML hidden input for a form field (default name `_ap_nonce`).
     */
    public static function field(
        string $action = '-1',
        string $name = '_ap_nonce',
        bool $referer = true,
        ?int $userId = null
    ): string {
        $nonce = self::create($action, $userId);
        $nameAttr = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $valueAttr = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<input type="hidden" id="' . $nameAttr . '" name="' . $nameAttr
            . '" value="' . $valueAttr . '" />' . "\n";

        if ($referer) {
            $ref = self::requestUri();
            if ($ref !== '') {
                $html .= '<input type="hidden" name="_ap_http_referer" value="'
                    . htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />' . "\n";
            }
        }

        return $html;
    }

    /**
     * Append a nonce query argument to a URL (for GET state-changing links).
     *
     * @param string $url     Absolute or relative URL.
     * @param string $action  Nonce action string.
     * @param string $name    Query parameter name (default `_ap_nonce`).
     * @param int|null $userId Optional user id; null = current user.
     */
    public static function url(
        string $url,
        string $action = '-1',
        string $name = '_ap_nonce',
        ?int $userId = null
    ): string {
        $nonce = self::create($action, $userId);
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . rawurlencode($name) . '=' . rawurlencode($nonce);
    }

    /**
     * Read a nonce from a request bag (POST preferred, then GET/REQUEST) and verify.
     *
     * @param array<string, mixed> $request Typically $_REQUEST / $_POST / $_GET.
     * @return int|false Same as {@see verify()}.
     */
    public static function verifyRequest(
        array $request,
        string $action = '-1',
        string $name = '_ap_nonce',
        ?int $userId = null
    ): int|false {
        $nonce = '';
        if (isset($request[$name]) && is_scalar($request[$name])) {
            $nonce = (string) $request[$name];
        } elseif (isset($request['_wpnonce']) && is_scalar($request['_wpnonce'])) {
            // Accept classic WP field name as a compatibility alias.
            $nonce = (string) $request['_wpnonce'];
        }

        return self::verify($nonce, $action, $userId);
    }

    /**
     * Current tick number (floor of unix time / TICK_SECONDS).
     */
    public static function tick(): int
    {
        return (int) floor(time() / self::TICK_SECONDS);
    }

    /**
     * @internal
     */
    private static function hash(int $tick, string $action, int $userId): string
    {
        $key = self::keyMaterial();
        $data = $tick . '|' . $action . '|' . $userId;
        // 10-byte hex (20 chars) — short enough for URLs, long enough for CSRF.
        return substr(hash_hmac('sha256', $data, $key), -12);
    }

    private static function keyMaterial(): string
    {
        $key = defined('AP_NONCE_KEY') ? (string) AP_NONCE_KEY : '';
        $salt = defined('AP_NONCE_SALT') ? (string) AP_NONCE_SALT : '';

        return $key . $salt;
    }

    private static function resolveUserId(): int
    {
        if (function_exists('ap_get_current_user_id')) {
            return ap_get_current_user_id();
        }
        if (class_exists('AP_Session', false)) {
            $user = AP_Session::getCurrentUser();

            return $user !== null ? $user->ID : 0;
        }

        return 0;
    }

    private static function requestUri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        if ($uri === '') {
            return '';
        }

        // Strip scheme/host if a full URL slipped in; keep path + query only.
        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $parts = parse_url($uri);
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';

            return $path . $query;
        }

        return $uri;
    }
}
