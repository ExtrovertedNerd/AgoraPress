<?php

/**
 * Primary sidebar (modular widget area).
 *
 * Loaded via AP_Theme::getSidebar() / ap_get_sidebar(). Renders nothing when
 * the Primary Sidebar area has no widgets.
 *
 * @package Agora
 */

declare(strict_types=1);

if (!function_exists('ap_is_active_sidebar') || !ap_is_active_sidebar('sidebar-1')) {
    return;
}

?>
<aside class="widget-area widget-area--sidebar" id="secondary" aria-label="Sidebar">
<?php
if (function_exists('ap_dynamic_sidebar')) {
    ap_dynamic_sidebar('sidebar-1');
} elseif (class_exists('AP_Widgets', false)) {
    AP_Widgets::dynamicSidebar('sidebar-1');
}
?>
</aside>
