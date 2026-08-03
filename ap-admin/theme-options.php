<?php

/**
 * Appearance — Theme Options (Agora color schemes).
 *
 * Selectable schemes for the default Agora theme: 3 light + 3 dark.
 * Stored as option `agora_color_scheme`.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('edit_theme_options');

AP_Admin::consumeQueryNotice();

// Ensure theme functions (color scheme API) are available.
if (class_exists('AP_Theme', false)) {
    AP_Theme::setup(ap_db());
}

$userId = ap_get_current_user_id();
$db = ap_db();
$activeTheme = class_exists('AP_Theme', false) ? AP_Theme::getStylesheet($db) : 'agora';
$isAgora = $activeTheme === 'agora' || function_exists('agora_get_color_schemes');

// --- Save ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['agora_save_theme_options'])) {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    if (!ap_check_nonce($nonce, 'agora_theme_options', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif (!function_exists('agora_set_color_scheme')) {
        AP_Admin::addNotice('Theme options are not available for the active theme.', 'error');
    } else {
        $scheme = (string) ($_POST['agora_color_scheme'] ?? '');
        if (agora_set_color_scheme($scheme, $db)) {
            AP_Admin::redirect(AP_Admin::url('theme-options.php', ['message' => 'theme_options_saved']));
        }
        AP_Admin::addNotice('Please choose a valid color scheme.', 'error');
    }
}

$schemes = function_exists('agora_get_color_schemes')
    ? agora_get_color_schemes()
    : [];
$current = function_exists('agora_get_color_scheme')
    ? agora_get_color_scheme($db)
    : 'marble';

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

<?php if (!$isAgora || $schemes === []) : ?>
    <div class="ap-notice ap-notice--info">
        Color schemes are provided by the default <strong>Agora</strong> theme.
        Activate Agora to select a scheme.
    </div>
<?php else : ?>
    <p>
        Choose one of six pure-CSS color schemes for the public site
        (3 light and 3 dark). No images are used.
    </p>

    <form method="post" action="" class="ap-theme-options-form">
        <?php
        $nonce = ap_create_nonce('agora_theme_options', $userId > 0 ? $userId : null);
        echo '<input type="hidden" name="_ap_nonce" value="' . ap_esc_attr($nonce) . '">';
        echo '<input type="hidden" name="agora_save_theme_options" value="1">';
        ?>

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

        <p class="ap-submit-row" style="margin-top:1.25rem;">
            <button type="submit" class="button button-primary">Save changes</button>
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
<?php endif; ?>

<?php
require __DIR__ . '/admin-footer.php';
