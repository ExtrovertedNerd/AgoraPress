<?php

/**
 * Add New Media — redirects to library upload panel.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

require __DIR__ . '/admin-bootstrap.php';

AP_Admin::redirect(AP_Admin::url('upload.php') . '#ap-media-upload');
