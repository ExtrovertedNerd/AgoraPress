<?php

/**
 * AgoraPress syndication feeds (RSS 2.0 and Atom).
 *
 * Serves site-wide post feeds at /feed/ (pretty) or ?feed=rss2|atom (plain).
 * Forum feeds: /forums/feed/, /forums/{slug}/feed/, /topic/{slug}/feed/
 * (or the matching query-string vars). Anyone without `view_forum` does not
 * see that board, its topics, or an empty parent category; a direct URL is a
 * generic “you cannot view this” page that does not advertise the name or slug.
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
     * Whether rewrite/query vars request a forum-scoped feed (not the blog feed).
     *
     * @param array<string, mixed> $vars
     */
    public static function isForumFeedRequest(array $vars): bool
    {
        if (!self::isFeedRequest($vars)) {
            return false;
        }
        $view = strtolower(trim((string) ($vars['ap_forum_view'] ?? '')));
        if ($view !== '') {
            return true;
        }
        $flag = $vars['ap_forum'] ?? null;
        if ($flag !== null && $flag !== '' && $flag !== false && $flag !== 0 && $flag !== '0') {
            return true;
        }
        if ((int) ($vars['forum_id'] ?? 0) > 0 || (int) ($vars['topic_id'] ?? 0) > 0) {
            return true;
        }
        if (trim((string) ($vars['forum_slug'] ?? '')) !== '') {
            return true;
        }

        return trim((string) ($vars['topic_slug'] ?? '')) !== '';
    }

    /**
     * Emit feed headers + body and stop PHP (never returns under normal use).
     *
     * Forum-scoped requests that the viewer cannot list become a generic HTML
     * 404 (“You cannot view this.”) instead of RSS that would name the board.
     *
     * @param array<string, mixed> $vars Rewrite query vars (must include feed).
     *
     * @return never|string When $exit is false, returns the XML or HTML body.
     */
    public static function serve(array $vars = [], ?AP_DB $db = null, bool $exit = true): string
    {
        // Already-denied rewrite args (name/slug stripped) must not fall through
        // to the blog feed — that would be a 200 instead of the generic 404.
        if (self::isDeniedForumFeed($vars)) {
            return self::emitCannotView($exit);
        }
        $type = self::normalizeType(isset($vars['feed']) ? (string) $vars['feed'] : self::TYPE_RSS2);
        if (self::isForumFeedRequest($vars)) {
            return self::serveForumFeed($vars, $type, $db, $exit);
        }
        $xml = $type === self::TYPE_ATOM
            ? self::buildAtom($db)
            : self::buildRss2($db);

        return self::emitBody(
            $xml,
            $type === self::TYPE_ATOM
                ? 'application/atom+xml; charset=UTF-8'
                : 'application/rss+xml; charset=UTF-8',
            200,
            $exit
        );
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
    // Forum feeds
    // -------------------------------------------------------------------------

    /**
     * Forum index / forum / topic feed. Unlistable boards (and empty parent
     * categories) are a generic HTML 404 — never RSS that names the room.
     *
     * @param array<string, mixed> $vars
     *
     * @return never|string
     */
    private static function serveForumFeed(
        array $vars,
        string $type,
        ?AP_DB $db,
        bool $exit
    ): string {
        $view = self::forumFeedView($vars);
        $vars['ap_forum_view'] = $view;
        $vars['feed'] = $type;

        if ($view === 'forum' || $view === 'topic') {
            if (class_exists('AP_Forum_Front', false)) {
                $vars = AP_Forum_Front::enrichQueryArgs($vars, $db);
            }
            // Unlistable boards and empty parent categories: generic HTML 404,
            // never RSS/Atom whose <title> or self URL would name the room.
            if (self::isDeniedForumFeed($vars) || !self::forumFeedScopeIsListable($vars, $view, $db)) {
                return self::emitCannotView($exit);
            }
        }

        $xml = $type === self::TYPE_ATOM
            ? self::buildForumAtomXml($vars, $view, $db)
            : self::buildForumRssXml($vars, $view, $db);

        return self::emitBody(
            $xml,
            $type === self::TYPE_ATOM
                ? 'application/atom+xml; charset=UTF-8'
                : 'application/rss+xml; charset=UTF-8',
            200,
            $exit
        );
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function forumFeedView(array $vars): string
    {
        $view = strtolower(trim((string) ($vars['ap_forum_view'] ?? '')));
        if ($view === 'search') {
            if (
                (int) ($vars['forum_id'] ?? 0) > 0
                || trim((string) ($vars['forum_slug'] ?? '')) !== ''
            ) {
                return 'forum';
            }

            return 'index';
        }
        if (in_array($view, ['index', 'forum', 'topic'], true)) {
            return $view;
        }
        if (
            (int) ($vars['topic_id'] ?? 0) > 0
            || trim((string) ($vars['topic_slug'] ?? '')) !== ''
        ) {
            return 'topic';
        }
        if (
            (int) ($vars['forum_id'] ?? 0) > 0
            || trim((string) ($vars['forum_slug'] ?? '')) !== ''
        ) {
            return 'forum';
        }

        return 'index';
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function isDeniedForumFeed(array $vars): bool
    {
        return !empty($vars['ap_forum_cannot_view'])
            || !empty($vars['is_404'])
            || !empty($vars['ap_forum_not_found']);
    }

    private static function currentFeedUserId(?AP_DB $db): int
    {
        if (function_exists('ap_get_current_user_id')) {
            try {
                return max(0, (int) ap_get_current_user_id($db));
            } catch (Throwable) {
                return 0;
            }
        }

        return 0;
    }

    /**
     * Whether this forum/topic feed may emit RSS/Atom for the current viewer.
     *
     * Fail closed: missing forum classes, unknown slugs, unlistable boards,
     * and empty parent categories are not listable.
     *
     * @param array<string, mixed> $vars
     */
    private static function forumFeedScopeIsListable(
        array $vars,
        string $view,
        ?AP_DB $db
    ): bool {
        if (!class_exists('AP_Forum', false)) {
            return false;
        }
        $userId = self::currentFeedUserId($db);
        if ($view === 'topic') {
            $topic = null;
            $tid = (int) ($vars['topic_id'] ?? 0);
            if ($tid > 0) {
                $topic = AP_Forum::getTopic($tid, $db);
            }
            if ($topic === null) {
                $slug = trim((string) ($vars['topic_slug'] ?? ''));
                if ($slug !== '' && method_exists('AP_Forum', 'getTopicBySlug')) {
                    $topic = AP_Forum::getTopicBySlug($slug, 0, $db);
                }
            }
            if ($topic === null) {
                return false;
            }

            return AP_Forum::isListableToUser(
                $userId,
                (int) ($topic->forum_id ?? 0),
                $db
            );
        }
        $forum = null;
        $fid = (int) ($vars['forum_id'] ?? 0);
        if ($fid > 0) {
            $forum = AP_Forum::getForum($fid, $db);
        }
        if ($forum === null) {
            $slug = trim((string) ($vars['forum_slug'] ?? ''));
            if ($slug !== '') {
                $forum = AP_Forum::getForumBySlug($slug, $db);
            }
        }
        if ($forum === null) {
            return false;
        }

        return AP_Forum::isListableToUser($userId, (int) ($forum->forum_id ?? 0), $db);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function buildForumRssXml(array $vars, string $view, ?AP_DB $db): string
    {
        $channel = self::forumFeedChannel($vars, $view, $db);
        $items = self::forumFeedItems($vars, $view, $db);
        $useExcerpt = class_exists('AP_Options', false) && AP_Options::rssUseExcerpt($db);
        $buildDate = gmdate('D, d M Y H:i:s') . ' GMT';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"'
            . ' xmlns:content="http://purl.org/rss/1.0/modules/content/">' . "\n";
        $xml .= "  <channel>\n";
        $xml .= '    <title>' . self::xmlText($channel['title']) . "</title>\n";
        $xml .= '    <link>' . self::xmlText($channel['link']) . "</link>\n";
        $xml .= '    <description>' . self::xmlText($channel['title']) . "</description>\n";
        $xml .= '    <language>en</language>' . "\n";
        $xml .= '    <lastBuildDate>' . self::xmlText($buildDate) . "</lastBuildDate>\n";
        $xml .= '    <atom:link href="' . self::xmlAttr($channel['feed_url'])
            . '" rel="self" type="application/rss+xml" />' . "\n";
        $xml .= '    <generator>AgoraPress</generator>' . "\n";

        foreach ($items as $item) {
            $link = $item['link'];
            $guid = $item['guid'];
            $pub = self::rfc822Date($item['date'], $item['date']);
            $xml .= "    <item>\n";
            $xml .= '      <title>' . self::xmlText($item['title']) . "</title>\n";
            $xml .= '      <link>' . self::xmlText($link) . "</link>\n";
            $xml .= '      <guid isPermaLink="' . ($link !== '' ? 'true' : 'false') . '">'
                . self::xmlText($guid) . "</guid>\n";
            if ($pub !== '') {
                $xml .= '      <pubDate>' . self::xmlText($pub) . "</pubDate>\n";
            }
            $xml .= '      <description>' . self::xmlText($item['summary']) . "</description>\n";
            if (!$useExcerpt && $item['full'] !== '') {
                $xml .= '      <content:encoded><![CDATA['
                    . self::cdataSafe($item['full']) . "]]></content:encoded>\n";
            }
            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private static function buildForumAtomXml(array $vars, string $view, ?AP_DB $db): string
    {
        $channel = self::forumFeedChannel($vars, $view, $db);
        $items = self::forumFeedItems($vars, $view, $db);
        $useExcerpt = class_exists('AP_Options', false) && AP_Options::rssUseExcerpt($db);
        $updated = gmdate('Y-m-d\TH:i:s\Z');

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom">' . "\n";
        $xml .= '  <title>' . self::xmlText($channel['title']) . "</title>\n";
        $xml .= '  <link href="' . self::xmlAttr($channel['feed_url'])
            . '" rel="self" type="application/atom+xml" />' . "\n";
        $xml .= '  <link href="' . self::xmlAttr($channel['link'])
            . '" rel="alternate" type="text/html" />' . "\n";
        $xml .= '  <id>' . self::xmlText(
            $channel['feed_url'] !== '' ? $channel['feed_url'] : $channel['link']
        ) . "</id>\n";
        $xml .= '  <updated>' . self::xmlText($updated) . "</updated>\n";
        $xml .= '  <generator uri="https://agorapress.extrovertednerd.com/">AgoraPress</generator>' . "\n";

        foreach ($items as $item) {
            $link = $item['link'];
            $id = $link !== '' ? $link : $item['guid'];
            $pub = self::atomDate($item['date'], $item['date']);
            $body = $useExcerpt ? $item['summary'] : $item['full'];
            $xml .= "  <entry>\n";
            $xml .= '    <title>' . self::xmlText($item['title']) . "</title>\n";
            if ($link !== '') {
                $xml .= '    <link href="' . self::xmlAttr($link)
                    . '" rel="alternate" type="text/html" />' . "\n";
            }
            $xml .= '    <id>' . self::xmlText($id) . "</id>\n";
            if ($pub !== '') {
                $xml .= '    <updated>' . self::xmlText($pub) . "</updated>\n";
                $xml .= '    <published>' . self::xmlText($pub) . "</published>\n";
            }
            if ($item['summary'] !== '') {
                $xml .= '    <summary type="text">'
                    . self::xmlText($item['summary']) . "</summary>\n";
            }
            if ($body !== '') {
                $ctype = $useExcerpt ? 'text' : 'html';
                $xml .= '    <content type="' . $ctype . '">'
                    . self::xmlText($body) . "</content>\n";
            }
            $xml .= "  </entry>\n";
        }

        $xml .= "</feed>\n";

        return $xml;
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return array{title: string, link: string, feed_url: string}
     */
    private static function forumFeedChannel(array $vars, string $view, ?AP_DB $db): array
    {
        $home = self::homeUrl($db);
        $site = self::siteTitle($db);
        $feedType = self::normalizeType((string) ($vars['feed'] ?? self::TYPE_RSS2));
        if ($view === 'topic') {
            $title = trim((string) ($vars['topic_title'] ?? ''));
            $tid = (int) ($vars['topic_id'] ?? 0);
            $link = ($tid > 0 && class_exists('AP_Forum', false))
                ? AP_Forum::topicUrl($tid)
                : $home;
            $feedUrl = ($tid > 0 && class_exists('AP_Forum', false))
                ? AP_Forum::topicFeedUrl($tid, $feedType)
                : $home;

            return [
                'title' => $title !== '' ? $title : $site,
                'link' => $link,
                'feed_url' => $feedUrl,
            ];
        }
        if ($view === 'forum') {
            $title = trim((string) ($vars['forum_name'] ?? ''));
            $fid = (int) ($vars['forum_id'] ?? 0);
            $link = trim((string) ($vars['forum_url'] ?? ''));
            if ($link === '' && $fid > 0 && class_exists('AP_Forum', false)) {
                $link = AP_Forum::forumUrl($fid);
            }
            $feedUrl = ($fid > 0 && class_exists('AP_Forum', false))
                ? AP_Forum::forumFeedUrl($fid, $feedType)
                : $home;

            return [
                'title' => $title !== '' ? $title : $site,
                'link' => $link !== '' ? $link : $home,
                'feed_url' => $feedUrl,
            ];
        }

        $link = class_exists('AP_Forum', false) ? AP_Forum::forumsIndexUrl() : $home;
        $feedUrl = class_exists('AP_Forum', false)
            ? AP_Forum::forumsIndexFeedUrl($feedType)
            : $home;

        return [
            'title' => $site !== '' ? $site . ' Forums' : 'Forums',
            'link' => $link,
            'feed_url' => $feedUrl,
        ];
    }

    /**
     * @param array<string, mixed> $vars
     *
     * @return list<array{title: string, link: string, guid: string, date: string, summary: string, full: string}>
     */
    private static function forumFeedItems(array $vars, string $view, ?AP_DB $db): array
    {
        if (!class_exists('AP_Forum', false)) {
            return [];
        }
        $limit = class_exists('AP_Options', false) ? AP_Options::postsPerRss($db) : 10;
        $userId = self::currentFeedUserId($db);

        if ($view === 'topic') {
            $topicId = (int) ($vars['topic_id'] ?? 0);
            $topicTitle = trim((string) ($vars['topic_title'] ?? ''));
            $posts = AP_Forum::getFeedPosts($topicId, [
                'per_page' => $limit,
                'user_id' => $userId,
            ], $db);
            $items = [];
            foreach ($posts as $post) {
                $items[] = self::itemFromForumPost($post, $topicTitle, $db);
            }

            return $items;
        }

        $forumId = $view === 'forum' ? (int) ($vars['forum_id'] ?? 0) : 0;
        $topics = AP_Forum::getFeedTopics([
            'forum_id' => $forumId,
            'per_page' => $limit,
            'user_id' => $userId,
        ], $db);
        $items = [];
        foreach ($topics as $topic) {
            $items[] = self::itemFromForumTopic($topic, $db);
        }

        return $items;
    }

    /**
     * @return array{title: string, link: string, guid: string, date: string, summary: string, full: string}
     */
    private static function itemFromForumTopic(object $topic, ?AP_DB $db): array
    {
        $title = (string) ($topic->topic_title ?? '');
        $link = AP_Forum::topicUrl($topic);
        $date = (string) ($topic->topic_last_post_time ?? $topic->topic_time ?? '');
        $full = '';
        $firstId = (int) ($topic->first_post_id ?? 0);
        if ($firstId > 0) {
            $post = AP_Forum::getPost($firstId, $db);
            if ($post !== null && (int) ($post->post_approved ?? 1) === 1) {
                $full = (string) ($post->post_content ?? '');
            }
        }
        $summary = self::textExcerpt($full);

        return [
            'title' => $title,
            'link' => $link,
            'guid' => $link !== '' ? $link : ('topic-' . (int) ($topic->topic_id ?? 0)),
            'date' => $date,
            'summary' => $summary,
            'full' => $full,
        ];
    }

    /**
     * @return array{title: string, link: string, guid: string, date: string, summary: string, full: string}
     */
    private static function itemFromForumPost(
        object $post,
        string $topicTitle,
        ?AP_DB $db
    ): array {
        $subject = trim((string) ($post->post_subject ?? ''));
        $title = $subject !== '' ? $subject : $topicTitle;
        $tid = (int) ($post->topic_id ?? 0);
        $link = $tid > 0 ? AP_Forum::topicUrl($tid) : self::homeUrl($db);
        $pid = (int) ($post->post_id ?? 0);
        if ($pid > 0 && $link !== '') {
            $link .= (str_contains($link, '#') ? '' : '#post-' . $pid);
        }
        $full = (string) ($post->post_content ?? '');
        $summary = self::textExcerpt($full);

        return [
            'title' => $title,
            'link' => $link,
            'guid' => $link !== '' ? $link : ('post-' . $pid),
            'date' => (string) ($post->post_time ?? ''),
            'summary' => $summary,
            'full' => $full,
        ];
    }

    /**
     * Generic HTML 404. Do not interpolate board name or slug.
     *
     * @return never|string
     */
    private static function emitCannotView(bool $exit): string
    {
        $msg = class_exists('AP_Forum_Front', false)
            ? AP_Forum_Front::CANNOT_VIEW_MESSAGE
            : 'You cannot view this.';
        $safe = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $safe . '</title></head><body><main><p>'
            . $safe . '</p></main></body></html>';

        return self::emitBody($html, 'text/html; charset=UTF-8', 404, $exit);
    }

    /**
     * @return never|string
     */
    private static function emitBody(
        string $body,
        string $contentType,
        int $status,
        bool $exit
    ): string {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('X-Content-Type-Options: nosniff');
            http_response_code($status);
        }
        echo $body;
        if ($exit) {
            exit(0);
        }

        return $body;
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

        return self::textExcerpt((string) $post->post_content);
    }

    private static function textExcerpt(string $html): string
    {
        $text = trim(strip_tags($html));
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
