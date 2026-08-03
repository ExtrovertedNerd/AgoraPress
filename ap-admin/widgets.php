<?php

/**
 * Appearance — Widgets / modular areas.
 *
 * Assign available widgets to theme-registered sidebars (regions).
 * Simple form UI (add / configure / remove / reorder); drag-and-drop later.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

AP_Admin::requireCapability('edit_theme_options');

AP_Admin::consumeQueryNotice();

// Load theme so sidebars registered in functions.php are available.
if (class_exists('AP_Theme', false)) {
    AP_Theme::setup(ap_db());
}

// Ensure built-in widget types exist even if bootstrap path was partial.
if (class_exists('AP_Widgets', false)) {
    AP_Widgets::registerCore();
}

$userId = ap_get_current_user_id();
$db = ap_db();

$sidebars = AP_Widgets::getSidebars();
// Sensible defaults when the theme has not registered any yet.
if ($sidebars === []) {
    AP_Widgets::registerSidebar('sidebar-1', [
        'name' => 'Primary Sidebar',
        'description' => 'Main sidebar area beside content.',
    ]);
    AP_Widgets::registerSidebar('footer-1', [
        'name' => 'Footer',
        'description' => 'Footer modular area.',
    ]);
    $sidebars = AP_Widgets::getSidebars();
}

$widgetTypes = AP_Widgets::getWidgetTypes();

// --- Actions ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $nonce = (string) ($_POST['_ap_nonce'] ?? '');
    $action = (string) ($_POST['ap_widget_action'] ?? '');

    if (!ap_check_nonce($nonce, 'ap_widgets', $userId > 0 ? $userId : null)) {
        AP_Admin::addNotice('Security check failed. Please try again.', 'error');
    } elseif ($action === 'add') {
        $idBase = AP_Widgets::sanitizeId((string) ($_POST['widget_type'] ?? ''));
        $sidebarId = AP_Widgets::sanitizeId((string) ($_POST['sidebar_id'] ?? ''));
        if ($idBase === '' || !AP_Widgets::isRegisteredWidget($idBase)) {
            AP_Admin::addNotice('Unknown widget type.', 'error');
        } elseif ($sidebarId === '' || !AP_Widgets::isRegisteredSidebar($sidebarId)) {
            AP_Admin::addNotice('Unknown modular area.', 'error');
        } else {
            $instance = [];
            $type = AP_Widgets::getWidgetType($idBase);
            $fields = is_array($type['form_fields'] ?? null) ? $type['form_fields'] : [];
            foreach ($fields as $key => $_field) {
                if (!is_string($key)) {
                    continue;
                }
                $fieldName = 'widget_' . $key;
                if (isset($_POST[$fieldName])) {
                    $instance[$key] = $_POST[$fieldName];
                }
            }
            $newId = AP_Widgets::addWidget($idBase, $sidebarId, $instance, $db);
            if ($newId !== false) {
                AP_Admin::redirect(AP_Admin::url('widgets.php', [
                    'message' => 'widget_added',
                    'focus' => $newId,
                ]));
            }
            AP_Admin::addNotice('Could not add the widget.', 'error');
        }
    } elseif ($action === 'save') {
        $widgetId = AP_Widgets::sanitizeWidgetId((string) ($_POST['widget_id'] ?? ''));
        $sidebarId = AP_Widgets::sanitizeId((string) ($_POST['sidebar_id'] ?? ''));
        if ($widgetId === '' || AP_Widgets::parseWidgetId($widgetId) === null) {
            AP_Admin::addNotice('Invalid widget.', 'error');
        } else {
            $parsed = AP_Widgets::parseWidgetId($widgetId);
            $type = $parsed !== null ? AP_Widgets::getWidgetType($parsed['id_base']) : null;
            $instance = [];
            $fields = is_array($type['form_fields'] ?? null) ? $type['form_fields'] : [];
            foreach ($fields as $key => $field) {
                if (!is_string($key) || !is_array($field)) {
                    continue;
                }
                $fieldType = (string) ($field['type'] ?? 'text');
                $fieldName = 'widget_' . $key;
                if ($fieldType === 'checkbox') {
                    $instance[$key] = !empty($_POST[$fieldName]) ? 1 : 0;
                } elseif (isset($_POST[$fieldName])) {
                    $instance[$key] = $_POST[$fieldName];
                }
            }
            if (AP_Widgets::saveInstance($widgetId, $instance, $db)) {
                // Optional move to another area.
                if ($sidebarId !== '' && AP_Widgets::isRegisteredSidebar($sidebarId)) {
                    $currentSide = '';
                    foreach (AP_Widgets::getSidebarsWidgets($db) as $sid => $list) {
                        if (in_array($widgetId, $list, true)) {
                            $currentSide = $sid;
                            break;
                        }
                    }
                    if ($currentSide !== $sidebarId) {
                        AP_Widgets::moveWidget($widgetId, $sidebarId, null, $db);
                    }
                }
                AP_Admin::redirect(AP_Admin::url('widgets.php', [
                    'message' => 'widget_saved',
                    'focus' => $widgetId,
                ]));
            }
            AP_Admin::addNotice('Could not save the widget.', 'error');
        }
    } elseif ($action === 'remove') {
        $widgetId = AP_Widgets::sanitizeWidgetId((string) ($_POST['widget_id'] ?? ''));
        if ($widgetId !== '' && AP_Widgets::removeWidget($widgetId, $db)) {
            AP_Admin::redirect(AP_Admin::url('widgets.php', ['message' => 'widget_removed']));
        }
        AP_Admin::addNotice('Could not remove the widget.', 'error');
    } elseif ($action === 'move') {
        $widgetId = AP_Widgets::sanitizeWidgetId((string) ($_POST['widget_id'] ?? ''));
        $sidebarId = AP_Widgets::sanitizeId((string) ($_POST['sidebar_id'] ?? ''));
        $direction = (string) ($_POST['direction'] ?? '');
        if ($widgetId === '' || $sidebarId === '') {
            AP_Admin::addNotice('Invalid move request.', 'error');
        } else {
            $list = AP_Widgets::getWidgetsForSidebar($sidebarId, $db);
            $idx = array_search($widgetId, $list, true);
            if ($idx === false) {
                AP_Admin::addNotice('Widget not found in that area.', 'error');
            } else {
                $swap = $direction === 'up' ? $idx - 1 : $idx + 1;
                if ($swap >= 0 && $swap < count($list)) {
                    $tmp = $list[$swap];
                    $list[$swap] = $list[$idx];
                    $list[$idx] = $tmp;
                    AP_Widgets::reorderSidebar($sidebarId, $list, $db);
                }
                AP_Admin::redirect(AP_Admin::url('widgets.php', [
                    'message' => 'widget_moved',
                    'focus' => $widgetId,
                ]));
            }
        }
    }
}

// Notices from redirects.
$message = (string) ($_GET['message'] ?? '');
if ($message === 'widget_added') {
    AP_Admin::addNotice('Widget added.', 'success');
} elseif ($message === 'widget_saved') {
    AP_Admin::addNotice('Widget saved.', 'success');
} elseif ($message === 'widget_removed') {
    AP_Admin::addNotice('Widget removed.', 'success');
} elseif ($message === 'widget_moved') {
    AP_Admin::addNotice('Widget order updated.', 'success');
}

$focus = AP_Widgets::sanitizeWidgetId((string) ($_GET['focus'] ?? ''));
$map = AP_Widgets::getSidebarsWidgets($db);

$ap_admin_title = 'Widgets';
$ap_admin_screen = 'widgets';
require __DIR__ . '/admin-header.php';

/**
 * Render form fields for a widget type.
 *
 * @param array<string, mixed> $type
 * @param array<string, mixed> $instance
 */
$renderFields = static function (array $type, array $instance, string $prefix, ?AP_DB $db): void {
    $fields = is_array($type['form_fields'] ?? null) ? $type['form_fields'] : [];
    foreach ($fields as $key => $field) {
        if (!is_string($key) || !is_array($field)) {
            continue;
        }
        $fieldType = (string) ($field['type'] ?? 'text');
        $label = (string) ($field['label'] ?? $key);
        $name = $prefix . $key;
        $id = $name . '-' . md5($prefix . $key);
        $value = $instance[$key] ?? ($type['defaults'][$key] ?? '');

        if ($fieldType === 'checkbox') {
            echo '<p class="ap-field ap-field--checkbox">';
            echo '<label for="' . ap_esc_attr($id) . '">';
            echo '<input type="checkbox" name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id) . '" value="1"'
                . (!empty($value) ? ' checked' : '') . '> ';
            echo ap_esc_html($label);
            echo '</label></p>';
            continue;
        }

        echo '<p class="ap-field">';
        echo '<label for="' . ap_esc_attr($id) . '">' . ap_esc_html($label) . '</label>';
        if ($fieldType === 'textarea') {
            echo '<textarea name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id)
                . '" rows="4" class="ap-input-wide">' . ap_esc_html((string) $value) . '</textarea>';
        } elseif ($fieldType === 'number') {
            $min = isset($field['min']) ? (int) $field['min'] : 0;
            $max = isset($field['max']) ? (int) $field['max'] : 999;
            echo '<input type="number" name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id)
                . '" value="' . ap_esc_attr((string) (int) $value) . '" min="' . $min . '" max="' . $max . '">';
        } elseif ($fieldType === 'select') {
            $options = [];
            if (isset($field['options']) && is_array($field['options'])) {
                $options = $field['options'];
            } elseif (isset($field['options_callback']) && is_callable($field['options_callback'])) {
                $cbOut = call_user_func($field['options_callback'], $db);
                if (is_array($cbOut)) {
                    $options = $cbOut;
                }
            }
            echo '<select name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id) . '">';
            foreach ($options as $optVal => $optLabel) {
                $sel = (string) $optVal === (string) $value ? ' selected' : '';
                echo '<option value="' . ap_esc_attr((string) $optVal) . '"' . $sel . '>'
                    . ap_esc_html((string) $optLabel) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="text" name="' . ap_esc_attr($name) . '" id="' . ap_esc_attr($id)
                . '" value="' . ap_esc_attr((string) $value) . '" maxlength="200" class="ap-input-wide">';
        }
        echo '</p>';
    }
};
?>
<div class="ap-page-header">
    <h1>Widgets</h1>
</div>

<p>
    Place modular widgets into theme areas (sidebars, footer, etc.).
    Themes register areas; plugins can register additional widget types.
</p>

<div class="ap-widgets-layout">
    <aside class="ap-widgets-available">
        <h2>Available widgets</h2>
        <?php if ($widgetTypes === []) : ?>
            <p class="ap-help">No widget types registered.</p>
        <?php else : ?>
            <ul class="ap-widget-type-list">
                <?php foreach ($widgetTypes as $idBase => $type) : ?>
                    <li>
                        <strong><?php echo ap_esc_html((string) $type['name']); ?></strong>
                        <?php if ((string) ($type['description'] ?? '') !== '') : ?>
                            <span class="ap-help"><?php echo ap_esc_html((string) $type['description']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <h2>Add a widget</h2>
        <?php if ($sidebars !== [] && $widgetTypes !== []) : ?>
            <form method="post" action="" class="ap-form ap-form--compact">
                <?php echo ap_nonce_field('ap_widgets', '_ap_nonce', false); ?>
                <input type="hidden" name="ap_widget_action" value="add">
                <p class="ap-field">
                    <label for="widget_type">Widget</label>
                    <select name="widget_type" id="widget_type" required>
                        <?php foreach ($widgetTypes as $idBase => $type) : ?>
                            <option value="<?php echo ap_esc_attr($idBase); ?>">
                                <?php echo ap_esc_html((string) $type['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p class="ap-field">
                    <label for="add_sidebar_id">Area</label>
                    <select name="sidebar_id" id="add_sidebar_id" required>
                        <?php foreach ($sidebars as $sid => $sargs) : ?>
                            <option value="<?php echo ap_esc_attr($sid); ?>">
                                <?php echo ap_esc_html((string) $sargs['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                <p>
                    <button type="submit" class="button button-primary">Add Widget</button>
                </p>
            </form>
        <?php endif; ?>
    </aside>

    <section class="ap-widgets-areas">
        <?php foreach ($sidebars as $sidebarId => $sargs) :
            $widgets = $map[$sidebarId] ?? [];
            ?>
            <div class="ap-metabox ap-widget-area-box" id="area-<?php echo ap_esc_attr($sidebarId); ?>">
                <div class="ap-metabox-header">
                    <h2><?php echo ap_esc_html((string) $sargs['name']); ?></h2>
                </div>
                <div class="ap-metabox-body">
                    <?php if ((string) ($sargs['description'] ?? '') !== '') : ?>
                        <p class="ap-help"><?php echo ap_esc_html((string) $sargs['description']); ?></p>
                    <?php endif; ?>
                    <?php if ($widgets === []) : ?>
                        <p class="ap-help"><em>No widgets in this area yet.</em></p>
                    <?php else : ?>
                        <ol class="ap-widget-instances">
                            <?php foreach ($widgets as $i => $widgetId) :
                                $parsed = AP_Widgets::parseWidgetId($widgetId);
                                if ($parsed === null) {
                                    continue;
                                }
                                $type = AP_Widgets::getWidgetType($parsed['id_base']);
                                if ($type === null) {
                                    continue;
                                }
                                $instance = AP_Widgets::getInstance($widgetId, $db);
                                $isFocus = $focus === $widgetId;
                                $displayTitle = trim((string) ($instance['title'] ?? ''));
                                if ($displayTitle === '') {
                                    $displayTitle = (string) $type['name'];
                                }
                                ?>
                                <li class="ap-widget-instance<?php echo $isFocus ? ' is-focus' : ''; ?>"
                                    id="widget-<?php echo ap_esc_attr($widgetId); ?>">
                                    <details <?php echo $isFocus ? 'open' : ''; ?>>
                                        <summary>
                                            <strong><?php echo ap_esc_html($displayTitle); ?></strong>
                                            <span class="ap-help">
                                                (<?php echo ap_esc_html((string) $type['name']); ?> ·
                                                <?php echo ap_esc_html($widgetId); ?>)
                                            </span>
                                        </summary>

                                        <form method="post" action="" class="ap-form ap-form--compact">
                                            <?php echo ap_nonce_field('ap_widgets', '_ap_nonce', false); ?>
                                            <input type="hidden" name="ap_widget_action" value="save">
                                            <input type="hidden" name="widget_id" value="<?php echo ap_esc_attr($widgetId); ?>">

                                            <?php $renderFields($type, $instance, 'widget_', $db); ?>

                                            <p class="ap-field">
                                                <label for="move-<?php echo ap_esc_attr($widgetId); ?>">Area</label>
                                                <select name="sidebar_id" id="move-<?php echo ap_esc_attr($widgetId); ?>">
                                                    <?php foreach ($sidebars as $sid => $sa) : ?>
                                                        <option value="<?php echo ap_esc_attr($sid); ?>"
                                                            <?php echo $sid === $sidebarId ? ' selected' : ''; ?>>
                                                            <?php echo ap_esc_html((string) $sa['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </p>
                                            <p class="ap-widget-actions">
                                                <button type="submit" class="button button-primary">Save</button>
                                            </p>
                                        </form>

                                        <div class="ap-widget-actions ap-widget-actions--row">
                                            <form method="post" action="" class="ap-inline-form">
                                                <?php echo ap_nonce_field('ap_widgets', '_ap_nonce', false); ?>
                                                <input type="hidden" name="ap_widget_action" value="move">
                                                <input type="hidden" name="widget_id" value="<?php echo ap_esc_attr($widgetId); ?>">
                                                <input type="hidden" name="sidebar_id" value="<?php echo ap_esc_attr($sidebarId); ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" class="button" <?php echo $i === 0 ? ' disabled' : ''; ?>>
                                                    Move up
                                                </button>
                                            </form>
                                            <form method="post" action="" class="ap-inline-form">
                                                <?php echo ap_nonce_field('ap_widgets', '_ap_nonce', false); ?>
                                                <input type="hidden" name="ap_widget_action" value="move">
                                                <input type="hidden" name="widget_id" value="<?php echo ap_esc_attr($widgetId); ?>">
                                                <input type="hidden" name="sidebar_id" value="<?php echo ap_esc_attr($sidebarId); ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" class="button"
                                                    <?php echo $i >= count($widgets) - 1 ? ' disabled' : ''; ?>>
                                                    Move down
                                                </button>
                                            </form>
                                            <form method="post" action="" class="ap-inline-form"
                                                onsubmit="return confirm('Remove this widget?');">
                                                <?php echo ap_nonce_field('ap_widgets', '_ap_nonce', false); ?>
                                                <input type="hidden" name="ap_widget_action" value="remove">
                                                <input type="hidden" name="widget_id" value="<?php echo ap_esc_attr($widgetId); ?>">
                                                <button type="submit" class="button button-danger">Remove</button>
                                            </form>
                                        </div>
                                    </details>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
</div>

<?php
require __DIR__ . '/admin-footer.php';
