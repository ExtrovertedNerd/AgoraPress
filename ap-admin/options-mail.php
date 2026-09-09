<?php

/**
 * Settings — Mail (from identity, transport, SMTP). Own group, not General.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

$wantTest = isset($_POST['ap_mail_send_test']);
if (AP_Settings::isSaveRequest('mail') || $wantTest) {
    if (!AP_Settings::verifyNonce('mail', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateMailSettings($_POST, $db);
        if (!$ok) {
            AP_Admin::addNotice('Could not save mail settings.', 'error');
            AP_Settings::flushErrorsToAdmin();
        } elseif ($wantTest) {
            $sent = AP_Mail::sendTestToAdmin();
            if ($sent) {
                AP_Admin::redirect(AP_Admin::url('options-mail.php', ['message' => 'mail_test_sent']));
            }
            AP_Admin::addNotice('Could not send the test email.', 'error');
            $testError = AP_Mail::storedLastError();
            if ($testError !== '') {
                AP_Admin::addNotice($testError, 'error');
            }
        } else {
            AP_Admin::redirect(AP_Admin::url('options-mail.php', ['message' => 'mail_saved']));
        }
    }
}

$fromName = (string) AP_Options::get('mail_from_name', '', $db);
$fromEmail = (string) AP_Options::get('mail_from_email', '', $db);
$replyTo = (string) AP_Options::get('mail_reply_to', '', $db);
$transport = strtolower(trim((string) AP_Options::get('mail_transport', 'php', $db)));
if ($transport !== 'smtp') {
    $transport = 'php';
}
$smtpHost = (string) AP_Options::get('smtp_host', '', $db);
$smtpPort = (int) AP_Options::get('smtp_port', 587, $db);
if ($smtpPort < 1 || $smtpPort > 65535) {
    $smtpPort = 587;
}
$smtpEncryption = strtolower(trim((string) AP_Options::get('smtp_encryption', 'tls', $db)));
if (!in_array($smtpEncryption, ['none', 'tls', 'ssl'], true)) {
    $smtpEncryption = 'tls';
}
$smtpUser = (string) AP_Options::get('smtp_user', '', $db);
$hasPassword = class_exists('AP_Mail', false)
    ? AP_Mail::hasSmtpPassword()
    : (string) AP_Options::get('smtp_pass', '', $db) !== '';
$lastError = class_exists('AP_Mail', false)
    ? AP_Mail::storedLastError()
    : trim((string) AP_Options::get('mail_last_error', '', $db));

$blogname = (string) AP_Options::get('blogname', 'AgoraPress', $db);
$adminEmail = (string) AP_Options::get('admin_email', '', $db);

$ap_admin_title = 'Mail Settings';
$ap_admin_screen = 'options-mail';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Mail Settings</h1>
</div>

<p>
    Outbound mail identity and transport. From Email is separate from the
    administration email on Settings → General.
</p>

<form method="post" action="" class="ap-form ap-form--settings" autocomplete="off">
    <?php AP_Settings::settingsFields('mail'); ?>

    <fieldset class="ap-fieldset">
        <legend>From identity</legend>
        <p class="ap-field">
            <label for="mail_from_name">From Name</label>
            <input type="text" name="mail_from_name" id="mail_from_name" class="regular-text"
                value="<?php echo ap_esc_attr($fromName); ?>"
                placeholder="<?php echo ap_esc_attr($blogname); ?>">
            <span class="ap-help">Shown as the sender name. Empty uses the site title.</span>
        </p>
        <p class="ap-field">
            <label for="mail_from_email">From Email</label>
            <input type="email" name="mail_from_email" id="mail_from_email" class="regular-text"
                value="<?php echo ap_esc_attr($fromEmail); ?>"
                placeholder="<?php echo ap_esc_attr($adminEmail !== '' ? $adminEmail : 'noreply@example.com'); ?>">
            <span class="ap-help">
                Dedicated sending address such as <code>noreply@example.com</code>.
                Empty uses the administration email. Not the same field as
                Settings → General → administration email.
            </span>
        </p>
        <p class="ap-field">
            <label for="mail_reply_to">Reply-To</label>
            <input type="email" name="mail_reply_to" id="mail_reply_to" class="regular-text"
                value="<?php echo ap_esc_attr($replyTo); ?>"
                placeholder="<?php echo ap_esc_attr($adminEmail); ?>">
            <span class="ap-help">Optional. Empty uses the administration email.</span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Transport</legend>
        <p class="ap-field">
            <label for="mail_transport">Transport</label>
            <select name="mail_transport" id="mail_transport">
                <option value="php" <?php echo $transport === 'php' ? 'selected' : ''; ?>>
                    PHP mail() (default)
                </option>
                <option value="smtp" <?php echo $transport === 'smtp' ? 'selected' : ''; ?>>
                    SMTP
                </option>
            </select>
            <span class="ap-help">
                PHP mail() needs no extra setup. SMTP uses the host settings below.
            </span>
        </p>
        <p class="ap-field">
            <label for="smtp_host">SMTP Host</label>
            <input type="text" name="smtp_host" id="smtp_host" class="regular-text"
                value="<?php echo ap_esc_attr($smtpHost); ?>"
                placeholder="smtp.example.com" autocomplete="off">
        </p>
        <p class="ap-field">
            <label for="smtp_port">SMTP Port</label>
            <input type="number" name="smtp_port" id="smtp_port" class="small-text"
                min="1" max="65535" value="<?php echo (int) $smtpPort; ?>">
            <span class="ap-help">Typical ports: 587 (TLS), 465 (SSL), 25 (none).</span>
        </p>
        <p class="ap-field">
            <label for="smtp_encryption">Encryption</label>
            <select name="smtp_encryption" id="smtp_encryption">
                <option value="none" <?php echo $smtpEncryption === 'none' ? 'selected' : ''; ?>>
                    none
                </option>
                <option value="tls" <?php echo $smtpEncryption === 'tls' ? 'selected' : ''; ?>>
                    tls
                </option>
                <option value="ssl" <?php echo $smtpEncryption === 'ssl' ? 'selected' : ''; ?>>
                    ssl
                </option>
            </select>
            <span class="ap-help">
                <code>tls</code> is STARTTLS (usually port 587).
                <code>ssl</code> is SMTPS (usually port 465).
            </span>
        </p>
        <p class="ap-field">
            <label for="smtp_user">Username</label>
            <input type="text" name="smtp_user" id="smtp_user" class="regular-text"
                value="<?php echo ap_esc_attr($smtpUser); ?>"
                autocomplete="username">
        </p>
        <p class="ap-field">
            <label for="smtp_pass">Password</label>
            <input type="password" name="smtp_pass" id="smtp_pass" class="regular-text"
                value="" autocomplete="new-password">
            <span class="ap-help">
                <?php if ($hasPassword) : ?>
                    A password is stored. Leave blank to keep it.
                <?php else : ?>
                    Write-only. Leave blank to keep any stored password.
                <?php endif; ?>
            </span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Test and last error</legend>
        <p class="ap-help">
            Sends a text/plain test message to the administration email
            (<?php echo $adminEmail !== '' ? '<code>' . ap_esc_html($adminEmail) . '</code>' : 'not set'; ?>).
            Settings are saved first.
        </p>
        <?php if ($lastError !== '') : ?>
            <div class="ap-notice ap-notice--error" role="status">
                <strong>Last error:</strong>
                <?php echo ap_esc_html($lastError); ?>
            </div>
        <?php else : ?>
            <p class="ap-help">No recent mail errors.</p>
        <?php endif; ?>
    </fieldset>

    <p class="ap-form-actions">
        <button type="submit" name="ap_settings_submit" value="1" class="button button-primary">
            Save Changes
        </button>
        <button type="submit" name="ap_mail_send_test" value="1" class="button">
            Send test email to admin_email
        </button>
    </p>
</form>

<?php
require __DIR__ . '/admin-footer.php';
