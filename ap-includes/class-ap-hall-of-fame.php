<?php

/**
 * Hall of Fame — fully voluntary domain registration (no telemetry).
 *
 * AgoraPress never phones home during install or normal operation. The only
 * install-counting path is this explicit opt-in: an administrator may register
 * their site domain with the project so it can appear in a public counter /
 * random rotation and may be withdrawn later. No anonymous installer pings.
 *
 * Payload sent on join/leave is domain (+ action/token) only — never user
 * identity, email, or other site diagnostics.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Voluntary Hall of Fame registration + local status helpers.
 */
class AP_Hall_Of_Fame
{
    /** Default public registration endpoint on the project site. */
    public const DEFAULT_ENDPOINT = 'https://agorapress.extrovertednerd.com/api/hall-of-fame';

    /** Option: joined | empty string when not a member. */
    public const OPTION_STATUS = 'hall_of_fame_status';

    /** Option: registered domain host (e.g. example.com). */
    public const OPTION_DOMAIN = 'hall_of_fame_domain';

    /** Option: withdrawal token returned by the project API (opaque string). */
    public const OPTION_TOKEN = 'hall_of_fame_token';

    /** Option: ISO-8601 UTC timestamp when joined. */
    public const OPTION_JOINED_AT = 'hall_of_fame_joined_at';

    /** Option: dashboard prompt dismissed without joining. */
    public const OPTION_DISMISSED = 'hall_of_fame_dismissed';

    public const STATUS_JOINED = 'joined';

    public const ACTION_JOIN = 'join';
    public const ACTION_LEAVE = 'leave';
    public const ACTION_DISMISS = 'dismiss';

    public const NONCE_JOIN = 'hall-of-fame-join';
    public const NONCE_LEAVE = 'hall-of-fame-leave';
    public const NONCE_DISMISS = 'hall-of-fame-dismiss';

    /**
     * Public donation / tip page on the project site.
     *
     * The admin-footer donate link is permanent and non-optional (constitution);
     * it never blocks features.
     */
    public const DONATION_URL = 'https://agorapress.extrovertednerd.com/donate';

    /** Public Hall of Fame page. */
    public const PUBLIC_PAGE_URL = 'https://agorapress.extrovertednerd.com/hall-of-fame';

    /**
     * Optional HTTP transport for tests:
     * function(string $method, string $url, array $payload): array{ok:bool,status:int,body:string,error:string}
     *
     * @var callable|null
     */
    private static $httpTransport = null;

    /**
     * Whether this site is registered in the Hall of Fame (local option).
     */
    public static function isJoined(?AP_DB $db = null): bool
    {
        $status = (string) self::getOption(self::OPTION_STATUS, '', $db);

        return $status === self::STATUS_JOINED;
    }

    /**
     * Local Hall of Fame status snapshot.
     *
     * @return array{
     *   joined: bool,
     *   domain: string,
     *   token: string,
     *   joined_at: string,
     *   dismissed: bool
     * }
     */
    public static function getStatus(?AP_DB $db = null): array
    {
        return [
            'joined' => self::isJoined($db),
            'domain' => (string) self::getOption(self::OPTION_DOMAIN, '', $db),
            'token' => (string) self::getOption(self::OPTION_TOKEN, '', $db),
            'joined_at' => (string) self::getOption(self::OPTION_JOINED_AT, '', $db),
            'dismissed' => (string) self::getOption(self::OPTION_DISMISSED, '0', $db) === '1',
        ];
    }

    /**
     * Resolve the domain to register from siteurl / home / AP_SITEURL.
     */
    public static function resolveDomain(?AP_DB $db = null): string
    {
        $candidates = [];
        if (function_exists('ap_get_option')) {
            $candidates[] = (string) ap_get_option('siteurl', '', $db);
            $candidates[] = (string) ap_get_option('home', '', $db);
        } elseif (class_exists('AP_Options', false)) {
            $candidates[] = (string) AP_Options::get('siteurl', '', $db);
            $candidates[] = (string) AP_Options::get('home', '', $db);
        }
        if (defined('AP_SITEURL') && is_string(AP_SITEURL)) {
            $candidates[] = (string) AP_SITEURL;
        }

        foreach ($candidates as $url) {
            $domain = self::normalizeDomain($url);
            if ($domain !== '') {
                return $domain;
            }
        }

        return '';
    }

    /**
     * Extract a registrable host from a URL or bare host string.
     *
     * Strips scheme, path, port, credentials, and leading "www.".
     */
    public static function normalizeDomain(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Bare host without scheme.
        if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)) {
            $value = 'https://' . $value;
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        // Drop trailing dots and brackets from IPv6 literals (not useful for HoF).
        $host = rtrim($host, '.');
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return '';
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        // Local / private hosts are allowed to join for testing but still validated.
        if ($host === '' || !preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/i', $host)) {
            return '';
        }
        // Reject pure numeric IPs for public Hall of Fame (domains only).
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return '';
        }

        return $host;
    }

    /**
     * Registration API endpoint (overridable via AP_HALL_OF_FAME_ENDPOINT).
     */
    public static function registrationEndpoint(): string
    {
        if (
            defined('AP_HALL_OF_FAME_ENDPOINT') && is_string(AP_HALL_OF_FAME_ENDPOINT)
            && AP_HALL_OF_FAME_ENDPOINT !== ''
        ) {
            return (string) AP_HALL_OF_FAME_ENDPOINT;
        }

        return self::DEFAULT_ENDPOINT;
    }

    /**
     * Build the minimal join/leave JSON payload (domain only + action/token).
     *
     * Intentionally excludes email, user id, site title, version, and any
     * environment fingerprint — this is not telemetry.
     *
     * @return array{action: string, domain: string, token?: string}
     */
    public static function buildPayload(string $action, string $domain, string $token = ''): array
    {
        $payload = [
            'action' => $action,
            'domain' => $domain,
        ];
        if ($token !== '') {
            $payload['token'] = $token;
        }

        return $payload;
    }

    /**
     * Whether the dashboard “Join the Hall of Fame” prompt should show.
     *
     * Admins with manage_options only; hidden when joined or dismissed.
     */
    public static function shouldShowPrompt(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || self::isJoined($db)) {
            return false;
        }
        if ((string) self::getOption(self::OPTION_DISMISSED, '0', $db) === '1') {
            return false;
        }
        if (!self::userCanManage($userId, $db)) {
            return false;
        }

        return true;
    }

    /**
     * Join the Hall of Fame (explicit admin action only).
     *
     * @param array<string, mixed> $input Typically $_POST.
     *
     * @return array{ok: bool, message_key: string, errors: list<string>, domain: string}
     */
    public static function join(int $userId, array $input, ?AP_DB $db = null): array
    {
        $empty = [
            'ok' => false,
            'message_key' => 'error',
            'errors' => [],
            'domain' => '',
        ];

        if (!self::userCanManage($userId, $db)) {
            $empty['errors'][] = 'You do not have permission to manage Hall of Fame registration.';

            return $empty;
        }

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!self::checkNonce($nonce, self::NONCE_JOIN, $userId)) {
            $empty['message_key'] = 'nonce';
            $empty['errors'][] = 'Security check failed. Please reload and try again.';

            return $empty;
        }

        if (self::isJoined($db)) {
            $empty['message_key'] = 'hall_of_fame_already';
            $empty['errors'][] = 'This site is already registered in the Hall of Fame.';
            $empty['domain'] = (string) self::getOption(self::OPTION_DOMAIN, '', $db);

            return $empty;
        }

        $domainInput = (string) ($input['domain'] ?? '');
        $domain = self::normalizeDomain($domainInput !== '' ? $domainInput : self::resolveDomain($db));
        if ($domain === '') {
            $empty['errors'][] = 'A valid public domain is required (set Site URL under General settings).';

            return $empty;
        }

        $payload = self::buildPayload(self::ACTION_JOIN, $domain);
        $response = self::httpRequest('POST', self::registrationEndpoint(), $payload);

        if (!$response['ok']) {
            $empty['message_key'] = 'hall_of_fame_remote';
            $empty['errors'][] = $response['error'] !== ''
                ? $response['error']
                : 'Could not reach the Hall of Fame service. Nothing was sent beyond this attempt.';
            $empty['domain'] = $domain;

            return $empty;
        }

        $token = self::extractTokenFromBody($response['body']);
        if ($token === '') {
            // Server accepted but no token: generate a local withdrawal handle.
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Throwable) {
                $token = hash('sha256', $domain . microtime(true));
            }
        }

        $joinedAt = gmdate('c');
        self::updateOption(self::OPTION_STATUS, self::STATUS_JOINED, $db);
        self::updateOption(self::OPTION_DOMAIN, $domain, $db);
        self::updateOption(self::OPTION_TOKEN, $token, $db);
        self::updateOption(self::OPTION_JOINED_AT, $joinedAt, $db);
        self::updateOption(self::OPTION_DISMISSED, '0', $db);

        return [
            'ok' => true,
            'message_key' => 'hall_of_fame_joined',
            'errors' => [],
            'domain' => $domain,
        ];
    }

    /**
     * Withdraw Hall of Fame registration (explicit admin action only).
     *
     * @param array<string, mixed> $input Typically $_POST.
     *
     * @return array{ok: bool, message_key: string, errors: list<string>, domain: string}
     */
    public static function leave(int $userId, array $input, ?AP_DB $db = null): array
    {
        $empty = [
            'ok' => false,
            'message_key' => 'error',
            'errors' => [],
            'domain' => '',
        ];

        if (!self::userCanManage($userId, $db)) {
            $empty['errors'][] = 'You do not have permission to manage Hall of Fame registration.';

            return $empty;
        }

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!self::checkNonce($nonce, self::NONCE_LEAVE, $userId)) {
            $empty['message_key'] = 'nonce';
            $empty['errors'][] = 'Security check failed. Please reload and try again.';

            return $empty;
        }

        if (!self::isJoined($db)) {
            $empty['message_key'] = 'hall_of_fame_not_joined';
            $empty['errors'][] = 'This site is not registered in the Hall of Fame.';

            return $empty;
        }

        $domain = (string) self::getOption(self::OPTION_DOMAIN, '', $db);
        $token = (string) self::getOption(self::OPTION_TOKEN, '', $db);
        if ($domain === '') {
            $domain = self::resolveDomain($db);
        }

        if ($domain !== '') {
            $payload = self::buildPayload(self::ACTION_LEAVE, $domain, $token);
            $response = self::httpRequest('POST', self::registrationEndpoint(), $payload);
            // Even if remote fails, clear local membership so the admin can re-join later.
            // Surface a warning via message_key when remote failed.
            $remoteOk = $response['ok'];
        } else {
            $remoteOk = true;
        }

        self::clearMembership($db);

        return [
            'ok' => true,
            'message_key' => $remoteOk ? 'hall_of_fame_left' : 'hall_of_fame_left_local',
            'errors' => [],
            'domain' => $domain,
        ];
    }

    /**
     * Dismiss the dashboard Hall of Fame prompt (does not send any data).
     *
     * @param array<string, mixed> $input Typically $_POST.
     *
     * @return array{ok: bool, message_key: string, errors: list<string>}
     */
    public static function dismissPrompt(int $userId, array $input, ?AP_DB $db = null): array
    {
        $empty = [
            'ok' => false,
            'message_key' => 'error',
            'errors' => [],
        ];

        if (!self::userCanManage($userId, $db)) {
            $empty['errors'][] = 'You do not have permission to dismiss this notice.';

            return $empty;
        }

        $nonce = (string) ($input['_ap_nonce'] ?? '');
        if (!self::checkNonce($nonce, self::NONCE_DISMISS, $userId)) {
            $empty['message_key'] = 'nonce';
            $empty['errors'][] = 'Security check failed. Please reload and try again.';

            return $empty;
        }

        self::updateOption(self::OPTION_DISMISSED, '1', $db);

        return [
            'ok' => true,
            'message_key' => 'hall_of_fame_dismissed',
            'errors' => [],
        ];
    }

    /**
     * Inject HTTP transport for unit tests (null restores default cURL/stream).
     *
     * @param callable|null $transport
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
     * Whether any automatic/anonymous install counting exists in core.
     *
     * Always false — documents the product invariant for tests and greps.
     */
    public static function usesInstallerPings(): bool
    {
        return false;
    }

    /**
     * Whether core telemetry is enabled by this feature.
     *
     * Always false — Hall of Fame is voluntary domain registration only.
     */
    public static function isTelemetry(): bool
    {
        return false;
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpRequest(string $method, string $url, array $payload): array
    {
        if (self::$httpTransport !== null) {
            $result = (self::$httpTransport)($method, $url, $payload);
            if (!is_array($result)) {
                return [
                    'ok' => false,
                    'status' => 0,
                    'body' => '',
                    'error' => 'Invalid Hall of Fame transport response.',
                ];
            }

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'status' => (int) ($result['status'] ?? 0),
                'body' => (string) ($result['body'] ?? ''),
                'error' => (string) ($result['error'] ?? ''),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Could not encode registration payload.',
            ];
        }

        if (function_exists('curl_init')) {
            return self::httpViaCurl($method, $url, $json);
        }

        return self::httpViaStream($method, $url, $json);
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaCurl(string $method, string $url, string $jsonBody): array
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

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: AgoraPress-HallOfFame/' . (defined('AP_VERSION') ? (string) AP_VERSION : 'dev'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 8,
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
            'error' => $ok ? '' : self::errorFromHttpBody((string) $body, $status),
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: string, error: string}
     */
    private static function httpViaStream(string $method, string $url, string $jsonBody): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $jsonBody,
                'timeout' => 12,
                'ignore_errors' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        // Populated by the HTTP stream wrapper after file_get_contents().
        $status = self::statusFromHttpHeaders($http_response_header);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => 'Could not reach the Hall of Fame service.',
            ];
        }

        $ok = $status >= 200 && $status < 300;

        return [
            'ok' => $ok,
            'status' => $status,
            'body' => (string) $body,
            'error' => $ok ? '' : self::errorFromHttpBody((string) $body, $status),
        ];
    }

    /**
     * Parse status code from HTTP stream response headers.
     *
     * @param list<string> $headers Lines from $http_response_header after file_get_contents().
     */
    private static function statusFromHttpHeaders(array $headers): int
    {
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', (string) $line, $m) === 1) {
                return (int) $m[1];
            }
        }

        return 0;
    }

    private static function errorFromHttpBody(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $msg = (string) ($decoded['message'] ?? $decoded['error'] ?? '');
            if ($msg !== '') {
                return $msg;
            }
        }

        return 'Hall of Fame service returned HTTP ' . $status . '.';
    }

    private static function extractTokenFromBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return '';
        }
        $token = (string) ($decoded['token'] ?? $decoded['withdrawal_token'] ?? '');
        $token = trim($token);
        if ($token === '' || strlen($token) > 255) {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9_\-.]+$/', $token) !== 1) {
            return '';
        }

        return $token;
    }

    private static function clearMembership(?AP_DB $db): void
    {
        self::updateOption(self::OPTION_STATUS, '', $db);
        self::updateOption(self::OPTION_DOMAIN, '', $db);
        self::updateOption(self::OPTION_TOKEN, '', $db);
        self::updateOption(self::OPTION_JOINED_AT, '', $db);
    }

    private static function userCanManage(int $userId, ?AP_DB $db): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (function_exists('ap_user_can')) {
            return ap_user_can($userId, 'manage_options', null, $db);
        }
        if (class_exists('AP_Roles', false)) {
            return AP_Roles::userCan($userId, 'manage_options', null, $db);
        }

        return false;
    }

    private static function checkNonce(string $nonce, string $action, int $userId): bool
    {
        if (function_exists('ap_check_nonce')) {
            return ap_check_nonce($nonce, $action, $userId > 0 ? $userId : null);
        }
        if (class_exists('AP_Nonce', false)) {
            return AP_Nonce::check($nonce, $action, $userId > 0 ? $userId : null);
        }

        return false;
    }

    private static function getOption(string $name, mixed $default, ?AP_DB $db): mixed
    {
        if (function_exists('ap_get_option')) {
            return ap_get_option($name, $default, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        return $default;
    }

    private static function updateOption(string $name, mixed $value, ?AP_DB $db): bool
    {
        if (function_exists('ap_update_option')) {
            return (bool) ap_update_option($name, $value, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::update($name, $value, $db);
        }

        return false;
    }
}
