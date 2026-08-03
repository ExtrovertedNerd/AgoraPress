<?php

/**
 * AgoraPress front controller.
 *
 * Loads the bootstrap. If the site is not installed (no ap-config.php),
 * bootstrap exits with a friendly 503 page. When installed, parses the
 * request path into query vars (pretty permalinks / rewrite rules), builds
 * the main AP_Query, and renders via the theme template hierarchy.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

// Absolute filesystem path to the AgoraPress root, with trailing slash.
if (!defined('AP_ABSPATH')) {
    define('AP_ABSPATH', __DIR__ . '/');
}

require AP_ABSPATH . 'ap-includes/bootstrap.php';

ap_bootstrap();

// Installed and core loaded — resolve the public request into the main query.
$apRewriteVars = [];
if (function_exists('ap_parse_request') && class_exists('AP_Rewrite', false)) {
    $apRewriteVars = ap_parse_request();
    // REST API short-circuits before feeds / theme.
    if (
        class_exists('AP_Rest', false)
        && AP_Rest::isRestRequest($apRewriteVars)
    ) {
        try {
            AP_Rest::serve($apRewriteVars);
        } catch (Throwable) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo '{"code":"rest_unavailable","message":"REST API temporarily unavailable.","data":{"status":503}}';
            exit(0);
        }
    }
    // Syndication feeds (RSS/Atom) short-circuit before the theme.
    if (
        class_exists('AP_Feed', false)
        && AP_Feed::isFeedRequest($apRewriteVars)
    ) {
        try {
            AP_Feed::serve($apRewriteVars);
        } catch (Throwable) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Feed temporarily unavailable.';
            exit(0);
        }
    }
    // XML sitemaps + robots.txt short-circuit before the theme.
    if (
        class_exists('AP_Sitemap', false)
        && (
            AP_Sitemap::isSitemapRequest($apRewriteVars)
            || AP_Sitemap::isRobotsRequest($apRewriteVars)
        )
    ) {
        try {
            AP_Sitemap::serve($apRewriteVars);
        } catch (Throwable) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo 'Sitemap temporarily unavailable.';
            exit(0);
        }
    }
    // Forum create-topic / reply forms (POST → redirect before render).
    if (class_exists('AP_Forum_Front', false) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $apForumRedirect = AP_Forum_Front::handlePost();
            if (is_string($apForumRedirect) && $apForumRedirect !== '') {
                if (!headers_sent()) {
                    header('Location: ' . $apForumRedirect, true, 302);
                }
                exit(0);
            }
        } catch (Throwable) {
            // Fall through to normal render with an error notice if set.
        }
    }
    if (function_exists('ap_set_query') && class_exists('AP_Query', false)) {
        try {
            $apMainQuery = AP_Rewrite::queryFromVars($apRewriteVars);
            // Enrich forum query vars (names, caps) + side effects (views, online).
            if (class_exists('AP_Forum_Front', false) && AP_Forum_Front::isForumRequest($apMainQuery)) {
                AP_Forum_Front::applyToQuery($apMainQuery);
            }
            ap_set_query($apMainQuery);
        } catch (Throwable) {
            // DB may be unavailable in partial installs; empty main query (no SQL).
            if (!isset($GLOBALS['ap_query']) || !$GLOBALS['ap_query'] instanceof AP_Query) {
                ap_set_query(new AP_Query());
            }
        }
    }
}

// Front-end template loader + classic hierarchy.
if (class_exists('AP_Theme', false)) {
    try {
        if (function_exists('ap_template_loader')) {
            ap_template_loader();
        } else {
            AP_Theme::render();
        }
    } catch (Throwable) {
        // Partial install / DB down: avoid fatal on public front controller.
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>AgoraPress</title></head>'
            . '<body><p>AgoraPress is temporarily unable to display this page.</p></body></html>';
    }
}
