<?php

/**
 * AgoraPress mail helper — single outbound API with php and smtp transports.
 *
 * `AP_Mail::send()` is the only outbound mail entry point. Transport `php`
 * (default) uses PHP mail(); transport `smtp` uses the native stream client
 * in {@see AP_SMTP}. Filter `ap_mail_send` may replace either transport
 * (return true/false to short-circuit; null continues). Test outbox capture
 * runs after that filter and before php/smtp. Outbound volume is gated by
 * {@see AP_Rate_Limit::ACTION_MAIL} (IP + recipient) so an open register
 * form cannot turn the configured transport into a cannon. No PHPMailer;
 * no Composer runtime mail library.
 *
 * When defined in `ap-config.php`, these constants override the options table
 * (so an SMTP password can live next to the database password):
 * `AP_MAIL_FROM_NAME`, `AP_MAIL_FROM_EMAIL`, `AP_MAIL_TRANSPORT`,
 * `AP_SMTP_HOST`, `AP_SMTP_PORT`, `AP_SMTP_ENCRYPTION`, `AP_SMTP_USER`,
 * `AP_SMTP_PASS`. Test overlay ({@see setConfigForTests}) still wins in tests.
 * {@see healthSnapshot()} feeds Tools → Site Health; it never sends mail and
 * never returns SMTP secrets.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Send site emails (registration, password reset, etc.).
 */
class AP_Mail
{
    public const TRANSPORT_PHP = 'php';

    public const TRANSPORT_SMTP = 'smtp';

    /** @var list<array{to: string, subject: string, message: string, headers: string}>|null */
    private static ?array $testOutbox = null;

    /**
     * Test-only config overlay (transport, SMTP host/port/user/pass/encryption).
     *
     * @var array<string, mixed>|null
     */
    private static ?array $configOverride = null;

    private static string $lastError = '';

    /** Test-only: next valid send() returns false with this error. */
    private static ?string $forcedFailure = null;

    /**
     * Capture outbound mail in memory instead of calling mail().
     */
    public static function enableTestMode(): void
    {
        self::$testOutbox = [];
    }

    /**
     * Stop capturing and discard the outbox.
     */
    public static function disableTestMode(): void
    {
        self::$testOutbox = null;
    }

    /**
     * Whether the test outbox is active.
     */
    public static function isTestMode(): bool
    {
        return self::$testOutbox !== null;
    }

    /**
     * Messages captured while test mode is on (oldest first).
     *
     * @return list<array{to: string, subject: string, message: string, headers: string}>
     */
    public static function getTestOutbox(): array
    {
        return self::$testOutbox ?? [];
    }

    /**
     * Clear captured messages (keeps test mode on).
     */
    public static function clearTestOutbox(): void
    {
        if (self::$testOutbox !== null) {
            self::$testOutbox = [];
        }
    }

    /**
     * Overlay transport / SMTP settings for tests. Pass null to clear.
     *
     * @param array<string, mixed>|null $config
     */
    public static function setConfigForTests(?array $config): void
    {
        self::$configOverride = $config;
    }

    /**
     * Reset static test state (outbox, config overlay, last error, forced failure).
     */
    public static function resetForTests(): void
    {
        self::$testOutbox = null;
        self::$configOverride = null;
        self::$lastError = '';
        self::$forcedFailure = null;
    }

    /**
     * Test helper: the next {@see send()} with valid recipients returns false.
     *
     * Used to exercise registration / reset callers that must not claim mail
     * went out when the transport fails. Cleared after one send or {@see resetForTests()}.
     */
    public static function failNextForTests(string $message = 'Forced test failure.'): void
    {
        self::$forcedFailure = $message !== '' ? $message : 'Forced test failure.';
    }

    /**
     * Last send failure (empty after a successful send or test-outbox capture).
     *
     * In-memory for the current request. {@see storedLastError()} also reads
     * the persisted `mail_last_error` option used by Settings → Mail.
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * Last send failure for display: this request, else stored option.
     */
    public static function storedLastError(?AP_DB $db = null): string
    {
        if (self::$lastError !== '') {
            return self::$lastError;
        }

        return trim(self::optionString('mail_last_error', '', $db));
    }

    /**
     * Site Health / support snapshot. Does not send mail and never includes secrets.
     *
     * @return array{
     *     transport: string,
     *     from_email: string,
     *     from_name: string,
     *     reply_to: string,
     *     smtp_host: string,
     *     smtp_port: int,
     *     smtp_encryption: string,
     *     smtp_user_set: bool,
     *     smtp_pass_set: bool,
     *     last_error: string,
     *     php_mail_available: bool,
     *     override_constants: list<string>
     * }
     */
    public static function healthSnapshot(?AP_DB $db = null): array
    {
        $smtp = self::smtpSettings($db);

        return [
            'transport' => self::transport($db),
            'from_email' => self::fromAddress($db),
            'from_name' => self::fromName($db),
            'reply_to' => self::replyToAddress($db),
            'smtp_host' => $smtp['host'],
            'smtp_port' => $smtp['port'],
            'smtp_encryption' => $smtp['encryption'],
            'smtp_user_set' => $smtp['username'] !== '',
            'smtp_pass_set' => $smtp['password'] !== '',
            'last_error' => self::storedLastError($db),
            'php_mail_available' => function_exists('mail'),
            'override_constants' => self::definedConfigConstants(),
        ];
    }

    /**
     * Whether an SMTP password is stored (never return the secret itself).
     */
    public static function hasSmtpPassword(?AP_DB $db = null): bool
    {
        return (string) self::configValue('smtp_pass', '', $db) !== '';
    }

    /**
     * Send a text/plain test message to the site `admin_email`.
     */
    public static function sendTestToAdmin(): bool
    {
        $to = self::adminEmail();
        if ($to === '') {
            self::rememberError('No admin email is configured.');

            return false;
        }

        $site = self::fromName();
        $subject = 'AgoraPress test email';
        $message = 'This is a test message from ' . $site . ".\n\n"
            . "If you received this, outbound mail is working.\n";

        return self::send($to, $subject, $message);
    }

    /**
     * Active transport: `php` (default) or `smtp`.
     *
     * `AP_MAIL_TRANSPORT` wins over the `mail_transport` option when defined.
     */
    public static function transport(?AP_DB $db = null): string
    {
        $raw = self::configValue('transport', self::TRANSPORT_PHP, $db);

        return self::normalizeTransport((string) $raw);
    }

    /**
     * ap-config.php constant that overrides this mail setting, or null.
     *
     * Keys match {@see configValue()} (`transport`, `from_name`, `smtp_host`, …).
     * Reply-To has no constant this pass.
     */
    public static function configConstantName(string $key): ?string
    {
        return match ($key) {
            'transport' => 'AP_MAIL_TRANSPORT',
            'from_name' => 'AP_MAIL_FROM_NAME',
            'from_email' => 'AP_MAIL_FROM_EMAIL',
            'smtp_host' => 'AP_SMTP_HOST',
            'smtp_port' => 'AP_SMTP_PORT',
            'smtp_encryption' => 'AP_SMTP_ENCRYPTION',
            'smtp_user' => 'AP_SMTP_USER',
            'smtp_pass' => 'AP_SMTP_PASS',
            default => null,
        };
    }

    /**
     * Mail override constants currently defined (names only, never values).
     *
     * @return list<string>
     */
    public static function definedConfigConstants(): array
    {
        $names = [];
        foreach (
            [
                'transport',
                'from_name',
                'from_email',
                'smtp_host',
                'smtp_port',
                'smtp_encryption',
                'smtp_user',
                'smtp_pass',
            ] as $key
        ) {
            $constant = self::configConstantName($key);
            if ($constant !== null && defined($constant)) {
                $names[] = $constant;
            }
        }

        return $names;
    }

    /**
     * SMTP settings for the native client (host empty until configured).
     *
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string
     * }
     */
    public static function smtpSettings(?AP_DB $db = null): array
    {
        $encryption = self::normalizeEncryption(
            (string) self::configValue('smtp_encryption', AP_SMTP::ENCRYPTION_TLS, $db)
        );
        $port = (int) self::configValue(
            'smtp_port',
            $encryption === AP_SMTP::ENCRYPTION_SSL
                ? AP_SMTP::DEFAULT_PORT_SSL
                : AP_SMTP::DEFAULT_PORT_TLS,
            $db
        );

        return [
            'host' => trim((string) self::configValue('smtp_host', '', $db)),
            'port' => $port,
            'encryption' => $encryption,
            'username' => (string) self::configValue('smtp_user', '', $db),
            'password' => (string) self::configValue('smtp_pass', '', $db),
        ];
    }

    /**
     * Send an email.
     *
     * After recipients are sanitized, outbound mail is rate-limited
     * ({@see AP_Rate_Limit::ACTION_MAIL}, IP + recipient). Then `ap_mail_send`
     * runs so a plugin can replace php/smtp. Return true or false to
     * short-circuit; null (default) continues with the test outbox, then the
     * configured transport. Callbacks that do not send must return the incoming
     * value unchanged (`accepted_args` ≥ 2 to receive the payload).
     *
     * @param string|list<string> $to      Recipient address(es).
     * @param string              $subject Subject line (plain text).
     * @param string              $message Body (plain text; CRLF normalized).
     * @param array<string, string> $headers Extra headers (name => value), optional.
     */
    public static function send(
        string|array $to,
        string $subject,
        string $message,
        array $headers = []
    ): bool {
        self::$lastError = '';

        $recipients = is_array($to) ? $to : [$to];
        $clean = [];
        foreach ($recipients as $addr) {
            $addr = trim((string) $addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                $clean[] = $addr;
            }
        }
        if ($clean === []) {
            self::rememberError('No valid recipients.');

            return false;
        }

        if (self::$forcedFailure !== null) {
            $forced = self::$forcedFailure;
            self::$forcedFailure = null;
            self::rememberError($forced);

            return false;
        }

        if (!self::consumeOutboundQuota($clean)) {
            return false;
        }

        $toHeader = implode(', ', $clean);
        $subject = self::sanitizeHeaderValue($subject);
        if ($subject === '') {
            $subject = 'AgoraPress';
        }

        // Normalize body line endings for SMTP friendliness.
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = str_replace("\n", "\r\n", $message);

        $headerLines = self::defaultHeaders();
        foreach ($headers as $name => $value) {
            $name = self::sanitizeHeaderName((string) $name);
            $value = self::sanitizeHeaderValue((string) $value);
            if ($name === '' || $value === '') {
                continue;
            }
            $headerLines[$name] = $value;
        }

        $headerString = '';
        foreach ($headerLines as $name => $value) {
            $headerString .= $name . ': ' . $value . "\r\n";
        }

        $handled = self::applySendFilter(
            $clean,
            $toHeader,
            $subject,
            $message,
            $headerLines,
            $headerString
        );
        if ($handled !== null) {
            if ($handled) {
                self::rememberError('');
            } elseif (self::$lastError === '') {
                self::rememberError('Mail send was cancelled.');
            }

            return $handled;
        }

        if (self::$testOutbox !== null) {
            self::$testOutbox[] = [
                'to' => $toHeader,
                'subject' => $subject,
                'message' => $message,
                'headers' => rtrim($headerString, "\r\n"),
            ];
            self::rememberError('');

            return true;
        }

        if (self::transport() === self::TRANSPORT_SMTP) {
            return self::sendViaSmtp($toHeader, $subject, $message, $headerString, $headerLines, $clean);
        }

        // Suppress warnings from mail() on misconfigured hosts; caller checks bool.
        $ok = @mail($toHeader, $subject, $message, $headerString);
        if (!$ok) {
            self::rememberError('PHP mail() returned false.');

            return false;
        }
        self::rememberError('');

        return true;
    }

    /**
     * Build default From / Content-Type headers from site options when available.
     *
     * @return array<string, string>
     */
    public static function defaultHeaders(): array
    {
        $fromEmail = self::fromAddress();
        $fromName = self::fromName();
        $from = $fromName !== ''
            ? sprintf('%s <%s>', self::encodeFromName($fromName), $fromEmail)
            : $fromEmail;

        $headers = [
            'From' => $from,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer' => 'AgoraPress',
        ];
        $replyTo = self::replyToAddress();
        if ($replyTo !== '') {
            $headers['Reply-To'] = $replyTo;
        }

        return $headers;
    }

    /**
     * Site From address (`AP_MAIL_FROM_EMAIL`, then `mail_from_email`, then
     * `admin_email`, then noreply@host).
     */
    public static function fromAddress(?AP_DB $db = null): string
    {
        $email = self::validEmail((string) self::configValue('from_email', '', $db));
        if ($email !== '') {
            return $email;
        }
        $email = self::adminEmail($db);
        if ($email !== '') {
            return $email;
        }

        // Last resort: noreply@hostname (still valid for local tests).
        $host = (string) (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'localhost';

        return 'noreply@' . $host;
    }

    /**
     * Site From display name (`AP_MAIL_FROM_NAME`, then `mail_from_name`, then
     * `blogname`).
     */
    public static function fromName(?AP_DB $db = null): string
    {
        $name = trim((string) self::configValue('from_name', '', $db));
        if ($name !== '') {
            return $name;
        }
        $name = self::optionString('blogname', 'AgoraPress', $db);
        $name = trim($name);

        return $name !== '' ? $name : 'AgoraPress';
    }

    /**
     * Reply-To address (`mail_reply_to`, then `admin_email`). Empty omits the header.
     */
    public static function replyToAddress(?AP_DB $db = null): string
    {
        $email = self::validEmail((string) self::configValue('reply_to', '', $db));
        if ($email !== '') {
            return $email;
        }

        return self::adminEmail($db);
    }

    /**
     * Administration email from General settings (not the mail From address).
     */
    public static function adminEmail(?AP_DB $db = null): string
    {
        return self::validEmail(self::optionString('admin_email', '', $db));
    }

    /**
     * Quote a display name for a From: header when needed.
     */
    private static function encodeFromName(string $name): string
    {
        // Quote if needed; strip CR/LF always.
        $name = self::sanitizeHeaderValue($name);
        if ($name === '') {
            return 'AgoraPress';
        }
        if (preg_match('/[,"<>]/', $name)) {
            return '"' . str_replace('"', '', $name) . '"';
        }

        return $name;
    }

    private static function sanitizeHeaderName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[\r\n\0]+/', '', $name) ?? '';
        $name = preg_replace('/[^A-Za-z0-9\-]/', '', $name) ?? '';

        return $name;
    }

    private static function sanitizeHeaderValue(string $value): string
    {
        // Prevent header injection.
        return trim(str_replace(["\r", "\n", "\0"], '', $value));
    }

    /**
     * Apply {@see AP_Rate_Limit::ACTION_MAIL} before any transport or filter.
     *
     * Invalid recipients are rejected earlier so they do not consume quota.
     * When the limiter class is not loaded, or tests have disabled it, send
     * continues. A blocked send stores the lockout text as last error and
     * does not call `ap_mail_send` / php / smtp.
     *
     * @param list<string> $recipients
     */
    private static function consumeOutboundQuota(array $recipients): bool
    {
        if (!class_exists('AP_Rate_Limit', false) || AP_Rate_Limit::isDisabled()) {
            return true;
        }

        $gate = AP_Rate_Limit::checkMail($recipients);
        if (!$gate['allowed']) {
            $message = $gate['message'] !== ''
                ? $gate['message']
                : 'Too many emails. Please try again later.';
            self::rememberError($message);

            return false;
        }

        AP_Rate_Limit::recordMail($recipients);

        return true;
    }

    /**
     * Filter `ap_mail_send` so a plugin can replace php/smtp.
     *
     * Returning a boolean short-circuits {@see send()}. Null continues.
     *
     * @param list<string>          $recipients
     * @param array<string, string> $headerLines
     */
    private static function applySendFilter(
        array $recipients,
        string $toHeader,
        string $subject,
        string $message,
        array $headerLines,
        string $headerString
    ): ?bool {
        if (!function_exists('ap_apply_filters')) {
            return null;
        }

        $handled = ap_apply_filters('ap_mail_send', null, [
            'to' => $recipients,
            'to_header' => $toHeader,
            'subject' => $subject,
            'message' => $message,
            'headers' => $headerLines,
            'header_string' => rtrim($headerString, "\r\n"),
            'transport' => self::transport(),
        ]);

        return is_bool($handled) ? $handled : null;
    }

    /**
     * @param array<string, string> $headerLines
     * @param list<string>          $recipients
     */
    private static function sendViaSmtp(
        string $toHeader,
        string $subject,
        string $message,
        string $headerString,
        array $headerLines,
        array $recipients
    ): bool {
        if (!class_exists('AP_SMTP', false)) {
            require_once __DIR__ . '/class-ap-smtp.php';
        }

        $settings = self::smtpSettings();
        if ($settings['host'] === '') {
            self::rememberError('SMTP host is not configured.');

            return false;
        }

        $from = self::envelopeFrom($headerLines);
        $rfc822 = 'To: ' . $toHeader . "\r\n"
            . 'Subject: ' . $subject . "\r\n"
            . $headerString
            . "\r\n"
            . $message;

        $ok = AP_SMTP::send($settings, $from, $recipients, $rfc822);
        if (!$ok) {
            $smtpError = AP_SMTP::lastError();
            self::rememberError($smtpError !== '' ? $smtpError : 'SMTP send failed.');

            return false;
        }
        self::rememberError('');

        return true;
    }

    /**
     * @param array<string, string> $headerLines
     */
    private static function envelopeFrom(array $headerLines): string
    {
        $from = $headerLines['From'] ?? self::fromAddress();
        if (preg_match('/<([^>]+)>/', $from, $m) === 1) {
            $from = trim($m[1]);
        }
        $from = trim($from);
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
            return $from;
        }

        return self::fromAddress();
    }

    private static function normalizeTransport(string $transport): string
    {
        $transport = strtolower(trim($transport));
        if ($transport === self::TRANSPORT_SMTP) {
            return self::TRANSPORT_SMTP;
        }

        return self::TRANSPORT_PHP;
    }

    private static function normalizeEncryption(string $encryption): string
    {
        if (!class_exists('AP_SMTP', false)) {
            require_once __DIR__ . '/class-ap-smtp.php';
        }
        $normalized = AP_SMTP::normalize([
            'host' => 'x',
            'encryption' => $encryption,
        ]);

        return $normalized['encryption'];
    }

    /**
     * Resolve a mail setting: test overlay, then ap-config.php constant, then option.
     *
     * Constants win over the options table when defined (including empty string).
     */
    private static function configValue(string $key, mixed $default, ?AP_DB $db = null): mixed
    {
        if (self::$configOverride !== null && array_key_exists($key, self::$configOverride)) {
            return self::$configOverride[$key];
        }

        $constant = self::configConstantName($key);
        if ($constant !== null && defined($constant)) {
            return constant($constant);
        }

        return self::optionValue(self::optionName($key), $default, $db);
    }

    private static function optionName(string $key): string
    {
        return match ($key) {
            'transport' => 'mail_transport',
            'from_name' => 'mail_from_name',
            'from_email' => 'mail_from_email',
            'reply_to' => 'mail_reply_to',
            default => $key,
        };
    }

    private static function optionString(string $name, string $default, ?AP_DB $db = null): string
    {
        return (string) self::optionValue($name, $default, $db);
    }

    private static function optionValue(string $name, mixed $default, ?AP_DB $db = null): mixed
    {
        if (function_exists('ap_get_option')) {
            return ap_get_option($name, $default, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        return $default;
    }

    private static function validEmail(string $email): string
    {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }

        return '';
    }

    private static function rememberError(string $message): void
    {
        self::$lastError = $message;
        if (!class_exists('AP_Options', false)) {
            return;
        }
        try {
            AP_Options::update('mail_last_error', $message, null, 'no');
        } catch (Throwable) {
            // Best-effort persistence for Settings → Mail.
        }
    }
}

require_once __DIR__ . '/class-ap-smtp.php';
