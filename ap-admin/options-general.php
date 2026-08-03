<?php

/**
 * Settings — General (site title, URLs, membership, date/time).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('manage_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

if (AP_Settings::isSaveRequest('general')) {
    if (!AP_Settings::verifyNonce('general', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $ok = AP_Options::updateGeneralSettings($_POST, $db);
        if ($ok) {
            AP_Admin::redirect(AP_Admin::url('options-general.php', ['message' => 'general_saved']));
        }
        AP_Admin::addNotice('Could not save general settings.', 'error');
        AP_Settings::flushErrorsToAdmin();
    }
}

$blogname = (string) AP_Options::get('blogname', 'AgoraPress', $db);
$blogdescription = (string) AP_Options::get('blogdescription', '', $db);
$siteurl = (string) AP_Options::get('siteurl', '', $db);
$home = (string) AP_Options::get('home', '', $db);
$adminEmail = (string) AP_Options::get('admin_email', '', $db);
$canRegister = (string) AP_Options::get('users_can_register', '0', $db) === '1';
$requireVerify = (string) AP_Options::get('require_email_verification', '1', $db) === '1';
$registrationCaptcha = strtolower(trim((string) AP_Options::get('registration_captcha', 'off', $db)));
if (!in_array($registrationCaptcha, ['off', 'math'], true)) {
    $registrationCaptcha = 'off';
}
$defaultRole = (string) AP_Options::get('default_role', 'subscriber', $db);
$timezone = (string) AP_Options::get('timezone_string', 'UTC', $db);
$wplang = (string) AP_Options::get('WPLANG', '', $db);
$dateFormat = (string) AP_Options::get('date_format', 'Y-m-d', $db);
$timeFormat = (string) AP_Options::get('time_format', 'H:i', $db);
$startOfWeek = (int) AP_Options::get('start_of_week', '1', $db);
$localeChoices = class_exists('AP_L10n', false)
    ? AP_L10n::availableLocales()
    : ['' => 'English (United States) — default'];
// Ensure the currently saved locale appears even if not in the curated list.
if ($wplang !== '' && !array_key_exists($wplang, $localeChoices)) {
    $localeChoices[$wplang] = class_exists('AP_L10n', false)
        ? AP_L10n::localeDisplayName($wplang)
        : $wplang;
}

$roles = class_exists('AP_Roles', false) ? AP_Roles::getRoleNames($db) : [
    'subscriber' => 'Subscriber',
    'contributor' => 'Contributor',
    'author' => 'Author',
    'editor' => 'Editor',
];
unset($roles['administrator']);

$datePresets = ['Y-m-d', 'F j, Y', 'm/d/Y', 'd/m/Y'];
$timePresets = ['H:i', 'g:i a', 'g:i A'];
$weekDays = [
    0 => 'Sunday',
    1 => 'Monday',
    2 => 'Tuesday',
    3 => 'Wednesday',
    4 => 'Thursday',
    5 => 'Friday',
    6 => 'Saturday',
];

// Common timezones (subset; free text still allowed via list).
$timezones = [
    'UTC',
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Los_Angeles',
    'America/Sao_Paulo',
    'Europe/London',
    'Europe/Paris',
    'Europe/Berlin',
    'Europe/Moscow',
    'Asia/Dubai',
    'Asia/Kolkata',
    'Asia/Shanghai',
    'Asia/Tokyo',
    'Australia/Sydney',
    'Pacific/Auckland',
];
if ($timezone !== '' && !in_array($timezone, $timezones, true)) {
    array_unshift($timezones, $timezone);
}

$ap_admin_title = 'General Settings';
$ap_admin_screen = 'options-general';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>General Settings</h1>
</div>

<p>Site identity, URLs, membership, and date/time preferences.</p>

<form method="post" action="" class="ap-form ap-form--settings">
    <?php AP_Settings::settingsFields('general'); ?>

    <fieldset class="ap-fieldset">
        <legend>Site identity</legend>
        <p class="ap-field">
            <label for="blogname">Site Title</label>
            <input type="text" name="blogname" id="blogname" class="regular-text"
                value="<?php echo ap_esc_attr($blogname); ?>" required>
        </p>
        <p class="ap-field">
            <label for="blogdescription">Tagline</label>
            <input type="text" name="blogdescription" id="blogdescription" class="regular-text"
                value="<?php echo ap_esc_attr($blogdescription); ?>">
            <span class="ap-help">In a few words, explain what this site is about.</span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>URLs</legend>
        <p class="ap-field">
            <label for="siteurl">AgoraPress Address (URL)</label>
            <input type="url" name="siteurl" id="siteurl" class="regular-text"
                value="<?php echo ap_esc_attr($siteurl); ?>" placeholder="https://example.com">
            <span class="ap-help">Where AgoraPress core files are installed.</span>
        </p>
        <p class="ap-field">
            <label for="home">Site Address (URL)</label>
            <input type="url" name="home" id="home" class="regular-text"
                value="<?php echo ap_esc_attr($home); ?>" placeholder="https://example.com">
            <span class="ap-help">Public front-end URL visitors use.</span>
        </p>
        <p class="ap-field">
            <label for="admin_email">Administration Email</label>
            <input type="email" name="admin_email" id="admin_email" class="regular-text"
                value="<?php echo ap_esc_attr($adminEmail); ?>" required>
            <span class="ap-help">Used for site notices and as the default From address.</span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Membership</legend>
        <p>
            <label>
                <input type="checkbox" name="users_can_register" value="1"
                    <?php echo $canRegister ? 'checked' : ''; ?>>
                Anyone can register
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="require_email_verification" value="1"
                    <?php echo $requireVerify ? 'checked' : ''; ?>>
                Require email verification for new accounts
            </label>
        </p>
        <p class="ap-field">
            <label for="registration_captcha">Registration anti-spam (CAPTCHA)</label>
            <select name="registration_captcha" id="registration_captcha">
                <option value="off" <?php echo $registrationCaptcha === 'off' ? 'selected' : ''; ?>>
                    Off (email verification / rate limits only)
                </option>
                <option value="math" <?php echo $registrationCaptcha === 'math' ? 'selected' : ''; ?>>
                    Simple math question + honeypot
                </option>
            </select>
            <span class="ap-help">
                Optional extra protection against bot sign-ups. No third-party service;
                works offline. Plugins can extend verification via hooks.
            </span>
        </p>
        <p class="ap-field">
            <label for="default_role">New User Default Role</label>
            <select name="default_role" id="default_role">
                <?php foreach ($roles as $slug => $label) : ?>
                    <option value="<?php echo ap_esc_attr((string) $slug); ?>"
                        <?php echo $defaultRole === (string) $slug ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html((string) $label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Language &amp; locale</legend>
        <p class="ap-field">
            <label for="WPLANG">Site Language</label>
            <select name="WPLANG" id="WPLANG">
                <?php foreach ($localeChoices as $code => $label) : ?>
                    <option value="<?php echo ap_esc_attr((string) $code); ?>"
                        <?php echo $wplang === (string) $code ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html((string) $label); ?>
                        <?php
                        if ((string) $code !== '' && class_exists('AP_L10n', false) && AP_L10n::isRtl((string) $code)) {
                            echo ' — RTL';
                        }
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="ap-help">
                Install language packs as <code>.mo</code> files under
                <code>ap-content/languages/</code>
                (e.g. <code>agorapress-ar.mo</code> or <code>ar.mo</code>).
                Right-to-left languages set <code>dir=&quot;rtl&quot;</code> automatically.
            </span>
        </p>
    </fieldset>

    <fieldset class="ap-fieldset">
        <legend>Date &amp; time</legend>
        <p class="ap-field">
            <label for="timezone_string">Timezone</label>
            <select name="timezone_string" id="timezone_string">
                <?php foreach ($timezones as $tz) : ?>
                    <option value="<?php echo ap_esc_attr($tz); ?>"
                        <?php echo $timezone === $tz ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html($tz); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
        <p class="ap-field">
            <label for="date_format">Date Format</label>
            <?php foreach ($datePresets as $preset) : ?>
                <label class="ap-inline-option">
                    <input type="radio" name="date_format_radio" value="<?php echo ap_esc_attr($preset); ?>"
                        <?php echo $dateFormat === $preset ? 'checked' : ''; ?>
                        onchange="document.getElementById('date_format').value=this.value">
                    <code><?php echo ap_esc_html(gmdate($preset)); ?></code>
                    <span class="ap-muted"><?php echo ap_esc_html($preset); ?></span>
                </label>
            <?php endforeach; ?>
            <input type="text" name="date_format" id="date_format" class="regular-text"
                value="<?php echo ap_esc_attr($dateFormat); ?>">
        </p>
        <p class="ap-field">
            <label for="time_format">Time Format</label>
            <?php foreach ($timePresets as $preset) : ?>
                <label class="ap-inline-option">
                    <input type="radio" name="time_format_radio" value="<?php echo ap_esc_attr($preset); ?>"
                        <?php echo $timeFormat === $preset ? 'checked' : ''; ?>
                        onchange="document.getElementById('time_format').value=this.value">
                    <code><?php echo ap_esc_html(gmdate($preset)); ?></code>
                    <span class="ap-muted"><?php echo ap_esc_html($preset); ?></span>
                </label>
            <?php endforeach; ?>
            <input type="text" name="time_format" id="time_format" class="regular-text"
                value="<?php echo ap_esc_attr($timeFormat); ?>">
        </p>
        <p class="ap-field">
            <label for="start_of_week">Week Starts On</label>
            <select name="start_of_week" id="start_of_week">
                <?php foreach ($weekDays as $n => $label) : ?>
                    <option value="<?php echo (int) $n; ?>"
                        <?php echo $startOfWeek === (int) $n ? 'selected' : ''; ?>>
                        <?php echo ap_esc_html($label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </fieldset>

    <?php AP_Settings::submitButton(); ?>
</form>

<?php
require __DIR__ . '/admin-footer.php';
