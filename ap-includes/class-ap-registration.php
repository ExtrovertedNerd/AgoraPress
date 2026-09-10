<?php

/**
 * AgoraPress public registration, email verification, and password reset.
 *
 * Uses existing users columns:
 * - user_status: 0 = active, {@see self::STATUS_PENDING} = awaiting email verification
 * - user_activation_key: purpose:timestamp:hmac (never store the raw URL key)
 *
 * Gated by options:
 * - users_can_register (0/1)
 * - require_email_verification (0/1, default 1)
 * - registration_captcha (off|math|guard, default off) — optional visible anti-spam
 * - reserved_usernames (optional extras, one login per line)
 * - default_role (seeded by installer)
 *
 * When users_can_register is on, public register() always enforces a hidden
 * honeypot (`ap_hp`), a ~3s minimum fill time, and a short-lived form ticket
 * issued on GET of the register form. Naked POSTs fail closed. Staff/system
 * logins on {@see self::RESERVED_LOGINS}, extras from
 * {@see self::OPTION_RESERVED_USERNAMES}, and names added by filter
 * `ap_reserved_usernames` are rejected case-insensitively with
 * {@see self::USERNAME_UNAVAILABLE_MESSAGE} (the form does not say a name
 * is reserved). ACP / CLI / Users → Add may still create those accounts.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Registration / verification / password-reset workflows.
 */
class AP_Registration
{
    /** user_status while waiting for email confirmation. */
    public const STATUS_PENDING = 1;

    /** Activation / reset key lifetime (24 hours). */
    public const KEY_TTL = 86400;

    /** Math CAPTCHA challenge lifetime (30 minutes). */
    public const CAPTCHA_TTL = 1800;

    /** Minimum seconds between password-reset emails for the same account. */
    public const RESET_COOLDOWN = 60;

    /** Minimum seconds between public verification resends for the same account. */
    public const VERIFY_RESEND_COOLDOWN = 60;

    /** Key purpose: email verification after registration. */
    public const PURPOSE_ACTIVATE = 'activate';

    /** Key purpose: password reset. */
    public const PURPOSE_RESET = 'reset';

    /** CAPTCHA mode: disabled (default). */
    public const CAPTCHA_OFF = 'off';

    /** CAPTCHA mode: built-in arithmetic challenge. */
    public const CAPTCHA_MATH = 'math';

    /**
     * CAPTCHA mode: first-party checkbox card + signed token.
     *
     * JavaScript proof-of-work is progressive enhancement; a short typed code
     * still works with no JS. No third-party widget.
     */
    public const CAPTCHA_GUARD = 'guard';

    /** Leading hex zeros required for the guard SHA-256 proof-of-work. */
    public const GUARD_POW_DIFFICULTY = 3;

    /** Prefix on captcha_answer when the client submits a PoW instead of the fallback code. */
    public const GUARD_POW_PREFIX = 'pow:';

    /** Length of the no-JS fallback code (Crockford-like alphabet, no 0/O/1/I). */
    public const GUARD_CODE_LENGTH = 4;

    /** Minimum seconds a public registration form must stay open before POST. */
    public const MIN_FILL_SECONDS = 3;

    /** Form-ticket lifetime (30 minutes). */
    public const FORM_TICKET_TTL = 1800;

    /** Hidden honeypot field (must stay empty). */
    public const FIELD_HONEYPOT = 'ap_hp';

    /** Hidden form-ticket field issued on GET. */
    public const FIELD_FORM_TICKET = 'ap_form_ticket';

    /** Guard checkbox (must be "1" when mode is guard). */
    public const FIELD_GUARD_ACK = 'ap_guard_ack';

    /**
     * Staff / system logins public self-register cannot take.
     *
     * Match is case-insensitive. ACP Users → Add, CLI, and {@see AP_User::create()}
     * may still create these accounts.
     *
     * @var list<string>
     */
    public const RESERVED_LOGINS = [
        'root',
        'admin',
        'administrator',
        'administrators',
        'adm',
        'mod',
        'moderator',
        'moderators',
        'mods',
        'webmaster',
        'postmaster',
        'hostmaster',
        'support',
        'security',
        'abuse',
        'staff',
        'superadmin',
        'sysadmin',
        'guest',
        'nobody',
        'noreply',
        'no-reply',
        'www',
        'mail',
        'system',
        'owner',
        'ap-admin',
        'agora',
        'agorapress',
    ];

    /**
     * Option: extra reserved logins for public register (one name per line).
     *
     * Locked {@see self::RESERVED_LOGINS} are not stored here. ACP / CLI /
     * Users → Add may still create any reserved name.
     */
    public const OPTION_RESERVED_USERNAMES = 'reserved_usernames';

    /**
     * Public-register copy when a login is reserved or already taken.
     *
     * Same wording for both so the form does not advertise that a name is reserved.
     */
    public const USERNAME_UNAVAILABLE_MESSAGE = 'That username is not available.';

    /**
     * Whether anyone may register (option users_can_register).
     */
    public static function usersCanRegister(?AP_DB $db = null): bool
    {
        return self::optionIsTruthy('users_can_register', false, $db);
    }

    /**
     * Whether new public registrations must verify email before login.
     * Defaults to true when the option is missing (secure default).
     */
    public static function requireEmailVerification(?AP_DB $db = null): bool
    {
        return self::optionIsTruthy('require_email_verification', true, $db);
    }

    /**
     * Registration CAPTCHA / anti-spam mode.
     *
     * Values: {@see self::CAPTCHA_OFF} (default), {@see self::CAPTCHA_MATH},
     * {@see self::CAPTCHA_GUARD}. Plugins may filter via
     * `ap_registration_captcha_mode` when hooks are loaded.
     */
    public static function captchaMode(?AP_DB $db = null): string
    {
        $raw = strtolower(trim((string) self::readOption('registration_captcha', self::CAPTCHA_OFF, $db)));
        if ($raw === '' || $raw === '0' || $raw === 'false' || $raw === 'no' || $raw === 'disabled') {
            $raw = self::CAPTCHA_OFF;
        }
        if ($raw === '1' || $raw === 'true' || $raw === 'yes' || $raw === 'on') {
            $raw = self::CAPTCHA_MATH;
        }

        // Built-in modes: off, math, guard. Unknown strings stay as-is so a
        // plugin can supply a custom mode via ap_registration_captcha_mode and
        // ap_registration_verify_captcha. The settings sanitizer still
        // collapses unknown saved values to off.

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_registration_captcha_mode', $raw, $db);
            if (is_string($filtered) && $filtered !== '') {
                $raw = strtolower(trim($filtered));
            }
        }

        return $raw !== '' ? $raw : self::CAPTCHA_OFF;
    }

    /**
     * Whether optional registration anti-spam (CAPTCHA or equivalent) is active.
     */
    public static function isCaptchaEnabled(?AP_DB $db = null): bool
    {
        return self::captchaMode($db) !== self::CAPTCHA_OFF;
    }

    /**
     * Issue a short-lived registration form ticket (GET of the public form).
     *
     * The token encodes the issue time so POST can enforce a minimum fill
     * interval and reject naked submissions that never loaded the form.
     *
     * @param int|null $issuedAt Unix timestamp. Tests pass a past time to
     *                           satisfy {@see self::MIN_FILL_SECONDS} without sleeping.
     *
     * @return array{
     *     token: string,
     *     issued_at: int,
     *     field: string,
     *     ttl: int,
     *     min_fill: int
     * }
     */
    public static function createFormTicket(?int $issuedAt = null): array
    {
        $ts = $issuedAt ?? time();
        if ($ts < 1) {
            $ts = time();
        }

        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $nonce = bin2hex(substr(hash('sha256', uniqid((string) $ts, true), true), 0, 8));
        }

        return [
            'token' => self::encodeFormTicket($ts, $nonce),
            'issued_at' => $ts,
            'field' => self::FIELD_FORM_TICKET,
            'ttl' => self::FORM_TICKET_TTL,
            'min_fill' => self::MIN_FILL_SECONDS,
        ];
    }

    /**
     * Ticket to embed in the public register form (GET, or re-display after POST).
     *
     * Reuses $postedToken when HMAC and TTL are still valid so a validation
     * error does not restart the minimum-fill clock. Invalid or missing tokens
     * get a freshly issued ticket (min-fill starts from this render).
     *
     * @return array{
     *     token: string,
     *     issued_at: int,
     *     field: string,
     *     ttl: int,
     *     min_fill: int
     * }
     */
    public static function formTicketForDisplay(?string $postedToken = null): array
    {
        $postedToken = trim((string) $postedToken);
        if ($postedToken !== '') {
            $parsed = self::decodeFormTicket($postedToken);
            if ($parsed !== null) {
                return [
                    'token' => $postedToken,
                    'issued_at' => $parsed['ts'],
                    'field' => self::FIELD_FORM_TICKET,
                    'ttl' => self::FORM_TICKET_TTL,
                    'min_fill' => self::MIN_FILL_SECONDS,
                ];
            }
        }

        return self::createFormTicket();
    }

    /**
     * Always-on public-register gate: empty honeypot, valid form ticket, min fill.
     *
     * Failures use a generic error so bots cannot tell which check failed.
     *
     * @param array<string, mixed> $data
     * @param AP_DB|null $db Unused; same signature as {@see self::verifyCaptcha()}.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function verifyFormGate(array $data, ?AP_DB $db = null): array
    {
        $generic = self::genericFormFailure();

        $honeypot = trim((string) ($data[self::FIELD_HONEYPOT] ?? ''));
        $website = trim((string) ($data['website'] ?? ''));
        if ($honeypot !== '' || $website !== '') {
            return $generic;
        }

        $token = trim((string) ($data[self::FIELD_FORM_TICKET] ?? $data['ap_ft'] ?? ''));
        $parsed = self::decodeFormTicket($token);
        if ($parsed === null) {
            return $generic;
        }

        $age = time() - $parsed['ts'];
        if ($age < self::MIN_FILL_SECONDS) {
            return $generic;
        }

        return ['ok' => true, 'errors' => []];
    }

    /**
     * Whether a login is reserved for public self-register.
     *
     * Comparison is case-insensitive after {@see AP_User::sanitizeUserLogin()}.
     * Empty / unsanitizable input is not reserved. Includes the locked list,
     * per-site extras, and filter `ap_reserved_usernames`.
     */
    public static function isReservedLogin(string $login, ?AP_DB $db = null): bool
    {
        if (class_exists('AP_User', false)) {
            $login = AP_User::sanitizeUserLogin($login);
        } else {
            $login = trim($login);
        }
        if ($login === '') {
            return false;
        }

        return isset(self::reservedLoginLookup($db)[strtolower($login)]);
    }

    /**
     * Effective reserved logins for public register.
     *
     * Locked names always remain. Option extras and filter `ap_reserved_usernames`
     * may only add names.
     *
     * @return list<string>
     */
    public static function reservedLogins(?AP_DB $db = null): array
    {
        $names = self::RESERVED_LOGINS;
        foreach (self::extraReservedLogins($db) as $extra) {
            $names[] = $extra;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_reserved_usernames', $names, $db);
            if (is_array($filtered)) {
                $names = $filtered;
            }
        }

        return self::normalizeReservedLoginList($names, true);
    }

    /**
     * Extra reserved logins from Settings → General (`reserved_usernames`).
     *
     * @return list<string>
     */
    public static function extraReservedLogins(?AP_DB $db = null): array
    {
        $raw = (string) self::readOption(self::OPTION_RESERVED_USERNAMES, '', $db);

        return self::parseReservedUsernameList($raw);
    }

    /**
     * Parse a textarea of extra reserved logins (one name per line).
     *
     * Locked {@see self::RESERVED_LOGINS} are dropped so the option stores
     * extras only. Match is case-insensitive.
     *
     * @return list<string>
     */
    public static function parseReservedUsernameList(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $names = self::normalizeReservedLoginList(explode("\n", $raw), false);
        if ($names === []) {
            return [];
        }

        $locked = [];
        foreach (self::RESERVED_LOGINS as $login) {
            $locked[strtolower($login)] = true;
        }

        $extras = [];
        foreach ($names as $name) {
            if (!isset($locked[strtolower($name)])) {
                $extras[] = $name;
            }
        }

        return $extras;
    }

    /**
     * Public-register error for a reserved or taken login.
     *
     * Does not say the name is reserved. ACP / CLI uniqueness copy stays
     * “already registered” via {@see AP_User::create()}.
     */
    public static function reservedLoginUnavailableMessage(): string
    {
        return self::USERNAME_UNAVAILABLE_MESSAGE;
    }

    /**
     * Rewrite staff uniqueness / reserved copy for the public register form.
     *
     * Taken logins and reserved logins use the same wording so the form does
     * not advertise that a name is reserved. Other errors (email, password,
     * closed registration) pass through.
     *
     * @param list<string> $errors
     *
     * @return list<string>
     */
    public static function publicUsernameErrors(array $errors): array
    {
        $unavailable = self::reservedLoginUnavailableMessage();
        $out = [];
        foreach ($errors as $error) {
            if (!is_string($error) || $error === '') {
                continue;
            }
            $trimmed = trim($error);
            if (
                strcasecmp($trimmed, 'That username is already registered.') === 0
                || preg_match('/\breserved\b/i', $trimmed) === 1
            ) {
                $out[] = $unavailable;
                continue;
            }
            $out[] = $error;
        }

        return $out;
    }

    /**
     * @param array<mixed> $names
     *
     * @return list<string>
     */
    private static function normalizeReservedLoginList(array $names, bool $keepLocked): array
    {
        $out = [];
        $seen = [];

        if ($keepLocked) {
            foreach (self::RESERVED_LOGINS as $locked) {
                $key = strtolower($locked);
                $seen[$key] = true;
                $out[] = $locked;
            }
        }

        foreach ($names as $name) {
            if (!is_scalar($name)) {
                continue;
            }
            $login = trim((string) $name);
            if ($login === '') {
                continue;
            }
            if (class_exists('AP_User', false)) {
                $login = AP_User::sanitizeUserLogin($login);
            }
            if ($login === '') {
                continue;
            }
            $key = strtolower($login);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $login;
        }

        return $out;
    }

    /**
     * @return array<string, true> lowercase login => true
     */
    private static function reservedLoginLookup(?AP_DB $db = null): array
    {
        $lookup = [];
        foreach (self::reservedLogins($db) as $name) {
            $lookup[strtolower($name)] = true;
        }

        return $lookup;
    }

    /**
     * Create a built-in math challenge for the registration form.
     *
     * @return array{
     *     mode: string,
     *     legend: string,
     *     a: int,
     *     b: int,
     *     prompt: string,
     *     token: string,
     *     field_answer: string,
     *     field_token: string,
     *     field_honeypot: string
     * }
     */
    public static function createMathChallenge(): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $ts = time();
        $token = self::encodeCaptchaToken($a, $b, $ts);

        return [
            'mode' => self::CAPTCHA_MATH,
            'legend' => 'Human check',
            'a' => $a,
            'b' => $b,
            'prompt' => sprintf('What is %d + %d?', $a, $b),
            'token' => $token,
            'field_answer' => 'captcha_answer',
            'field_token' => 'captcha_token',
            'field_honeypot' => self::FIELD_HONEYPOT,
        ];
    }

    /**
     * Create a first-party guard challenge (checkbox card + signed token).
     *
     * `captcha_answer` is either the visible fallback code (no JS) or
     * {@see self::GUARD_POW_PREFIX} plus a SHA-256 proof (JS enhancement).
     *
     * @return array{
     *     mode: string,
     *     legend: string,
     *     prompt: string,
     *     fallback_code: string,
     *     fallback_prompt: string,
     *     difficulty: int,
     *     token: string,
     *     field_answer: string,
     *     field_token: string,
     *     field_ack: string,
     *     field_honeypot: string
     * }
     */
    public static function createGuardChallenge(): array
    {
        $ts = time();
        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $nonce = bin2hex(substr(hash('sha256', uniqid((string) $ts, true), true), 0, 8));
        }
        $difficulty = self::GUARD_POW_DIFFICULTY;
        $code = self::randomGuardCode();
        $token = self::encodeGuardToken($ts, $nonce, $difficulty, $code);

        return [
            'mode' => self::CAPTCHA_GUARD,
            'legend' => 'Human check',
            'prompt' => 'I am a person',
            'fallback_code' => $code,
            'fallback_prompt' => sprintf('Type this code to continue: %s', $code),
            'difficulty' => $difficulty,
            'token' => $token,
            'field_answer' => 'captcha_answer',
            'field_token' => 'captcha_token',
            'field_ack' => self::FIELD_GUARD_ACK,
            'field_honeypot' => self::FIELD_HONEYPOT,
        ];
    }

    /**
     * Build challenge data for the current CAPTCHA mode (empty when off).
     *
     * @return array<string, mixed>
     */
    public static function createCaptchaChallenge(?AP_DB $db = null): array
    {
        $mode = self::captchaMode($db);
        if ($mode === self::CAPTCHA_OFF) {
            return ['mode' => self::CAPTCHA_OFF];
        }

        if ($mode === self::CAPTCHA_MATH) {
            $challenge = self::createMathChallenge();
        } elseif ($mode === self::CAPTCHA_GUARD) {
            $challenge = self::createGuardChallenge();
        } else {
            $challenge = [
                'mode' => $mode,
                'field_answer' => 'captcha_answer',
                'field_token' => 'captcha_token',
                'field_honeypot' => self::FIELD_HONEYPOT,
            ];
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_registration_captcha_challenge', $challenge, $mode, $db);
            if (is_array($filtered)) {
                return $filtered;
            }
        }

        return $challenge;
    }

    /**
     * Verify CAPTCHA from registration form data.
     *
     * Expected keys when math mode is on: captcha_answer, captcha_token.
     * Guard mode: captcha_token, ap_guard_ack=1, and captcha_answer as either
     * the fallback code or {@see self::GUARD_POW_PREFIX} plus a valid proof.
     * Honeypot `ap_hp` is also rejected here when CAPTCHA is on (defense in
     * depth; {@see self::verifyFormGate()} always checks it on public register).
     * Always succeeds when CAPTCHA mode is off (the form gate still runs).
     * Plugins may override via `ap_registration_verify_captcha`.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function verifyCaptcha(array $data, ?AP_DB $db = null): array
    {
        $mode = self::captchaMode($db);
        $result = ['ok' => true, 'errors' => []];

        if ($mode === self::CAPTCHA_OFF) {
            if (function_exists('ap_apply_filters')) {
                $filtered = ap_apply_filters('ap_registration_verify_captcha', $result, $data, $mode, $db);
                if (is_array($filtered) && array_key_exists('ok', $filtered)) {
                    return [
                        'ok' => (bool) $filtered['ok'],
                        'errors' => self::normalizeErrorList($filtered['errors'] ?? []),
                    ];
                }
            }

            return $result;
        }

        // Honeypot: bots often fill hidden "website" fields.
        $honeypot = trim((string) ($data[self::FIELD_HONEYPOT] ?? ''));
        $website = trim((string) ($data['website'] ?? ''));
        if ($honeypot !== '' || $website !== '') {
            $result = self::genericFormFailure();
        } elseif ($mode === self::CAPTCHA_MATH) {
            $answerRaw = trim((string) ($data['captcha_answer'] ?? ''));
            $token = trim((string) ($data['captcha_token'] ?? ''));
            if ($answerRaw === '' || $token === '') {
                $result = [
                    'ok' => false,
                    'errors' => ['Please answer the anti-spam question.'],
                ];
            } elseif (!is_numeric($answerRaw)) {
                $result = [
                    'ok' => false,
                    'errors' => ['Incorrect anti-spam answer. Please try again.'],
                ];
            } else {
                $parsed = self::decodeCaptchaToken($token);
                if ($parsed === null) {
                    $result = [
                        'ok' => false,
                        'errors' => ['The anti-spam check expired. Please try again.'],
                    ];
                } else {
                    $expected = $parsed['a'] + $parsed['b'];
                    if ((int) $answerRaw !== $expected) {
                        $result = [
                            'ok' => false,
                            'errors' => ['Incorrect anti-spam answer. Please try again.'],
                        ];
                    }
                }
            }
        } elseif ($mode === self::CAPTCHA_GUARD) {
            $result = self::verifyGuardResponse($data);
        } else {
            // Unknown / plugin modes: fail closed unless a filter approves.
            $result = [
                'ok' => false,
                'errors' => ['Anti-spam verification is not configured correctly.'],
            ];
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_registration_verify_captcha', $result, $data, $mode, $db);
            if (is_array($filtered) && array_key_exists('ok', $filtered)) {
                return [
                    'ok' => (bool) $filtered['ok'],
                    'errors' => self::normalizeErrorList($filtered['errors'] ?? []),
                ];
            }
        }

        return $result;
    }

    /**
     * Register a new public account.
     *
     * Required keys: user_login, user_email, user_pass (or password).
     * Optional: display_name.
     * Always required when public registration is open: empty `ap_hp`,
     * `ap_form_ticket` issued on GET of the form, and ~3s elapsed since issue.
     * When CAPTCHA is enabled also: captcha_answer, captcha_token
     * (and ap_guard_ack=1 in guard mode).
     *
     * When email verification is required the account is created with
     * STATUS_PENDING and a verification email is sent. When not required the
     * account is active immediately (no activation email).
     *
     * If verification is required and {@see AP_Mail::send()} fails, the pending
     * user is kept (`ok` remains true) and `mail_sent` is false. Callers must
     * not claim the message went out. `mail_sent` is true only when a
     * verification message was actually accepted by {@see AP_Mail::send()}.
     *
     * @param array<string, mixed> $data
     *
     * @return array{
     *     ok: bool,
     *     id: int,
     *     errors: list<string>,
     *     user: ?AP_User,
     *     needs_verification: bool,
     *     plain_key: string,
     *     mail_sent: bool
     * }
     */
    public static function register(array $data, ?AP_DB $db = null): array
    {
        $empty = [
            'ok' => false,
            'id' => 0,
            'errors' => [],
            'user' => null,
            'needs_verification' => false,
            'plain_key' => '',
            'mail_sent' => false,
        ];

        if (!self::usersCanRegister($db)) {
            $empty['errors'][] = 'Registration is currently closed.';

            return $empty;
        }

        $formGate = self::verifyFormGate($data, $db);
        if (!$formGate['ok']) {
            $empty['errors'] = $formGate['errors'] !== []
                ? $formGate['errors']
                : self::genericFormFailure()['errors'];

            if (class_exists('AP_Rate_Limit', false)) {
                AP_Rate_Limit::hit(
                    AP_Rate_Limit::ACTION_REGISTER,
                    AP_Rate_Limit::ipBucket(),
                    $db
                );
            }

            return $empty;
        }

        // Optional visible CAPTCHA (beyond the always-on form gate).
        $captcha = self::verifyCaptcha($data, $db);
        if (!$captcha['ok']) {
            $empty['errors'] = $captcha['errors'] !== []
                ? $captcha['errors']
                : self::genericFormFailure()['errors'];

            // Count CAPTCHA failures toward the registration rate limit.
            if (class_exists('AP_Rate_Limit', false)) {
                AP_Rate_Limit::hit(
                    AP_Rate_Limit::ACTION_REGISTER,
                    AP_Rate_Limit::ipBucket(),
                    $db
                );
            }

            return $empty;
        }

        // IP rate limit (registration floods / bot sign-ups).
        if (class_exists('AP_Rate_Limit', false)) {
            $gate = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_REGISTER,
                AP_Rate_Limit::ipBucket(),
                $db
            );
            if (!$gate['allowed']) {
                $empty['errors'][] = AP_Rate_Limit::lockoutMessage(
                    (int) $gate['retry_after'],
                    'try registering again'
                );

                return $empty;
            }
        }

        $needsVerification = self::requireEmailVerification($db);
        $payload = $data;
        // Public registration always uses default_role (ignore client-supplied role).
        unset(
            $payload['role'],
            $payload['captcha_answer'],
            $payload['captcha_token'],
            $payload[self::FIELD_HONEYPOT],
            $payload['website'],
            $payload[self::FIELD_FORM_TICKET],
            $payload['ap_ft'],
            $payload[self::FIELD_GUARD_ACK]
        );
        $payload['user_status'] = $needsVerification ? self::STATUS_PENDING : 0;

        $loginRaw = (string) ($payload['user_login'] ?? '');
        $loginUnavailable = self::isReservedLogin($loginRaw, $db);
        if (!$loginUnavailable && class_exists('AP_User', false)) {
            $sanitizedLogin = AP_User::sanitizeUserLogin($loginRaw);
            $loginUnavailable = $sanitizedLogin !== ''
                && AP_User::getByLogin($sanitizedLogin, $db) !== null;
        }
        if ($loginUnavailable) {
            if (class_exists('AP_Rate_Limit', false)) {
                AP_Rate_Limit::hit(
                    AP_Rate_Limit::ACTION_REGISTER,
                    AP_Rate_Limit::ipBucket(),
                    $db
                );
            }

            $empty['errors'] = self::publicUsernameErrors([
                self::reservedLoginUnavailableMessage(),
            ]);

            return $empty;
        }

        $result = AP_User::create($payload, $db);
        if (!$result['ok'] || $result['user'] === null) {
            // Count failed attempts too (enumeration / spam of taken logins).
            if (class_exists('AP_Rate_Limit', false)) {
                AP_Rate_Limit::hit(
                    AP_Rate_Limit::ACTION_REGISTER,
                    AP_Rate_Limit::ipBucket(),
                    $db
                );
            }

            return [
                'ok' => false,
                'id' => 0,
                'errors' => self::publicUsernameErrors($result['errors']),
                'user' => null,
                'needs_verification' => false,
                'plain_key' => '',
                'mail_sent' => false,
            ];
        }

        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::hit(
                AP_Rate_Limit::ACTION_REGISTER,
                AP_Rate_Limit::ipBucket(),
                $db
            );
        }

        /** @var AP_User $user */
        $user = $result['user'];
        $plainKey = '';

        if ($needsVerification) {
            $plainKey = self::issueKey($user, self::PURPOSE_ACTIVATE, $db);
            if ($plainKey === '') {
                // Roll back on key failure so we do not leave a stuck pending user.
                AP_User::delete($user->ID, $db);

                return [
                    'ok' => false,
                    'id' => 0,
                    'errors' => ['Could not prepare account verification. Please try again.'],
                    'user' => null,
                    'needs_verification' => false,
                    'plain_key' => '',
                    'mail_sent' => false,
                ];
            }

            $mailSent = self::sendVerificationEmail($user, $plainKey, $db);
            if (!$mailSent) {
                // Keep the pending user. Do not pretend the message went out.
                return [
                    'ok' => true,
                    'id' => $user->ID,
                    'errors' => [self::couldNotSendVerificationMessage(true)],
                    'user' => $user,
                    'needs_verification' => true,
                    'plain_key' => $plainKey,
                    'mail_sent' => false,
                ];
            }
            AP_User::updateMeta($user->ID, 'ap_verification_sent', (string) time(), $db);
        }

        return [
            'ok' => true,
            'id' => $user->ID,
            'errors' => [],
            'user' => $user,
            'needs_verification' => $needsVerification,
            'plain_key' => $plainKey,
            // True only when a verification message was actually handed to send().
            'mail_sent' => $needsVerification,
        ];
    }

    /**
     * Confirm email with login + raw key from the verification link.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    public static function verifyEmail(string $login, string $plainKey, ?AP_DB $db = null): array
    {
        $login = trim($login);
        $plainKey = trim($plainKey);
        if ($login === '' || $plainKey === '') {
            return ['ok' => false, 'errors' => ['Invalid verification link.'], 'user' => null];
        }

        $user = AP_User::getByLogin($login, $db);
        if ($user === null) {
            return ['ok' => false, 'errors' => ['Invalid verification link.'], 'user' => null];
        }

        if ($user->user_status === 0 && $user->user_activation_key === '') {
            // Already verified — treat as success (idempotent).
            return ['ok' => true, 'errors' => [], 'user' => $user];
        }

        if (!self::validateKey($user, $plainKey, self::PURPOSE_ACTIVATE)) {
            return [
                'ok' => false,
                'errors' => ['This verification link is invalid or has expired.'],
                'user' => null,
            ];
        }

        return self::persistActiveStatus($user, self::resolveDb($db));
    }

    /**
     * Activate a pending verification account without the email key.
     *
     * Staff path for Users → Edit / the users list when mail never arrived.
     * Does not lift a forum ban: requires {@see self::userAwaitsVerification()}.
     * Already-active accounts (status 0, empty key) succeed idempotently.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    public static function activatePendingUser(AP_User $user, ?AP_DB $db = null): array
    {
        if ($user->ID < 1) {
            return ['ok' => false, 'errors' => ['Invalid user.'], 'user' => null];
        }

        $db = self::resolveDb($db);
        $fresh = AP_User::getById($user->ID, $db);
        if ($fresh === null) {
            return ['ok' => false, 'errors' => ['User not found.'], 'user' => null];
        }

        if ($fresh->user_status === 0 && $fresh->user_activation_key === '') {
            return ['ok' => true, 'errors' => [], 'user' => $fresh];
        }

        if (!self::userAwaitsVerification($fresh)) {
            return [
                'ok' => false,
                'errors' => ['This account is not waiting for email verification.'],
                'user' => $fresh,
            ];
        }

        return self::persistActiveStatus($fresh, $db);
    }

    /**
     * Set user_status to active and clear the activation key.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    private static function persistActiveStatus(AP_User $user, AP_DB $db): array
    {
        $updated = $db->update(
            'users',
            [
                'user_status' => 0,
                'user_activation_key' => '',
            ],
            ['ID' => $user->ID]
        );
        if ($updated === false) {
            return [
                'ok' => false,
                'errors' => ['Could not activate the account. Please try again.'],
                'user' => null,
            ];
        }

        $user->user_status = 0;
        $user->user_activation_key = '';

        return ['ok' => true, 'errors' => [], 'user' => $user];
    }

    /**
     * Start a password reset: issue key and email when the account exists and is active.
     *
     * Always returns ok=true with a generic message path so callers do not leak
     * whether an email/login is registered — except when a real send() fails,
     * in which case ok=false so the UI does not claim the mail went out.
     * The `sent` flag is for tests and honest-failure handling.
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     sent: bool,
     *     plain_key: string,
     *     user: ?AP_User
     * }
     */
    public static function requestPasswordReset(string $loginOrEmail, ?AP_DB $db = null): array
    {
        $loginOrEmail = trim($loginOrEmail);
        $generic = [
            'ok' => true,
            'errors' => [],
            'sent' => false,
            'plain_key' => '',
            'user' => null,
        ];

        if ($loginOrEmail === '') {
            return [
                'ok' => false,
                'errors' => ['Please enter your username or email address.'],
                'sent' => false,
                'plain_key' => '',
                'user' => null,
            ];
        }

        // IP throttle for reset form abuse (still returns generic success after).
        if (class_exists('AP_Rate_Limit', false)) {
            $gate = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
            if (!$gate['allowed']) {
                // Same generic success path so lockouts do not leak account state.
                return $generic;
            }
            AP_Rate_Limit::hit(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
        }

        $user = AP_User::getByLogin($loginOrEmail, $db);
        if ($user === null && str_contains($loginOrEmail, '@')) {
            $user = AP_User::getByEmail($loginOrEmail, $db);
        }

        // Unknown account or pending verification: still pretend success.
        if ($user === null || $user->user_status !== 0) {
            return $generic;
        }

        // Cooldown to limit reset email flooding.
        $last = AP_User::getMeta($user->ID, 'ap_password_reset_sent', $db);
        if (is_string($last) && $last !== '' && ctype_digit($last)) {
            $elapsed = time() - (int) $last;
            if ($elapsed >= 0 && $elapsed < self::RESET_COOLDOWN) {
                return $generic;
            }
        }

        $plainKey = self::issueKey($user, self::PURPOSE_RESET, $db);
        if ($plainKey === '') {
            return $generic;
        }

        $sent = self::sendPasswordResetEmail($user, $plainKey, $db);
        if (!$sent) {
            return [
                'ok' => false,
                'errors' => [self::couldNotSendResetMessage()],
                'sent' => false,
                'plain_key' => $plainKey,
                'user' => $user,
            ];
        }

        AP_User::updateMeta($user->ID, 'ap_password_reset_sent', (string) time(), $db);

        return [
            'ok' => true,
            'errors' => [],
            'sent' => true,
            'plain_key' => $plainKey,
            'user' => $user,
        ];
    }

    /**
     * Whether this account is waiting on registration email verification.
     *
     * Requires STATUS_PENDING and an activate-purpose key so a banned account
     * that reuses user_status=1 is not treated as unverified.
     */
    public static function userAwaitsVerification(?AP_User $user): bool
    {
        if ($user === null || $user->ID < 1) {
            return false;
        }
        if ($user->user_status !== self::STATUS_PENDING) {
            return false;
        }

        return str_starts_with($user->user_activation_key, self::PURPOSE_ACTIVATE . ':');
    }

    /**
     * Public resend of a pending verification email (login or email lookup).
     *
     * Unknown / already-active accounts return generic ok=true so the form does
     * not leak whether the address is registered. A real send() failure is
     * reported honestly (`ok` false) and does not claim the mail went out.
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     sent: bool,
     *     plain_key: string,
     *     user: ?AP_User
     * }
     */
    public static function resendVerification(string $loginOrEmail, ?AP_DB $db = null): array
    {
        $loginOrEmail = trim($loginOrEmail);
        $generic = [
            'ok' => true,
            'errors' => [],
            'sent' => false,
            'plain_key' => '',
            'user' => null,
        ];

        if ($loginOrEmail === '') {
            return [
                'ok' => false,
                'errors' => ['Please enter your username or email address.'],
                'sent' => false,
                'plain_key' => '',
                'user' => null,
            ];
        }

        if (class_exists('AP_Rate_Limit', false)) {
            // Share the password-reset IP bucket so public resend cannot become
            // a second flood / enumeration path. Lockout still returns generic ok.
            $gate = AP_Rate_Limit::check(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
            if (!$gate['allowed']) {
                return $generic;
            }
            AP_Rate_Limit::hit(
                AP_Rate_Limit::ACTION_PASSWORD_RESET,
                AP_Rate_Limit::ipBucket(),
                $db
            );
        }

        $user = AP_User::getByLogin($loginOrEmail, $db);
        if ($user === null && str_contains($loginOrEmail, '@')) {
            $user = AP_User::getByEmail($loginOrEmail, $db);
        }

        if ($user === null || !self::userAwaitsVerification($user)) {
            return $generic;
        }

        return self::resendVerificationForUser($user, $db, true);
    }

    /**
     * Resend verification for a known pending user (admin Users → Edit).
     *
     * @return array{
     *     ok: bool,
     *     errors: list<string>,
     *     sent: bool,
     *     plain_key: string,
     *     user: ?AP_User
     * }
     */
    public static function resendVerificationForUser(
        AP_User $user,
        ?AP_DB $db = null,
        bool $respectCooldown = false
    ): array {
        $fail = [
            'ok' => false,
            'errors' => [],
            'sent' => false,
            'plain_key' => '',
            'user' => $user,
        ];

        if (!self::userAwaitsVerification($user)) {
            $fail['errors'][] = 'This account is not waiting for email verification.';

            return $fail;
        }

        if ($respectCooldown) {
            $last = AP_User::getMeta($user->ID, 'ap_verification_sent', $db);
            if (is_string($last) && $last !== '' && ctype_digit($last)) {
                $elapsed = time() - (int) $last;
                if ($elapsed >= 0 && $elapsed < self::VERIFY_RESEND_COOLDOWN) {
                    return [
                        'ok' => true,
                        'errors' => [],
                        'sent' => false,
                        'plain_key' => '',
                        'user' => $user,
                    ];
                }
            }
        }

        $plainKey = self::issueKey($user, self::PURPOSE_ACTIVATE, $db);
        if ($plainKey === '') {
            $fail['errors'][] = 'Could not prepare account verification. Please try again.';

            return $fail;
        }

        $sent = self::sendVerificationEmail($user, $plainKey, $db);
        if (!$sent) {
            $fail['errors'][] = self::couldNotSendVerificationMessage(false);
            $fail['plain_key'] = $plainKey;

            return $fail;
        }

        AP_User::updateMeta($user->ID, 'ap_verification_sent', (string) time(), $db);

        return [
            'ok' => true,
            'errors' => [],
            'sent' => true,
            'plain_key' => $plainKey,
            'user' => $user,
        ];
    }

    /**
     * Validate a password-reset key without consuming it.
     */
    public static function checkPasswordResetKey(
        string $login,
        string $plainKey,
        ?AP_DB $db = null
    ): ?AP_User {
        $login = trim($login);
        $plainKey = trim($plainKey);
        if ($login === '' || $plainKey === '') {
            return null;
        }

        $user = AP_User::getByLogin($login, $db);
        if ($user === null || $user->user_status !== 0) {
            return null;
        }

        if (!self::validateKey($user, $plainKey, self::PURPOSE_RESET)) {
            return null;
        }

        return $user;
    }

    /**
     * Complete password reset with a valid key.
     *
     * @return array{ok: bool, errors: list<string>, user: ?AP_User}
     */
    public static function resetPassword(
        string $login,
        string $plainKey,
        string $newPassword,
        ?AP_DB $db = null
    ): array {
        $user = self::checkPasswordResetKey($login, $plainKey, $db);
        if ($user === null) {
            return [
                'ok' => false,
                'errors' => ['This password reset link is invalid or has expired.'],
                'user' => null,
            ];
        }

        if ($newPassword === '') {
            return ['ok' => false, 'errors' => ['Password is required.'], 'user' => null];
        }
        if (strlen($newPassword) < 8) {
            return [
                'ok' => false,
                'errors' => ['Password must be at least 8 characters.'],
                'user' => null,
            ];
        }

        if (!$user->updatePassword($newPassword, $db)) {
            return [
                'ok' => false,
                'errors' => ['Could not update the password. Please try again.'],
                'user' => null,
            ];
        }

        // Invalidate the reset key after successful use.
        $db = self::resolveDb($db);
        $db->update('users', ['user_activation_key' => ''], ['ID' => $user->ID]);
        $user->user_activation_key = '';
        AP_User::deleteMeta($user->ID, 'ap_password_reset_sent', $db);

        // Re-fetch so callers see the new hash / clean key.
        $fresh = AP_User::getById($user->ID, $db);

        return ['ok' => true, 'errors' => [], 'user' => $fresh];
    }

    /**
     * Issue a one-time key for the given purpose and store its HMAC on the user.
     *
     * @return string Raw key for the email URL, or empty string on failure.
     */
    public static function issueKey(AP_User $user, string $purpose, ?AP_DB $db = null): string
    {
        if ($user->ID < 1) {
            return '';
        }
        if ($purpose !== self::PURPOSE_ACTIVATE && $purpose !== self::PURPOSE_RESET) {
            return '';
        }

        try {
            $plain = bin2hex(random_bytes(32));
        } catch (Throwable) {
            return '';
        }

        $timestamp = time();
        $hmac = self::hashKey($plain, $purpose, $user->ID, $timestamp);
        $stored = $purpose . ':' . $timestamp . ':' . $hmac;

        $db = self::resolveDb($db);
        $ok = $db->update(
            'users',
            ['user_activation_key' => $stored],
            ['ID' => $user->ID]
        );
        if ($ok === false) {
            return '';
        }

        $user->user_activation_key = $stored;

        return $plain;
    }

    /**
     * Validate a raw key against the stored activation field.
     */
    public static function validateKey(AP_User $user, string $plainKey, string $purpose): bool
    {
        $plainKey = trim($plainKey);
        if ($plainKey === '' || $user->user_activation_key === '') {
            return false;
        }

        $parts = explode(':', $user->user_activation_key, 3);
        if (count($parts) !== 3) {
            return false;
        }

        [$storedPurpose, $tsRaw, $storedHmac] = $parts;
        if ($storedPurpose !== $purpose) {
            return false;
        }
        if (!ctype_digit($tsRaw)) {
            return false;
        }
        $timestamp = (int) $tsRaw;
        if ($timestamp < 1 || (time() - $timestamp) > self::KEY_TTL) {
            return false;
        }

        $expected = self::hashKey($plainKey, $purpose, $user->ID, $timestamp);

        return hash_equals($storedHmac, $expected);
    }

    /**
     * Absolute URL for email verification.
     */
    public static function verificationUrl(AP_User $user, string $plainKey, ?AP_DB $db = null): string
    {
        return self::loginActionUrl(
            'verifyemail',
            [
                'login' => $user->user_login,
                'key' => $plainKey,
            ],
            $db
        );
    }

    /**
     * Absolute URL for password reset form.
     */
    public static function passwordResetUrl(AP_User $user, string $plainKey, ?AP_DB $db = null): string
    {
        return self::loginActionUrl(
            'rp',
            [
                'login' => $user->user_login,
                'key' => $plainKey,
            ],
            $db
        );
    }

    /**
     * Send the email-verification message.
     */
    public static function sendVerificationEmail(
        AP_User $user,
        string $plainKey,
        ?AP_DB $db = null
    ): bool {
        $site = self::siteName($db);
        $url = self::verificationUrl($user, $plainKey, $db);
        $subject = sprintf('[%s] Confirm your email', $site);
        $message = "Hello {$user->user_login},\r\n\r\n"
            . "Thank you for registering at {$site}.\r\n\r\n"
            . "Please confirm your email address by visiting this link:\r\n"
            . "{$url}\r\n\r\n"
            . self::mailLinkNotice()
            . "If you did not register, you can ignore this email.\r\n";

        return AP_Mail::send($user->user_email, $subject, $message);
    }

    /**
     * Send the password-reset message.
     */
    public static function sendPasswordResetEmail(
        AP_User $user,
        string $plainKey,
        ?AP_DB $db = null
    ): bool {
        $site = self::siteName($db);
        $url = self::passwordResetUrl($user, $plainKey, $db);
        $subject = sprintf('[%s] Password reset', $site);
        $message = "Hello {$user->user_login},\r\n\r\n"
            . "Someone requested a password reset for your account on {$site}.\r\n\r\n"
            . "To choose a new password, visit:\r\n"
            . "{$url}\r\n\r\n"
            . self::mailLinkNotice()
            . "If you did not request this, you can ignore this email."
            . " Your password will not change.\r\n";

        return AP_Mail::send($user->user_email, $subject, $message);
    }

    /**
     * Build ap-admin/login.php?action=… URL (absolute when siteurl known).
     *
     * Does not use AP_Admin::url(): that helper is path-only (`/ap-admin/…`)
     * when AP_SITEURL is unset, which is the normal production config. Mail
     * bodies need `{siteurl}/ap-admin/login.php?…`. Prefers the live `siteurl`
     * option over AP_Rewrite’s request cache so the mailed host matches Settings.
     *
     * @param array<string, string> $query
     */
    public static function loginActionUrl(string $action, array $query = [], ?AP_DB $db = null): string
    {
        $query = array_merge(['action' => $action], $query);

        $site = (string) self::readOption('siteurl', '', $db);
        if ($site === '') {
            $site = (string) self::readOption('home', '', $db);
        }
        if ($site === '' && defined('AP_SITEURL') && is_string(AP_SITEURL) && AP_SITEURL !== '') {
            $site = (string) AP_SITEURL;
        }

        $base = '';
        if (self::isAbsoluteHttpUrl($site)) {
            $base = rtrim($site, '/') . '/ap-admin/login.php';
        } elseif (class_exists('AP_Rewrite', false)) {
            $rewritten = AP_Rewrite::siteUrl('ap-admin/login.php', $db);
            if (self::isAbsoluteHttpUrl($rewritten)) {
                $base = $rewritten;
            }
        }
        if ($base === '') {
            $base = $site !== ''
                ? rtrim($site, '/') . '/ap-admin/login.php'
                : '/ap-admin/login.php';
        }

        $qs = http_build_query($query);
        if ($qs === '') {
            return $base;
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . $qs;
    }

    /**
     * Whether $url is an http(s) URL with a host (usable in email).
     */
    private static function isAbsoluteHttpUrl(string $url): bool
    {
        return strncmp($url, 'https://', 8) === 0 || strncmp($url, 'http://', 7) === 0;
    }

    /**
     * HMAC of the raw key (never store the raw value).
     */
    private static function hashKey(
        string $plainKey,
        string $purpose,
        int $userId,
        int $timestamp
    ): string {
        $material = $purpose . '|' . $userId . '|' . $timestamp . '|' . $plainKey;

        return hash_hmac('sha256', $material, self::signingSecret());
    }

    /**
     * Generic public-register failure (honeypot / ticket / min-fill).
     *
     * @return array{ok: bool, errors: list<string>}
     */
    private static function genericFormFailure(): array
    {
        return [
            'ok' => false,
            'errors' => ['Could not complete registration. Please try again.'],
        ];
    }

    /**
     * Signed form ticket: base64url(ts:nonce:hmac).
     */
    private static function encodeFormTicket(int $timestamp, string $nonce): string
    {
        $hmac = hash_hmac(
            'sha256',
            'form_ticket|' . $timestamp . '|' . $nonce,
            self::signingSecret()
        );

        return self::toBase64Url($timestamp . ':' . $nonce . ':' . $hmac);
    }

    /**
     * @return array{ts: int, nonce: string}|null
     */
    private static function decodeFormTicket(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = self::fromBase64Url($token);
        if ($payload === null || $payload === '') {
            return null;
        }

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$tsRaw, $nonce, $hmac] = $parts;
        if (!ctype_digit($tsRaw) || $nonce === '' || $hmac === '') {
            return null;
        }
        if (!ctype_xdigit($nonce) || strlen($nonce) !== 16) {
            return null;
        }

        $ts = (int) $tsRaw;
        $expected = hash_hmac(
            'sha256',
            'form_ticket|' . $ts . '|' . $nonce,
            self::signingSecret()
        );
        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        $now = time();
        if ($ts < 1 || $ts > ($now + 60)) {
            return null;
        }
        if (($now - $ts) > self::FORM_TICKET_TTL) {
            return null;
        }

        return ['ts' => $ts, 'nonce' => $nonce];
    }

    /**
     * Guard checkbox + fallback code or JS proof-of-work.
     *
     * @param array<string, mixed> $data
     *
     * @return array{ok: bool, errors: list<string>}
     */
    private static function verifyGuardResponse(array $data): array
    {
        $incomplete = [
            'ok' => false,
            'errors' => ['Please complete the human check.'],
        ];

        $ack = trim((string) ($data[self::FIELD_GUARD_ACK] ?? ''));
        $answerRaw = trim((string) ($data['captcha_answer'] ?? ''));
        $token = trim((string) ($data['captcha_token'] ?? ''));
        if ($ack !== '1' || $answerRaw === '' || $token === '') {
            return $incomplete;
        }

        $parsed = self::decodeGuardToken($token);
        if ($parsed === null) {
            return [
                'ok' => false,
                'errors' => ['The human check expired. Please try again.'],
            ];
        }

        $prefix = self::GUARD_POW_PREFIX;
        $prefixLen = strlen($prefix);
        if (strncmp(strtolower($answerRaw), $prefix, $prefixLen) === 0) {
            $proof = substr($answerRaw, $prefixLen);
            if (self::guardProofIsValid($token, $proof, $parsed['difficulty'])) {
                return ['ok' => true, 'errors' => []];
            }

            return $incomplete;
        }

        if (hash_equals(strtoupper($parsed['code']), strtoupper($answerRaw))) {
            return ['ok' => true, 'errors' => []];
        }

        return $incomplete;
    }

    /**
     * SHA-256(token + ':' + proof) starts with $difficulty hex zeros.
     */
    private static function guardProofIsValid(string $token, string $proof, int $difficulty): bool
    {
        if ($proof === '' || strlen($proof) > 16 || !ctype_alnum($proof)) {
            return false;
        }
        if ($difficulty < 1 || $difficulty > 6) {
            return false;
        }

        $hash = hash('sha256', $token . ':' . $proof);

        return str_starts_with($hash, str_repeat('0', $difficulty));
    }

    /**
     * Short no-JS fallback code (uppercase, no ambiguous 0/O/1/I).
     */
    private static function randomGuardCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < self::GUARD_CODE_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Signed guard token: base64url(ts:nonce:difficulty:code:hmac).
     */
    private static function encodeGuardToken(
        int $timestamp,
        string $nonce,
        int $difficulty,
        string $code
    ): string {
        $hmac = hash_hmac(
            'sha256',
            'guard|' . $timestamp . '|' . $nonce . '|' . $difficulty . '|' . $code,
            self::signingSecret()
        );

        return self::toBase64Url(
            $timestamp . ':' . $nonce . ':' . $difficulty . ':' . $code . ':' . $hmac
        );
    }

    /**
     * @return array{ts: int, nonce: string, difficulty: int, code: string}|null
     */
    private static function decodeGuardToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = self::fromBase64Url($token);
        if ($payload === null || $payload === '') {
            return null;
        }

        $parts = explode(':', $payload, 5);
        if (count($parts) !== 5) {
            return null;
        }

        [$tsRaw, $nonce, $diffRaw, $code, $hmac] = $parts;
        if (!ctype_digit($tsRaw) || !ctype_digit($diffRaw) || $nonce === '' || $code === '' || $hmac === '') {
            return null;
        }
        if (!ctype_xdigit($nonce) || strlen($nonce) !== 16) {
            return null;
        }
        if (!preg_match('/^[A-HJ-NP-Z2-9]{' . self::GUARD_CODE_LENGTH . '}$/', $code)) {
            return null;
        }

        $ts = (int) $tsRaw;
        $difficulty = (int) $diffRaw;
        if ($ts < 1 || $difficulty < 1 || $difficulty > 6) {
            return null;
        }
        if ((time() - $ts) > self::CAPTCHA_TTL || $ts > (time() + 60)) {
            return null;
        }

        $expected = hash_hmac(
            'sha256',
            'guard|' . $ts . '|' . $nonce . '|' . $difficulty . '|' . $code,
            self::signingSecret()
        );
        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        return [
            'ts' => $ts,
            'nonce' => $nonce,
            'difficulty' => $difficulty,
            'code' => $code,
        ];
    }

    /**
     * Signed captcha token: base64url(ts:a:b:hmac).
     */
    private static function encodeCaptchaToken(int $a, int $b, int $timestamp): string
    {
        $hmac = hash_hmac(
            'sha256',
            'captcha|' . $timestamp . '|' . $a . '|' . $b,
            self::signingSecret()
        );

        return self::toBase64Url($timestamp . ':' . $a . ':' . $b . ':' . $hmac);
    }

    /**
     * @return array{a: int, b: int, ts: int}|null
     */
    private static function decodeCaptchaToken(string $token): ?array
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

        [$tsRaw, $aRaw, $bRaw, $hmac] = $parts;
        if (!ctype_digit($tsRaw) || !ctype_digit($aRaw) || !ctype_digit($bRaw) || $hmac === '') {
            return null;
        }

        $ts = (int) $tsRaw;
        $a = (int) $aRaw;
        $b = (int) $bRaw;
        if ($ts < 1 || $a < 1 || $b < 1 || $a > 99 || $b > 99) {
            return null;
        }
        if ((time() - $ts) > self::CAPTCHA_TTL || $ts > (time() + 60)) {
            return null;
        }

        $expected = hash_hmac(
            'sha256',
            'captcha|' . $ts . '|' . $a . '|' . $b,
            self::signingSecret()
        );
        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        return ['a' => $a, 'b' => $b, 'ts' => $ts];
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
     * Shared expiry and delivery hint for verification and reset mail (text/plain).
     * Expiry copy matches {@see self::KEY_TTL} (24 hours).
     */
    private static function mailLinkNotice(): string
    {
        return "This link expires in 24 hours.\r\n\r\n"
            . "If this message is not in your inbox, check your spam folder. "
            . "The sending server may be new.\r\n\r\n";
    }

    /**
     * Public-facing copy when verification mail cannot be sent.
     */
    private static function couldNotSendVerificationMessage(bool $accountJustCreated): string
    {
        if ($accountJustCreated) {
            return 'Your account was created, but the verification email could not be sent.'
                . ' Please try resending, or contact the site administrator.';
        }

        return 'The verification email could not be sent.'
            . ' Please try again later or contact the site administrator.';
    }

    /**
     * Public-facing copy when a password-reset mail cannot be sent.
     */
    private static function couldNotSendResetMessage(): string
    {
        return 'The password reset email could not be sent.'
            . ' Please try again later or contact the site administrator.';
    }

    /**
     * @param mixed $errors
     *
     * @return list<string>
     */
    private static function normalizeErrorList(mixed $errors): array
    {
        if (!is_array($errors)) {
            return [];
        }
        $out = [];
        foreach ($errors as $e) {
            if (is_string($e) && $e !== '') {
                $out[] = $e;
            }
        }

        return $out;
    }

    private static function signingSecret(): string
    {
        $key = defined('AP_AUTH_KEY') ? (string) AP_AUTH_KEY : '';
        $salt = defined('AP_AUTH_SALT') ? (string) AP_AUTH_SALT : '';
        if ($key === '' && $salt === '') {
            // Fallback so unit tests without config still exercise the path.
            $key = defined('AP_LOGGED_IN_KEY') ? (string) AP_LOGGED_IN_KEY : 'agorapress-auth';
            $salt = defined('AP_LOGGED_IN_SALT') ? (string) AP_LOGGED_IN_SALT : 'agorapress-salt';
        }

        return $key . $salt;
    }

    private static function siteName(?AP_DB $db): string
    {
        $name = self::readOption('blogname', 'AgoraPress', $db);
        $name = trim((string) $name);

        return $name !== '' ? $name : 'AgoraPress';
    }

    private static function optionIsTruthy(
        string $name,
        bool $default,
        ?AP_DB $db
    ): bool {
        $raw = self::readOption($name, $default ? '1' : '0', $db);
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string) $raw));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    private static function readOption(string $name, mixed $default, ?AP_DB $db): mixed
    {
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        // Direct DB read when Options API is not loaded (isolated tests).
        if ($db instanceof AP_DB) {
            try {
                $raw = $db->getVar(
                    'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
                    . ' WHERE option_name = ? LIMIT 1',
                    [$name]
                );
                if ($raw !== null) {
                    return $raw;
                }
            } catch (Throwable) {
                // fall through
            }
        }

        return $default;
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }

        throw new RuntimeException('No database connection available for registration.');
    }
}
