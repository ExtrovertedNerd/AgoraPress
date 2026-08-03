<?php

/**
 * AgoraPress mail helper — thin wrapper around PHP mail() with a test outbox.
 *
 * No telemetry, no external providers in core. Site admins can later filter
 * headers / swap transport via hooks (Phase 4).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Send site emails (registration, password reset, etc.).
 */
class AP_Mail
{
    /** @var list<array{to: string, subject: string, message: string, headers: string}>|null */
    private static ?array $testOutbox = null;

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
     * Send an email.
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
        $recipients = is_array($to) ? $to : [$to];
        $clean = [];
        foreach ($recipients as $addr) {
            $addr = trim((string) $addr);
            if ($addr !== '' && filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
                $clean[] = $addr;
            }
        }
        if ($clean === []) {
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

        if (self::$testOutbox !== null) {
            self::$testOutbox[] = [
                'to' => $toHeader,
                'subject' => $subject,
                'message' => $message,
                'headers' => rtrim($headerString, "\r\n"),
            ];

            return true;
        }

        // Suppress warnings from mail() on misconfigured hosts; caller checks bool.
        return @mail($toHeader, $subject, $message, $headerString);
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

        return [
            'From' => $from,
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Mailer' => 'AgoraPress',
        ];
    }

    /**
     * Site From address (admin_email fallback).
     */
    public static function fromAddress(): string
    {
        $email = '';
        if (function_exists('ap_get_option')) {
            $email = (string) ap_get_option('admin_email', '');
        } elseif (class_exists('AP_Options', false)) {
            $email = (string) AP_Options::get('admin_email', '');
        }
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            return $email;
        }

        // Last resort: noreply@hostname (still valid for local tests).
        $host = (string) (isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'localhost';

        return 'noreply@' . $host;
    }

    /**
     * Site From display name (blogname).
     */
    public static function fromName(): string
    {
        $name = '';
        if (function_exists('ap_get_option')) {
            $name = (string) ap_get_option('blogname', 'AgoraPress');
        } elseif (class_exists('AP_Options', false)) {
            $name = (string) AP_Options::get('blogname', 'AgoraPress');
        }
        $name = trim($name);

        return $name !== '' ? $name : 'AgoraPress';
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
}
