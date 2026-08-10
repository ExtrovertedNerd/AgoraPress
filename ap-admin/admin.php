<?php

/**
 * Admin front controller for registered plugin / core ACP pages.
 *
 * Entry: admin.php?page={id}
 *
 * Request pipeline (SPEC):
 * 1. Login required (admin-bootstrap → AP_Admin::requireLogin).
 * 2. Resolve ?page= via AP_Admin_Menu registry allowlist only.
 * 3. Unknown / empty page → safe 404 (AP_Admin::notFound); no callback, no includes.
 * 4. AP_Admin::requireCapability($page['capability']) on every render.
 * 5. Load admin header (title + $ap_admin_screen from registry).
 * 6. Invoke the registered callback (never a filesystem path from query input).
 * 7. Admin footer.
 *
 * Security model:
 * - Login required (via admin-bootstrap; requireCapability re-checks).
 * - Page slug is looked up only in the AP_Admin_Menu registry allowlist.
 * - Unknown ?page= yields HTTP 404 with a static safe message (no path execution).
 * - Capability is checked on every render from the registered page record.
 * - Never includes or executes a filesystem path from user input.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require_once __DIR__ . '/admin-bootstrap.php';

// 1) Bootstrap already called AP_Admin::requireLogin() (unless skip-auth).

// 2) Registry allowlist only — sanitize slug, never treat as a path.
//    Unknown / empty / path-like ?page= → safe 404 (no includes, no callback).
$page = AP_Admin::resolveRequestedAdminPage();
if ($page === null) {
    AP_Admin::notFound(AP_Admin::unknownAdminPageMessage());
}

// 3) Cap gate every request (default manage_options when registration omitted it).
//    requireCapability also re-asserts login.
AP_Admin::requireCapability(AP_Admin::capabilityForRegisteredPage($page));

AP_Admin::consumeQueryNotice();

// 4) Admin chrome: document title + menu active screen id from registry.
$screen = AP_Admin::registeredPageScreenContext($page);
$ap_admin_title = $screen['title'];
// Screen id = registry id so menu merge (Phase 3) can mark the item active.
$ap_admin_screen = $screen['screen'];
$ap_admin_body_class = $screen['body_class'];

require __DIR__ . '/admin-header.php';

// 5) Invoke registered callback only (no path includes from user input).
$invoked = AP_Admin::invokeAdminPageCallback($page['callback']);
if (!$invoked) {
    echo '<div class="ap-notice ap-notice--error" role="alert">'
        . '<p>' . ap_esc_html('This admin page could not be rendered (invalid callback).') . '</p>'
        . '</div>';
}

// 6) Admin footer (closes main / shell).
require __DIR__ . '/admin-footer.php';
