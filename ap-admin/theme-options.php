<?php

/**
 * Appearance — Theme Options.
 *
 * Core always offers Additional CSS. Themes register extra sections/fields via
 * the Settings API on page/group {@see AP_Theme::THEME_OPTIONS_PAGE} /
 * {@see AP_Theme::THEME_OPTIONS_GROUP}, typically on the `ap_theme_options_register`
 * action (WordPress-style register_setting / add_settings_section /
 * add_settings_field). The default Agora theme also exposes color schemes here.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('edit_theme_options');

AP_Admin::consumeQueryNotice();

$userId = ap_get_current_user_id();
$db = ap_db();

// Load active theme functions.php, then let themes register Settings API UI.
if (class_exists('AP_Theme', false)) {
    AP_Theme::registerThemeOptions($db);
}

$activeTheme = class_exists('AP_Theme', false) ? AP_Theme::getStylesheet($db) : 'agora';
$isAgora = $activeTheme === 'agora' && function_exists('agora_get_color_schemes');
$hasThemeSettings = class_exists('AP_Theme', false) && AP_Theme::hasRegisteredThemeOptions();

$settingsGroup = class_exists('AP_Theme', false)
    ? AP_Theme::THEME_OPTIONS_GROUP
    : 'theme_options';
$settingsPage = class_exists('AP_Theme', false)
    ? AP_Theme::THEME_OPTIONS_PAGE
    : 'theme_options';

// --- Save ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (
    isset($_POST['ap_settings_submit'])
    || isset($_POST['agora_save_theme_options'])
    || (string) ($_POST['option_page'] ?? '') === $settingsGroup
)) {
    $nonceOk = false;
    if (class_exists('AP_Settings', false) && AP_Settings::verifyNonce($settingsGroup, $userId > 0 ? $userId : null)) {
        $nonceOk = true;
    } else {
        // Legacy nonce name (pre–Settings-API Theme Options form).
        $nonce = (string) ($_POST['_ap_nonce'] ?? '');
        $nonceOk = ap_check_nonce($nonce, 'agora_theme_options', $userId > 0 ? $userId : null)
            || ap_check_nonce($nonce, 'ap_theme_options', $userId > 0 ? $userId : null);
    }

    if (!$nonceOk) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } else {
        $schemeOk = true;
        if (function_exists('agora_set_color_scheme') && $isAgora) {
            $scheme = (string) ($_POST['agora_color_scheme'] ?? '');
            if ($scheme !== '') {
                $schemeOk = agora_set_color_scheme($scheme, $db);
                if (!$schemeOk) {
                    AP_Admin::addNotice('Please choose a valid color scheme.', 'error');
                }
            }
        }

        $settingsOk = true;
        if (class_exists('AP_Settings', false)) {
            $settingsOk = AP_Settings::save($settingsGroup, null, $db);
            if (!$settingsOk) {
                AP_Settings::flushErrorsToAdmin();
            }
        }

        $cssOk = true;
        if (class_exists('AP_Theme', false) && array_key_exists('custom_css', $_POST)) {
            $cssOk = AP_Theme::updateCustomCss((string) $_POST['custom_css'], $db);
            if (!$cssOk) {
                AP_Admin::addNotice('Could not save Additional CSS.', 'error');
            }
        }

        if ($schemeOk && $settingsOk && $cssOk) {
            AP_Admin::redirect(AP_Admin::url('theme-options.php', ['message' => 'theme_options_saved']));
        }
    }
}

$schemes = $isAgora && function_exists('agora_get_color_schemes')
    ? agora_get_color_schemes()
    : [];
$current = function_exists('agora_get_color_scheme')
    ? agora_get_color_scheme($db)
    : 'marble';
$customCss = class_exists('AP_Theme', false)
    ? AP_Theme::getCustomCss($db)
    : '';

// Preview swatches (approximate; pure CSS admin previews).
$swatches = [
    'marble' => ['#f4f5f7', '#ffffff', '#2f5eb8', '#1c1f26'],
    'parchment' => ['#f6f0e4', '#fffaf0', '#9a4a2a', '#3a2f24'],
    'cloud' => ['#eef4f8', '#fbfcfe', '#0b7ea4', '#1a2a36'],
    'obsidian' => ['#0c0c10', '#14141c', '#b794f6', '#ece8f4'],
    'midnight' => ['#0a1220', '#0f1a2c', '#5ec8ff', '#e2eaf4'],
    'charcoal' => ['#1a1816', '#221f1c', '#e8b86d', '#f0ebe4'],
];

$ap_admin_title = 'Theme Options';
$ap_admin_screen = 'theme-options';
require __DIR__ . '/admin-header.php';
?>
<div class="ap-page-header">
    <h1>Theme Options</h1>
</div>

<?php if ($activeTheme !== '') : ?>
    <p class="description">
        Active theme: <strong><?php echo ap_esc_html($activeTheme); ?></strong>.
        Themes can register extra options below via the Settings API
        (<code>ap_theme_options_register</code>).
    </p>
<?php endif; ?>

<form method="post" action="" class="ap-theme-options-form">
    <?php
    if (function_exists('ap_settings_fields')) {
        ap_settings_fields($settingsGroup);
    } else {
        $nonce = ap_create_nonce('ap_theme_options', $userId > 0 ? $userId : null);
        echo '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">';
        echo '<input type="hidden" name="option_page" value="' . ap_esc_attr($settingsGroup) . '">';
    }
    // Backward-compatible flag for older tests/forms.
    echo '<input type="hidden" name="agora_save_theme_options" value="1">';
    ?>

<?php if ($isAgora && $schemes !== []) : ?>
    <p>
        Choose one of six pure-CSS color schemes for the public site
        (3 light and 3 dark). No images are used.
    </p>

        <fieldset class="ap-scheme-fieldset">
            <legend class="screen-reader-text">Color scheme</legend>
            <div class="ap-scheme-grid">
                <?php foreach ($schemes as $slug => $meta) : ?>
                    <?php
                    $id = 'scheme-' . $slug;
                    $checked = $current === $slug ? ' checked' : '';
                    $mode = (string) ($meta['mode'] ?? 'light');
                    $label = (string) ($meta['label'] ?? $slug);
                    $desc = (string) ($meta['description'] ?? '');
                    $colors = $swatches[$slug] ?? ['#ccc', '#fff', '#06c', '#111'];
                    $cardClass = 'ap-scheme-card' . ($checked !== '' ? ' is-selected' : '');
                    ?>
                    <label
                        class="<?php echo ap_esc_attr($cardClass); ?>"
                        for="<?php echo ap_esc_attr($id); ?>"
                    >
                        <input
                            type="radio"
                            name="agora_color_scheme"
                            id="<?php echo ap_esc_attr($id); ?>"
                            value="<?php echo ap_esc_attr($slug); ?>"
                            <?php echo $checked; ?>
                        >
                        <span class="ap-scheme-swatch" aria-hidden="true" style="
                            --s0: <?php echo ap_esc_attr($colors[0]); ?>;
                            --s1: <?php echo ap_esc_attr($colors[1]); ?>;
                            --s2: <?php echo ap_esc_attr($colors[2]); ?>;
                            --s3: <?php echo ap_esc_attr($colors[3]); ?>;
                        ">
                            <span class="ap-scheme-swatch__bg"></span>
                            <span class="ap-scheme-swatch__card"></span>
                            <span class="ap-scheme-swatch__accent"></span>
                        </span>
                        <span class="ap-scheme-meta">
                            <span class="ap-scheme-name"><?php echo ap_esc_html($label); ?></span>
                            <span class="ap-scheme-mode ap-scheme-mode--<?php echo ap_esc_attr($mode); ?>">
                                <?php echo ap_esc_html(ucfirst($mode)); ?>
                            </span>
                        </span>
                        <?php if ($desc !== '') : ?>
                            <span class="ap-scheme-desc"><?php echo ap_esc_html($desc); ?></span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
<?php elseif (!$hasThemeSettings) : ?>
    <div class="ap-notice ap-notice--info">
        This theme has not registered any custom options yet.
        Theme authors can use <code>ap_register_setting</code>,
        <code>ap_add_settings_section</code>, and <code>ap_add_settings_field</code>
        on the <code>ap_theme_options_register</code> action (page/group
        <code><?php echo ap_esc_html($settingsPage); ?></code>).
        Additional CSS below works with any theme.
    </div>
<?php endif; ?>

<?php
// Theme-registered Settings API sections/fields (any active theme).
if (function_exists('ap_do_settings_sections') && $hasThemeSettings) {
    echo '<div class="ap-theme-registered-options" style="margin-top:1.5rem;">';
    ap_do_settings_sections($settingsPage);
    echo '</div>';
}
?>

    <fieldset class="ap-fieldset ap-custom-css-fieldset" style="margin-top:1.75rem;">
        <legend><strong>Additional CSS</strong></legend>
        <p class="ap-help">
            Add custom CSS rules for the public site without editing theme files.
            Rules are printed on every front-end page. Keep selectors specific to avoid
            clobbering core or theme styles.
        </p>
        <p class="ap-field">
            <label class="screen-reader-text" for="custom_css">Additional CSS</label>
            <textarea
                name="custom_css"
                id="custom_css"
                class="large-text code ap-custom-css-textarea"
                rows="12"
                spellcheck="false"
                placeholder="/* Example:&#10;.site-header { border-bottom: 2px solid #2271b1; } */"
            ><?php echo ap_esc_textarea($customCss); ?></textarea>
        </p>
        <p class="description">
            Maximum size <?php echo (int) (AP_Theme::CUSTOM_CSS_MAX_BYTES / 1024); ?> KiB.
            Do not paste full HTML documents — CSS only.
        </p>
    </fieldset>

    <p class="ap-submit-row" style="margin-top:1.25rem;">
        <button type="submit" name="ap_settings_submit" value="1" class="button button-primary">Save changes</button>
    </p>
</form>

<style>
    .ap-scheme-fieldset { border: 0; margin: 0; padding: 0; min-inline-size: 0; }
    .ap-scheme-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(11.5rem, 1fr));
        gap: 0.85rem;
        margin-top: 0.75rem;
    }
    .ap-scheme-card {
        display: flex;
        flex-direction: column;
        gap: 0.45rem;
        padding: 0.75rem;
        border: 2px solid var(--ap-border, #c3c4c7);
        border-radius: 8px;
        background: var(--ap-surface, #fff);
        cursor: pointer;
        transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .ap-scheme-card:hover { border-color: var(--ap-primary, #2271b1); }
    .ap-scheme-card.is-selected,
    .ap-scheme-card:has(input:checked) {
        border-color: var(--ap-primary, #2271b1);
        box-shadow: 0 0 0 1px var(--ap-primary, #2271b1);
    }
    .ap-scheme-card input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }
    .ap-scheme-swatch {
        display: block;
        position: relative;
        height: 4.25rem;
        border-radius: 6px;
        overflow: hidden;
        border: 1px solid rgb(0 0 0 / 0.08);
        background: var(--s0);
    }
    .ap-scheme-swatch__bg { position: absolute; inset: 0; background: var(--s0); }
    .ap-scheme-swatch__card {
        position: absolute;
        left: 12%; right: 12%; top: 28%; bottom: 18%;
        background: var(--s1);
        border-radius: 4px;
        box-shadow: 0 1px 3px rgb(0 0 0 / 0.12);
    }
    .ap-scheme-swatch__accent {
        position: absolute;
        left: 18%;
        top: 40%;
        width: 36%;
        height: 0.35rem;
        border-radius: 2px;
        background: var(--s2);
    }
    .ap-scheme-meta {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.35rem;
    }
    .ap-scheme-name { font-weight: 600; color: var(--ap-text, #1d2327); }
    .ap-scheme-mode {
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        padding: 0.15rem 0.4rem;
        border-radius: 999px;
        background: #e8f0fe;
        color: #1a4d8c;
    }
    .ap-scheme-mode--dark {
        background: #1e1e2a;
        color: #d0c8f0;
    }
    .ap-scheme-desc {
        font-size: 0.82rem;
        color: var(--ap-muted, #646970);
        line-height: 1.35;
    }
    .ap-custom-css-textarea {
        width: 100%;
        max-width: 48rem;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 0.85rem;
        line-height: 1.45;
        tab-size: 2;
    }
    .ap-theme-registered-options .ap-form-table {
        width: 100%;
        max-width: 56rem;
        border-collapse: collapse;
    }
    .ap-theme-registered-options .ap-form-table th {
        text-align: left;
        vertical-align: top;
        padding: 0.65rem 1rem 0.65rem 0;
        width: 12rem;
        font-weight: 600;
    }
    .ap-theme-registered-options .ap-form-table td {
        padding: 0.65rem 0;
        vertical-align: top;
    }
    .ap-theme-registered-options .ap-settings-section-title {
        margin: 1.5rem 0 0.5rem;
        font-size: 1.15rem;
    }
    /* ExtrovertedNerd / generic multi-card theme fields */
    .ap-en-projects-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
        gap: 1rem;
        margin-top: 0.5rem;
    }
    .ap-en-project-card {
        padding: 0.85rem 1rem;
        border: 1px solid var(--ap-border, #c3c4c7);
        border-radius: 8px;
        background: var(--ap-surface, #fff);
    }
    .ap-en-project-card__title {
        margin: 0 0 0.65rem;
        font-size: 0.95rem;
    }
    .ap-en-project-card .ap-field {
        margin: 0 0 0.55rem;
    }
    .ap-en-project-card .ap-field:last-child {
        margin-bottom: 0;
    }
    .ap-en-project-card label {
        display: block;
        font-weight: 600;
        font-size: 0.8rem;
        margin-bottom: 0.2rem;
    }
    .ap-en-project-card input[type="text"],
    .ap-en-project-card select {
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
    }
    .ap-en-project-card__row {
        display: grid;
        grid-template-columns: 1fr minmax(4rem, 5.5rem);
        gap: 0.5rem;
    }
    .screen-reader-text {
        position: absolute;
        width: 1px;
        height: 1px;
        padding: 0;
        margin: -1px;
        overflow: hidden;
        clip: rect(0, 0, 0, 0);
        white-space: nowrap;
        border: 0;
    }
</style>

<?php
require __DIR__ . '/admin-footer.php';
