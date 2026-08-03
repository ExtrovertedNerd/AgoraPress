<?php

/**
 * AgoraPress syndication feeds (RSS 2.0 and Atom).
 *
 * Serves site-wide post feeds at /feed/ (pretty) or ?feed=rss2|atom (plain).
 * Item count and full text vs summary follow Reading settings
 * (`posts_per_rss`, `rss_use_excerpt`).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Build and emit RSS/Atom XML for recent posts.
 */
class AP_Feed
{
    /** Supported feed types. */
    public const TYPE_RSS2 = 'rss2';

    public const TYPE_ATOM = 'atom';

    /**
     * Normalize a feed type slug.
     */
    public static function normalizeType(string $feed): string
    {
        $feed = strtolower(trim($feed));
        if ($feed === '' || $feed === 'feed' || $feed === 'rss' || $feed === 'rdf') {
            return self::TYPE_RSS2;
        }
        if ($feed === 'atom') {
            return self::TYPE_ATOM;
        }
        if ($feed === 'rss2') {
            return self::TYPE_RSS2;
        }

        return self::TYPE_RSS2;
    }

    /**
     * Whether the given rewrite/query vars request a feed.
     *
     * @param array<string, mixed> $vars
     */
    public static function isFeedRequest(array $vars): bool
    {
        if (!isset($vars['feed'])) {
            return false;
        }
        $feed = $vars['feed'];
        if (is_bool($feed)) {
            return $feed;
        }
        if (is_string($feed)) {
            return trim($feed) !== '';
        }

        return false;
    }

    /**
     * Emit feed headers + body and stop PHP (never returns under normal use).
     *
     * @param array<string, mixed> $vars Rewrite query vars (must include feed).
     *
     * @return never|string When $exit is false, returns the XML body.
     */
    public static function serve(array $vars = [], ?AP_DB $db = null, bool $exit = true): string
    {
        $type = self::normalizeType(isset($vars['feed']) ? (string) $vars['feed'] : self::TYPE_RSS2);
        $xml = $type === self::TYPE_ATOM
            ? self::buildAtom($db)
            : self::buildRss2($db);

        if (!headers_sent()) {
            $ctype = $type === self::TYPE_ATOM
                ? 'application/atom+xml; charset=UTF-8'
                : 'application/rss+xml; charset=UTF-8';
            header('Content-Type: ' . $ctype);
            header('X-Content-Type-Options: nosniff');
            http_response_code(200);
        }

        echo $xml;

        if ($exit) {
            exit(0);
        }

        return $xml;
    }

    /**
     * Build RSS 2.0 document for latest posts.
     */
    public static function buildRss2(?AP_DB $db = null): string
    {
        $siteTitle = self::siteTitle($db);
        $siteDesc = self::siteDescription($db);
        $home = self::homeUrl($db);
        $feedUrl = class_exists('AP_Rewrite', false)
            ? AP_Rewrite::getFeedLink(self::TYPE_RSS2, $db)
            : $home . '/?feed=rss2';
        $posts = self::fetchPosts($db);
        $useExcerpt = class_exists('AP_Options', false) && AP_Options::rssUseExcerpt($db);
        $buildDate = gmdate('D, d M Y H:i:s') . ' GMT';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . self::xmlText($siteTitle) . "</title>\n";
        $xml .= '    <link>' . self::xmlText($home) . "</link>\n";
        $xml .= '    <description>' . self::xmlText($siteDesc) . "</description>\n";
        $xml .= '    <language>en</language>' . "\n";
        $xml .= '    <lastBuildDate>' . self::xmlText($buildDate) . "</lastBuildDate>\n";
        $xml .= '    <atom:link href="' . self::xmlAttr($feedUrl) . '" rel="self" type="application/rss+xml" />' . "\n";
        $xml .= '    <generator>AgoraPress</generator>' . "\n";

        foreach ($posts as $post) {
            $link = self::permalink($post, $db);
            $title = (string) $post->post_title;
            $pub = self::rfc822Date((string) $post->post_date_gmt, (string) $post->post_date);
            $guid = $link !== '' ? $link : ('post-' . (int) $post->ID);
            $body = $useExcerpt
                ? self::excerptForFeed($post)
                : (string) $post->post_content;
            $desc = self::excerptForFeed($post);

            $xml .= "    <item>\n";
            $xml .= '      <title>' . self::xmlText($title) . "</title>\n";
            $xml .= '      <link>' . self::xmlText($link) . "</link>\n";
            $xml .= '      <guid isPermaLink="' . ($link !== '' ? 'true' : 'false') . '">'
                . self::xmlText($guid) . "</guid>\n";
            if ($pub !== '') {
                $xml .= '      <pubDate>' . self::xmlText($pub) . "</pubDate>\n";
            }
            $xml .= '      <description>' . self::xmlText($desc) . "</description>\n";
            if (!$useExcerpt && $body !== '') {
                $xml .= '      <content:encoded><![CDATA[' . self::cdataSafe($body) . "]]></content:encoded>\n";
            }
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }

    /**
     * Build Atom 1.0 document for latest posts.
     */
    public static function buildAtom(?AP_DB $db = null): string
    {
        $siteTitle = self::siteTitle($db);
        $siteDesc = self::siteDescription($db);
        $home = self::homeUrl($db);
        $feedUrl = class_exists('AP_Rewrite', false)
            ? AP_Rewrite::getFeedLink(self::TYPE_ATOM, $db)
            : $home . '/?feed=atom';
        $posts = self::fetchPosts($db);
        $useExcerpt = class_exists('AP_Options', false) && AP_Options::rssUseExcerpt($db);
        $updated = gmdate('Y-m-d\TH:i:s\Z');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . self::xmlText($siteTitle) . "</title>\n";
        if ($siteDesc !== '') {
            $xml .= '  <subtitle>' . self::xmlText($siteDesc) . "</subtitle>\n";
        }
        $xml .= '  <link href="' . self::xmlAttr($feedUrl) . '" rel="self" type="application/atom+xml" />' . "\n";
        $xml .= '  <link href="' . self::xmlAttr($home) . '" rel="alternate" type="text/html" />' . "\n";
        $xml .= '  <id>' . self::xmlText($feedUrl !== '' ? $feedUrl : $home) . "</id>\n";
        $xml .= '  <updated>' . self::xmlText($updated) . "</updated>\n";
        $xml .= '  <generator uri="https://agorapress.extrovertednerd.com/">AgoraPress</generator>' . "\n";

        foreach ($posts as $post) {
            $link = self::permalink($post, $db);
            $title = (string) $post->post_title;
            $id = $link !== '' ? $link : ('urn:agorapress:post:' . (int) $post->ID);
            $pub = self::atomDate((string) $post->post_date_gmt, (string) $post->post_date);
            $body = $useExcerpt
                ? self::excerptForFeed($post)
                : (string) $post->post_content;
            $summary = self::excerptForFeed($post);

            $xml .= "  <entry>\n";
            $xml .= '    <title>' . self::xmlText($title) . "</title>\n";
            if ($link !== '') {
                $xml .= '    <link href="' . self::xmlAttr($link) . '" rel="alternate" type="text/html" />' . "\n";
            }
            $xml .= '    <id>' . self::xmlText($id) . "</id>\n";
            if ($pub !== '') {
                $xml .= '    <updated>' . self::xmlText($pub) . "</updated>\n";
                $xml .= '    <published>' . self::xmlText($pub) . "</published>\n";
            }
            if ($summary !== '') {
                $xml .= '    <summary type="text">' . self::xmlText($summary) . "</summary>\n";
            }
            if ($body !== '') {
                $type = $useExcerpt ? 'text' : 'html';
                $xml .= '    <content type="' . $type . '">' . self::xmlText($body) . "</content>\n";
            }
            $xml .= "  </entry>\n";
        }

        $xml .= "</feed>\n";

        return $xml;
    }

    /**
     * Recent published posts for feeds.
     *
     * @return list<AP_Post>
     */
    public static function fetchPosts(?AP_DB $db = null): array
    {
        $limit = class_exists('AP_Options', false) ? AP_Options::postsPerRss($db) : 10;
        if (!class_exists('AP_Query', false)) {
            return [];
        }

        $q = new AP_Query([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ], $db);

        $out = [];
        foreach ($q->posts as $p) {
            if ($p instanceof AP_Post) {
                $out[] = $p;
            }
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function siteTitle(?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            $t = (string) AP_Options::get('blogname', 'AgoraPress', $db);

            return $t !== '' ? $t : 'AgoraPress';
        }

        return 'AgoraPress';
    }

    private static function siteDescription(?AP_DB $db): string
    {
        if (class_exists('AP_Options', false)) {
            return (string) AP_Options::get('blogdescription', '', $db);
        }

        return '';
    }

    private static function homeUrl(?AP_DB $db): string
    {
        if (class_exists('AP_Rewrite', false)) {
            return rtrim(AP_Rewrite::homeUrl('', $db), '/');
        }
        if (defined('AP_SITEURL')) {
            return rtrim((string) AP_SITEURL, '/');
        }

        return '';
    }

    private static function permalink(AP_Post $post, ?AP_DB $db): string
    {
        if (function_exists('ap_get_permalink') && class_exists('AP_Rewrite', false)) {
            return ap_get_permalink($post, $db);
        }

        return self::homeUrl($db) . '/?p=' . (int) $post->ID;
    }

    private static function excerptForFeed(AP_Post $post): string
    {
        $excerpt = trim((string) $post->post_excerpt);
        if ($excerpt !== '') {
            return $excerpt;
        }
        $text = trim(strip_tags((string) $post->post_content));
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\s+/u', $text, 56) ?: [];
        if (count($parts) > 55) {
            $parts = array_slice($parts, 0, 55);

            return implode(' ', $parts) . '…';
        }

        return implode(' ', $parts);
    }

    private static function rfc822Date(string $gmt, string $local): string
    {
        $src = $gmt !== '' && $gmt !== '0000-00-00 00:00:00' ? $gmt : $local;
        if ($src === '') {
            return '';
        }
        $ts = strtotime($src . (str_contains($src, 'GMT') || str_ends_with($src, 'Z') ? '' : ' UTC'));
        if ($ts === false) {
            $ts = strtotime($src);
        }

        return $ts !== false ? gmdate('D, d M Y H:i:s', $ts) . ' GMT' : '';
    }

    private static function atomDate(string $gmt, string $local): string
    {
        $src = $gmt !== '' && $gmt !== '0000-00-00 00:00:00' ? $gmt : $local;
        if ($src === '') {
            return '';
        }
        $ts = strtotime($src . ' UTC');
        if ($ts === false) {
            $ts = strtotime($src);
        }

        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : '';
    }

    private static function xmlText(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function xmlAttr(string $text): string
    {
        return self::xmlText($text);
    }

    private static function cdataSafe(string $text): string
    {
        // Prevent premature CDATA termination.
        return str_replace(']]>', ']]]]><![CDATA[>', $text);
    }
}
