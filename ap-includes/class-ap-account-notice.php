<?php

/**
 * Account security notices.
 *
 * A password change emails the address that was on the account. An email
 * change emails the previous address and sends a confirmation to the new
 * one. Each message says what changed, when (UTC), and the client IP, and
 * what to do if the reader did not make the change. It never includes the
 * password, the hash, or a reset key.
 *
 * Mail uses {@see AP_Mail::send()} and does not consume the verification /
 * reset rate bucket (`skip_rate_limit`). A failed or skipped send does not
 * undo the change. When the mail class is not loaded, the notice is
 * skipped. Under PHPUnit, the live transport is not opened unless the
 * in-memory outbox is on.
 *
 * When outbound mail is disabled (`none`, `off`, or `disabled`) or not
 * configured (SMTP with an empty host, or PHP `mail()` unavailable), the
 * notice is not sent. The reason is written to the mail last-error and to
 * the PHP error log. That line has no password and no mailbox.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Plain-text notices for credential changes on an account.
 */
class AP_Account_Notice
{
    /**
     * Email the account after a password change.
     *
     * `$to` is the address on the account before this save. Pass that previous
     * address when the same request also changes the email, so this notice
     * still reaches the inbox that was on the account. Null uses the address
     * currently stored on `$user`.
     *
     * Returns false when the notice is skipped or the send fails. Callers
     * keep the password change either way.
     *
     * @param string|null $to Previous account address. Null uses `$user->user_email`.
     * @param string|null $ip Client IP. Null reads the request IP.
     */
    public static function notifyPasswordChanged(
        AP_User $user,
        ?AP_DB $db = null,
        ?string $to = null,
        ?string $ip = null
    ): bool {
        try {
            return self::deliverPasswordChanged($user, $db, $to, $ip);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string|null $to
     * @param string|null $ip
     */
    private static function deliverPasswordChanged(
        AP_User $user,
        ?AP_DB $db,
        ?string $to,
        ?string $ip
    ): bool {
        if (!self::shouldAttemptSend()) {
            return false;
        }

        $recipient = AP_User::sanitizeEmail($to ?? (string) $user->user_email);
        if (!AP_User::isValidEmail($recipient)) {
            return false;
        }
        if (self::blockedByMailSettings($db, 'password change notice')) {
            return false;
        }

        self::warmMailOptions($db);

        $site = self::siteName($db);
        $when = gmdate('Y-m-d H:i:s');
        $shownIp = self::requestIp($ip);
        $login = self::oneLine($user->user_login);
        if ($login === '') {
            $login = 'there';
        }

        $subject = sprintf('[%s] Your password was changed', $site);
        $message = "Hello {$login},\r\n\r\n"
            . "The password for your account on {$site} was changed.\r\n\r\n"
            . "What changed: password\r\n"
            . "When: {$when} UTC\r\n"
            . "IP address: {$shownIp}\r\n\r\n"
            . "If you changed your password, you can ignore this email.\r\n\r\n"
            . "If you did not make this change, reset your password"
            . " and contact the site administrator.\r\n\r\n"
            . self::recoveryBlock($db)
            . "This message does not include your password.\r\n";

        // Do not share the verification / reset mail bucket. One notice per
        // change must not be dropped because that bucket is already full.
        return self::sendPlain($recipient, $subject, $message);
    }

    /**
     * Email the previous address and confirm the new one after an email change.
     *
     * `$previousEmail` is the address stored before this save. `$newEmail`
     * null uses the address currently on `$user`. The same mailbox
     * (comparison is case-insensitive) sends nothing, including a change of
     * letter case only. The previous message tells that inbox the address
     * changed and that password reset mail now goes to the new address. The
     * new message confirms the address is on the account. Neither message
     * includes a password or a reset key.
     *
     * Returns true only when every valid recipient was mailed. Callers keep
     * the email change when this returns false. A missing previous address
     * sends nothing, so a caller cannot confirm an address that did not change.
     *
     * @param string|null $previousEmail Address before the save.
     * @param string|null $newEmail      Address after the save. Null uses `$user->user_email`.
     * @param string|null $ip            Client IP. Null reads the request IP.
     */
    public static function notifyEmailChanged(
        AP_User $user,
        ?AP_DB $db = null,
        ?string $previousEmail = null,
        ?string $newEmail = null,
        ?string $ip = null
    ): bool {
        try {
            return self::deliverEmailChanged($user, $db, $previousEmail, $newEmail, $ip);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string|null $previousEmail
     * @param string|null $newEmail
     * @param string|null $ip
     */
    private static function deliverEmailChanged(
        AP_User $user,
        ?AP_DB $db,
        ?string $previousEmail,
        ?string $newEmail,
        ?string $ip
    ): bool {
        if ($previousEmail === null || !self::shouldAttemptSend()) {
            return false;
        }

        $previous = self::noticeAddress($previousEmail);
        $current = self::noticeAddress($newEmail ?? (string) $user->user_email);
        if ($previous === '' && $current === '') {
            return false;
        }
        if ($previous !== '' && $current !== '' && strcasecmp($previous, $current) === 0) {
            return false;
        }
        if (self::blockedByMailSettings($db, 'email change notice')) {
            return false;
        }

        self::warmMailOptions($db);

        $site = self::siteName($db);
        $when = gmdate('Y-m-d H:i:s');
        $shownIp = self::requestIp($ip);
        $login = self::oneLine($user->user_login);
        if ($login === '') {
            $login = 'there';
        }
        $shownPrevious = $previous !== '' ? $previous : 'unknown';
        $shownCurrent = $current !== '' ? $current : 'unknown';
        $facts = "What changed: email address\r\n"
            . "Previous address: {$shownPrevious}\r\n"
            . "New address: {$shownCurrent}\r\n"
            . "When: {$when} UTC\r\n"
            . "IP address: {$shownIp}\r\n";

        $sent = 0;
        $failed = 0;
        $previousNotified = false;

        if ($previous !== '') {
            $subject = sprintf('[%s] Your email address was changed', $site);
            $message = "Hello {$login},\r\n\r\n"
                . "The email address for your account on {$site} was changed.\r\n\r\n"
                . $facts . "\r\n"
                . "If you changed your email address, you can ignore this email.\r\n\r\n"
                . "If you did not make this change, contact the site administrator.\r\n"
                . "Password reset messages are sent to the new address.\r\n\r\n"
                . "This message does not include your password.\r\n";
            if (self::trySend($previous, $subject, $message)) {
                $sent++;
                $previousNotified = true;
            } else {
                $failed++;
            }
        }

        if ($current !== '') {
            $previousLine = '';
            if ($previous !== '') {
                $previousLine = $previousNotified
                    ? "The previous address was also notified.\r\n"
                    : "The previous address could not be notified.\r\n";
            }
            $subject = sprintf('[%s] Your new email address is confirmed', $site);
            $message = "Hello {$login},\r\n\r\n"
                . "This message confirms that this address is now the email"
                . " for your account on {$site}.\r\n\r\n"
                . $facts . "\r\n"
                . "If you made this change, you can ignore this email.\r\n\r\n"
                . "If you did not make this change, do not use this account"
                . " and contact the site administrator.\r\n"
                . $previousLine . "\r\n"
                . "This message does not include your password.\r\n";
            if (self::trySend($current, $subject, $message)) {
                $sent++;
            } else {
                $failed++;
            }
        }

        return $sent > 0 && $failed === 0;
    }

    /**
     * One plain-text security notice. Does not use the mail rate bucket.
     */
    private static function sendPlain(string $recipient, string $subject, string $message): bool
    {
        return AP_Mail::send($recipient, $subject, $message, [], [
            'skip_rate_limit' => true,
        ]);
    }

    /**
     * Send one notice. A transport error does not stop the other recipient.
     */
    private static function trySend(string $recipient, string $subject, string $message): bool
    {
        try {
            return self::sendPlain($recipient, $subject, $message);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Valid mailbox for a notice body and recipient, or an empty string.
     */
    private static function noticeAddress(string $email): string
    {
        $email = self::oneLine(AP_User::sanitizeEmail($email));
        if (!AP_User::isValidEmail($email)) {
            return '';
        }

        return $email;
    }

    /**
     * Production attempts a send when mail is loaded.
     * PHPUnit does not open the live transport unless the test outbox is on.
     * Disabled or unconfigured mail is handled by {@see blockedByMailSettings()}.
     */
    private static function shouldAttemptSend(): bool
    {
        if (!class_exists('AP_Mail', false)) {
            return false;
        }
        if (defined('PHPUNIT_COMPOSER_INSTALL') && !AP_Mail::isTestMode()) {
            return false;
        }

        return true;
    }

    /**
     * Skip and log when site mail settings disallow outbound mail.
     *
     * Does not call {@see AP_Mail::send()}. The log line is the fixed reason
     * plus `$context`. It does not include a password or a mailbox.
     */
    private static function blockedByMailSettings(?AP_DB $db, string $context): bool
    {
        $reason = AP_Mail::outboundSkipReason($db);
        if ($reason === null || $reason === '') {
            return false;
        }

        AP_Mail::logOutboundSkip($reason, $context, $db);

        return true;
    }

    /**
     * Read mail settings through `$db` so a later {@see AP_Mail::send()}
     * hits the request cache instead of the global connection.
     */
    private static function warmMailOptions(?AP_DB $db): void
    {
        if (!class_exists('AP_Options', false)) {
            return;
        }

        foreach (
            [
                'blogname',
                'admin_email',
                'mail_from_name',
                'mail_from_email',
                'mail_reply_to',
                'mail_transport',
            ] as $name
        ) {
            AP_Options::get($name, '', $db);
        }
    }

    private static function siteName(?AP_DB $db): string
    {
        $name = 'AgoraPress';
        if (class_exists('AP_Options', false)) {
            $name = trim((string) AP_Options::get('blogname', 'AgoraPress', $db));
        } elseif (function_exists('ap_get_option')) {
            $name = trim((string) ap_get_option('blogname', 'AgoraPress', $db));
        }

        $name = self::oneLine($name);

        return $name !== '' ? $name : 'AgoraPress';
    }

    /**
     * Public lost-password form. No key and no password are added to the URL.
     */
    private static function recoveryBlock(?AP_DB $db): string
    {
        if (!class_exists('AP_Registration', false)) {
            return '';
        }

        $url = AP_Registration::loginActionUrl('lostpassword', [], $db);
        $url = self::oneLine($url);
        if ($url === '') {
            return '';
        }

        return "To choose a new password, visit:\r\n{$url}\r\n\r\n";
    }

    /**
     * Client IP for the message. Invalid values are reported as unknown.
     */
    private static function requestIp(?string $ip): string
    {
        if ($ip === null) {
            if (class_exists('AP_Rate_Limit', false)) {
                $ip = AP_Rate_Limit::clientIp();
            } elseif (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            } else {
                $ip = '';
            }
        }

        $ip = trim($ip);
        if (class_exists('AP_Rate_Limit', false)) {
            $ip = AP_Rate_Limit::sanitizeIp($ip);
        } elseif ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $ip = '';
        }

        return $ip !== '' ? $ip : 'unknown';
    }

    private static function oneLine(string $value): string
    {
        $value = str_replace(["\r", "\n", "\0"], '', $value);

        return trim($value);
    }
}
