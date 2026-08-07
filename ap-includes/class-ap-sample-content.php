<?php

/**
 * Optional sample content for fresh installs (FEATURES: optional sample content).
 *
 * Seeds module-aware demo posts, pages, comments, and forums so a new site is
 * immediately browsable. Idempotent: skips when already installed or when
 * matching sample slugs already exist.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Install-time sample content seeder.
 */
class AP_Sample_Content
{
    /** Option set when sample content has been applied (or skipped as present). */
    public const OPTION_INSTALLED = 'sample_content_installed';

    /** Post meta key marking installer-created demo content. */
    public const META_FLAG = '_ap_sample_content';

    /** Blog post slug. */
    public const SLUG_HELLO_POST = 'hello-world';

    /** Static page slugs. */
    public const SLUG_ABOUT = 'about';

    public const SLUG_SAMPLE_PAGE = 'sample-page';

    public const SLUG_PRIVACY = 'privacy-policy';

    /** Forum / category slugs. */
    public const SLUG_FORUM_CATEGORY = 'community';

    public const SLUG_FORUM_GENERAL = 'general-discussion';

    public const SLUG_WELCOME_TOPIC = 'welcome-to-the-forums';

    /**
     * Ensure dependencies used by {@see seed()} are loaded.
     */
    public static function ensureDependencies(): void
    {
        $dir = __DIR__;
        $files = [
            'class-ap-post.php',
            'class-ap-taxonomy.php',
            'class-ap-comment.php',
            'class-ap-options.php',
            'class-ap-forum.php',
            'class-ap-privacy.php',
        ];
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_readable($path)) {
                // Class files are safe to require once; skip if class already loaded.
                $class = match ($file) {
                    'class-ap-post.php' => 'AP_Post',
                    'class-ap-taxonomy.php' => 'AP_Taxonomy',
                    'class-ap-comment.php' => 'AP_Comment',
                    'class-ap-options.php' => 'AP_Options',
                    'class-ap-forum.php' => 'AP_Forum',
                    'class-ap-privacy.php' => 'AP_Privacy',
                    default => '',
                };
                if ($class !== '' && !class_exists($class, false)) {
                    require_once $path;
                }
            }
        }
    }

    /**
     * Whether sample content has already been marked installed.
     */
    public static function isInstalled(?AP_DB $db = null): bool
    {
        self::ensureDependencies();
        if (class_exists('AP_Options', false)) {
            $raw = (string) AP_Options::get(self::OPTION_INSTALLED, '0', $db);

            return $raw === '1' || $raw === 'true' || $raw === 'yes';
        }

        if ($db === null) {
            return false;
        }
        $val = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [self::OPTION_INSTALLED]
        );

        return $val === '1' || $val === 'true' || $val === 'yes';
    }

    /**
     * Seed sample content for a fresh site.
     *
     * Respects module toggles (blog / static pages / forum). Safe to call more
     * than once: returns early when {@see OPTION_INSTALLED} is set, and skips
     * individual items whose slugs already exist.
     *
     * @param array{
     *     author_id?: int,
     *     site_title?: string,
     *     force?: bool
     * } $args
     *
     * @return array{
     *     ok: bool,
     *     skipped: bool,
     *     posts: list<int>,
     *     pages: list<int>,
     *     comments: list<int>,
     *     forums: list<int>,
     *     topics: list<int>,
     *     tags: list<int>,
     *     errors: list<string>
     * }
     */
    public static function seed(AP_DB $db, array $args = []): array
    {
        self::ensureDependencies();

        $result = [
            'ok' => true,
            'skipped' => false,
            'posts' => [],
            'pages' => [],
            'comments' => [],
            'forums' => [],
            'topics' => [],
            'tags' => [],
            'errors' => [],
        ];

        $force = !empty($args['force']);
        if (!$force && self::isInstalled($db)) {
            $result['skipped'] = true;

            return $result;
        }

        $authorId = max(0, (int) ($args['author_id'] ?? 0));
        if ($authorId < 1) {
            $authorId = self::resolveFirstAdminId($db);
        }
        $siteTitle = trim((string) ($args['site_title'] ?? ''));
        if ($siteTitle === '') {
            $siteTitle = self::readOption($db, 'blogname', 'AgoraPress');
        }

        $blogOn = self::moduleEnabled('blog', $db);
        $pagesOn = self::moduleEnabled('static_pages', $db);
        $forumOn = self::moduleEnabled('forum', $db);

        try {
            if ($blogOn && class_exists('AP_Post', false)) {
                self::seedBlog($db, $authorId, $siteTitle, $result);
            }
            if ($pagesOn && class_exists('AP_Post', false)) {
                self::seedPages($db, $authorId, $siteTitle, $result);
            }
            if ($forumOn && class_exists('AP_Forum', false)) {
                self::seedForums($db, $authorId, $siteTitle, $result);
            }
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['errors'][] = 'Sample content failed: ' . $e->getMessage();

            return $result;
        }

        self::markInstalled($db);

        return $result;
    }

    /**
     * Blog: Hello World post, Getting Started tag, sample approved comment.
     *
     * @param array<string, mixed> $result
     */
    private static function seedBlog(AP_DB $db, int $authorId, string $siteTitle, array &$result): void
    {
        $existing = AP_Post::getBySlug(self::SLUG_HELLO_POST, 'post', $db);
        if ($existing !== null) {
            $result['posts'][] = (int) $existing->ID;

            return;
        }

        $safeTitle = $siteTitle !== '' ? $siteTitle : 'AgoraPress';
        $content = <<<MD
Welcome to **{$safeTitle}**. This is your first blog post — edit or delete it, then start writing.

### What you can do next

1. Open **Posts → All Posts** in the admin to manage this entry.
2. Try the visual editor: formatting appears as you type and matches the published look.
3. Add categories and tags, then assign them from the post sidebar.
4. Invite co-authors who have the publish posts capability — the blog is a single site-wide stream.

AgoraPress is free forever, ships without telemetry by default, and keeps the core lightweight. Enjoy building.
MD;

        $postId = AP_Post::insert([
            'post_author' => $authorId,
            'post_title' => 'Hello world!',
            'post_name' => self::SLUG_HELLO_POST,
            'post_content' => $content,
            'post_excerpt' => 'Your first post on ' . $safeTitle . '. Edit or delete it anytime.',
            'post_status' => 'publish',
            'post_type' => 'post',
            'comment_status' => 'open',
            'meta' => [
                self::META_FLAG => '1',
            ],
        ], $db);

        if ($postId < 1) {
            $result['errors'][] = 'Could not create sample blog post.';
            $result['ok'] = false;

            return;
        }

        $result['posts'][] = $postId;

        // Default category + a demo tag.
        if (class_exists('AP_Taxonomy', false)) {
            AP_Taxonomy::ensureBuiltins();
            $catId = AP_Taxonomy::getDefaultCategoryId($db);
            if ($catId > 0) {
                AP_Taxonomy::setObjectTerms($postId, [$catId], 'category', false, $db);
            }

            $tag = AP_Taxonomy::insertTerm('Getting Started', 'post_tag', [
                'slug' => 'getting-started',
                'description' => 'Sample tag created by the installer.',
            ], $db);
            if (is_array($tag) && !empty($tag['term_id'])) {
                $tagId = (int) $tag['term_id'];
                $result['tags'][] = $tagId;
                AP_Taxonomy::setObjectTerms($postId, [$tagId], 'post_tag', true, $db);
            } else {
                // Term may already exist from a partial prior seed.
                $existingTag = AP_Taxonomy::getTermBySlug('getting-started', 'post_tag', $db);
                if ($existingTag !== null) {
                    $tagId = (int) $existingTag->term_id;
                    $result['tags'][] = $tagId;
                    AP_Taxonomy::setObjectTerms($postId, [$tagId], 'post_tag', true, $db);
                }
            }
        }

        if (class_exists('AP_Comment', false)) {
            $commentId = AP_Comment::insert([
                'comment_post_ID' => $postId,
                'comment_author' => 'AgoraPress',
                'comment_author_email' => 'hello@agorapress.example',
                'comment_author_url' => 'https://agorapress.extrovertednerd.com',
                'comment_content' => 'Hi! This is a sample comment. You can delete it, moderate it, or reply to start a conversation.',
                'comment_approved' => '1',
                'user_id' => 0,
            ], $db, [
                'check_open' => false,
                'run_spam' => false,
            ]);
            if ($commentId > 0) {
                $result['comments'][] = $commentId;
            }
        }
    }

    /**
     * Pages: About, Sample Page, Privacy Policy (linked for privacy tools).
     *
     * @param array<string, mixed> $result
     */
    private static function seedPages(AP_DB $db, int $authorId, string $siteTitle, array &$result): void
    {
        $safeTitle = $siteTitle !== '' ? $siteTitle : 'AgoraPress';

        $pages = [
            [
                'slug' => self::SLUG_ABOUT,
                'title' => 'About',
                'content' => <<<MD
This is an **About** page for {$safeTitle}.

Introduce your community, project, or organization here. Static pages are hierarchical — add child pages from the Pages screen when you need a simple site tree.

You can set a static front page under **Settings → Reading** if you prefer a landing page over the latest posts.
MD,
                'excerpt' => 'About ' . $safeTitle . '.',
            ],
            [
                'slug' => self::SLUG_SAMPLE_PAGE,
                'title' => 'Sample Page',
                'content' => <<<MD
This is a **sample page**. Pages are for evergreen content that is not part of the blog stream — policies, handbooks, landing pages, and similar.

Edit this page or create new ones from **Pages → Add New**. Page templates (when the active theme provides them) appear in the page attributes box.
MD,
                'excerpt' => 'A sample static page you can edit or delete.',
            ],
            [
                'slug' => self::SLUG_PRIVACY,
                'title' => 'Privacy Policy',
                'content' => <<<MD
This is a starter **Privacy Policy** page for {$safeTitle}.

Replace this text with your real policy before going live. AgoraPress does not send telemetry by default.
When you process personal data (accounts, comments, forum posts), document lawful bases, retention, and contact details here.

You can point the privacy tools at this page under **Settings → Privacy**. Export and erase tools live under **Tools**.
MD,
                'excerpt' => 'Starter privacy policy — customize before launch.',
                'is_privacy' => true,
            ],
        ];

        foreach ($pages as $spec) {
            $existing = AP_Post::getBySlug($spec['slug'], 'page', $db);
            if ($existing !== null) {
                $pageId = (int) $existing->ID;
                $result['pages'][] = $pageId;
                if (!empty($spec['is_privacy'])) {
                    self::linkPrivacyPolicy($pageId, $db);
                }
                continue;
            }

            $pageId = AP_Post::insert([
                'post_author' => $authorId,
                'post_title' => $spec['title'],
                'post_name' => $spec['slug'],
                'post_content' => $spec['content'],
                'post_excerpt' => $spec['excerpt'],
                'post_status' => 'publish',
                'post_type' => 'page',
                'comment_status' => 'closed',
                'meta' => [
                    self::META_FLAG => '1',
                ],
            ], $db);

            if ($pageId < 1) {
                $result['errors'][] = 'Could not create sample page: ' . $spec['slug'];
                $result['ok'] = false;
                continue;
            }

            $result['pages'][] = $pageId;
            if (!empty($spec['is_privacy'])) {
                self::linkPrivacyPolicy($pageId, $db);
            }
        }
    }

    /**
     * Forums: Community category, General Discussion forum, welcome topic.
     *
     * @param array<string, mixed> $result
     */
    private static function seedForums(AP_DB $db, int $authorId, string $siteTitle, array &$result): void
    {
        $safeTitle = $siteTitle !== '' ? $siteTitle : 'AgoraPress';

        // Category
        $category = AP_Forum::getForumBySlug(self::SLUG_FORUM_CATEGORY, $db);
        if ($category === null) {
            $catId = AP_Forum::insertForum([
                'forum_name' => 'Community',
                'forum_slug' => self::SLUG_FORUM_CATEGORY,
                'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
                'forum_status' => AP_Forum::FORUM_STATUS_OPEN,
                'forum_desc' => 'Sample category created by the installer. Reorganize freely.',
                'forum_order' => 0,
                'parent_id' => 0,
            ], $db);
            if ($catId < 1) {
                $result['errors'][] = 'Could not create sample forum category.';
                $result['ok'] = false;

                return;
            }
            $result['forums'][] = $catId;
        } else {
            $catId = (int) $category->forum_id;
            $result['forums'][] = $catId;
        }

        // General Discussion forum under category
        $forum = AP_Forum::getForumBySlug(self::SLUG_FORUM_GENERAL, $db);
        if ($forum === null) {
            $forumId = AP_Forum::insertForum([
                'forum_name' => 'General Discussion',
                'forum_slug' => self::SLUG_FORUM_GENERAL,
                'forum_type' => AP_Forum::FORUM_TYPE_FORUM,
                'forum_status' => AP_Forum::FORUM_STATUS_OPEN,
                'forum_desc' => 'Introduce yourself and talk about anything related to ' . $safeTitle . '.',
                'forum_order' => 0,
                'parent_id' => $catId,
            ], $db);
            if ($forumId < 1) {
                $result['errors'][] = 'Could not create sample forum.';
                $result['ok'] = false;

                return;
            }
            $result['forums'][] = $forumId;
        } else {
            $forumId = (int) $forum->forum_id;
            $result['forums'][] = $forumId;
        }

        // Welcome topic (skip if any topic already uses the sample slug in this forum)
        $existingTopic = AP_Forum::getTopicBySlug(self::SLUG_WELCOME_TOPIC, $forumId, $db);
        if ($existingTopic !== null) {
            $result['topics'][] = (int) $existingTopic->topic_id;

            return;
        }

        $body = <<<TXT
Welcome to the [b]{$safeTitle}[/b] forums!

This topic was created by the installer so you can see how topics and replies look. Feel free to reply, lock, sticky, or delete it.

[list]
[*]Start new topics in General Discussion
[*]Use BBCode or Markdown when posting
[*]Moderators can manage reports from the admin moderation queue
[/list]

Happy posting!
TXT;

        $topicId = AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'Welcome to the forums',
            'topic_slug' => self::SLUG_WELCOME_TOPIC,
            'content' => $body,
            'topic_poster' => $authorId,
            'topic_type' => AP_Forum::TOPIC_TYPE_STANDARD,
            'topic_status' => AP_Forum::TOPIC_STATUS_OPEN,
            'topic_approved' => 1,
        ], $db, [
            'check_open' => false,
            'check_permissions' => false,
            'check_guard' => false,
        ]);

        if ($topicId < 1) {
            $result['errors'][] = 'Could not create sample welcome topic.';
            $result['ok'] = false;

            return;
        }

        $result['topics'][] = $topicId;
    }

    /**
     * Link privacy policy page option when Privacy class is available.
     */
    private static function linkPrivacyPolicy(int $pageId, AP_DB $db): void
    {
        if ($pageId < 1) {
            return;
        }
        if (class_exists('AP_Privacy', false)) {
            AP_Privacy::setPrivacyPolicyPageId($pageId, $db);

            return;
        }
        self::writeOption($db, 'wp_page_for_privacy_policy', (string) $pageId);
    }

    /**
     * Mark sample content as installed.
     */
    private static function markInstalled(AP_DB $db): void
    {
        self::writeOption($db, self::OPTION_INSTALLED, '1');
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'flushCache')) {
            AP_Options::flushCache();
        }
    }

    /**
     * Whether a module toggle is on (for sample content seeding).
     */
    private static function moduleEnabled(string $module, AP_DB $db): bool
    {
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'isModuleEnabled')) {
            return AP_Options::isModuleEnabled($module, $db);
        }

        $map = [
            'static_pages' => 'ap_module_static_pages',
            'blog' => 'ap_module_blog',
            'forum' => 'ap_module_forum',
        ];
        $option = $map[$module] ?? '';
        if ($option === '') {
            return true;
        }
        $raw = strtolower(self::readOption($db, $option, '1'));

        return !in_array($raw, ['0', 'false', 'no', 'off', ''], true);
    }

    private static function resolveFirstAdminId(AP_DB $db): int
    {
        $table = $db->quoteIdentifier($db->table('users'));
        $id = (int) ($db->getVar('SELECT ID FROM ' . $table . ' ORDER BY ID ASC LIMIT 1') ?? 0);

        return max(0, $id);
    }

    private static function readOption(AP_DB $db, string $name, string $default = ''): string
    {
        if (class_exists('AP_Options', false)) {
            $val = AP_Options::get($name, $default, $db);

            return is_scalar($val) || $val === null ? (string) ($val ?? $default) : $default;
        }
        $raw = $db->getVar(
            'SELECT option_value FROM ' . $db->quoteIdentifier($db->table('options'))
            . ' WHERE option_name = ? LIMIT 1',
            [$name]
        );

        return $raw === null || $raw === false ? $default : (string) $raw;
    }

    private static function writeOption(AP_DB $db, string $name, string $value): void
    {
        if (class_exists('AP_Options', false) && method_exists('AP_Options', 'update')) {
            AP_Options::update($name, $value, $db);

            return;
        }
        if (class_exists('AP_Installer', false) && method_exists('AP_Installer', 'upsertOption')) {
            AP_Installer::upsertOption($db, $name, $value);

            return;
        }

        $table = $db->quoteIdentifier($db->table('options'));
        $existing = $db->getVar(
            'SELECT option_id FROM ' . $table . ' WHERE option_name = ?',
            [$name]
        );
        if ($existing !== null && $existing !== '') {
            $db->update(
                'options',
                ['option_value' => $value, 'autoload' => 'yes'],
                ['option_name' => $name]
            );

            return;
        }
        $db->insert('options', [
            'option_name' => $name,
            'option_value' => $value,
            'autoload' => 'yes',
        ]);
    }
}
