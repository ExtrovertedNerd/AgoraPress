<?php

/**
 * phpBB importer — users, forums, topics, and posts into AgoraPress.
 *
 * Supports:
 * - Live source database (MySQL/MariaDB primary; SQLite/PostgreSQL if tables match)
 * - Portable JSON export (`format: agorapress-phpbb-export`) for offline/tests
 *
 * Maps phpBB 3.x tables (users, forums, topics, posts) into AP_User + AP_Forum.
 * BBCode UIDs are stripped so AP_Content_Format can re-render. Historical
 * timestamps and view counts are restored after create. Attachments, PMs,
 * ranks, and custom BBCodes are out of scope for this increment.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Parse and import phpBB board data into AgoraPress forum tables.
 */
class AP_Phpbb_Importer
{
    /** Default max JSON / dump file size (64 MiB). */
    public const DEFAULT_MAX_BYTES = 67108864;

    /** JSON export format identifier. */
    public const JSON_FORMAT = 'agorapress-phpbb-export';

    /** Usermeta key storing the original phpBB user_id. */
    public const META_PHPBB_USER_ID = '_ap_phpbb_user_id';

    /** Usermeta flag: imported user needs password reset before login. */
    public const META_NEEDS_PASSWORD_RESET = '_ap_phpbb_needs_password_reset';

    /** phpBB user_type: normal registered member. */
    public const USER_TYPE_NORMAL = 0;

    /** phpBB user_type: inactive. */
    public const USER_TYPE_INACTIVE = 1;

    /** phpBB user_type: ignore / bots. */
    public const USER_TYPE_IGNORE = 2;

    /** phpBB user_type: founder. */
    public const USER_TYPE_FOUNDER = 3;

    /**
     * Import from a portable JSON export file.
     *
     * @param array{
     *   max_bytes?: int,
     *   import_users?: bool,
     *   import_forums?: bool,
     *   import_topics?: bool,
     *   import_posts?: bool,
     *   skip_bots?: bool,
     *   default_author?: int,
     *   default_role?: string
     * } $args
     *
     * @return array<string, mixed>
     */
    public static function importFromFile(string $path, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();

        $path = trim($path);
        if ($path === '' || !is_readable($path)) {
            $result['errors'][] = 'phpBB export file is missing or not readable.';

            return $result;
        }

        $size = @filesize($path);
        if (!is_int($size) || $size < 1) {
            $result['errors'][] = 'phpBB export file is empty.';

            return $result;
        }

        $max = isset($args['max_bytes']) ? (int) $args['max_bytes'] : self::maxBytes();
        if ($max < 1) {
            $max = self::DEFAULT_MAX_BYTES;
        }
        if ($size > $max) {
            $result['errors'][] = 'phpBB export exceeds the maximum size of ' . self::formatBytes($max) . '.';

            return $result;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            $result['errors'][] = 'Could not read the phpBB export file.';

            return $result;
        }

        return self::importFromString($raw, $db, $args);
    }

    /**
     * Import from a JSON string (portable export).
     *
     * @param array<string, mixed> $args Same as {@see importFromFile()}.
     *
     * @return array<string, mixed>
     */
    public static function importFromString(string $json, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();
        $parsed = self::parseJson($json);
        if ($parsed['errors'] !== []) {
            $result['errors'] = $parsed['errors'];

            return $result;
        }

        $result['source_name'] = (string) ($parsed['source']['board_name'] ?? '');
        $result['source_version'] = (string) ($parsed['source']['phpbb_version'] ?? '');

        return self::importFromArray($parsed, $db, $args, $result);
    }

    /**
     * Import from a structured array (already extracted users/forums/topics/posts).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     * @param array<string, mixed>|null $result Seed result (optional)
     *
     * @return array<string, mixed>
     */
    public static function importFromArray(array $data, ?AP_DB $db = null, array $args = [], ?array $result = null): array
    {
        $result = $result ?? self::emptyResult();
        $db = self::resolveDb($db);

        if (class_exists('AP_Roles', false)) {
            AP_Roles::ensureDefaults($db);
        }

        $importUsers = !array_key_exists('import_users', $args) || !empty($args['import_users']);
        $importForums = !array_key_exists('import_forums', $args) || !empty($args['import_forums']);
        $importTopics = !array_key_exists('import_topics', $args) || !empty($args['import_topics']);
        $importPosts = !array_key_exists('import_posts', $args) || !empty($args['import_posts']);
        $skipBots = !array_key_exists('skip_bots', $args) || !empty($args['skip_bots']);
        $defaultAuthor = max(0, (int) ($args['default_author'] ?? 0));
        $defaultRole = trim((string) ($args['default_role'] ?? 'subscriber'));
        if ($defaultRole === '') {
            $defaultRole = 'subscriber';
        }

        if (isset($data['source']) && is_array($data['source'])) {
            if ($result['source_name'] === '') {
                $result['source_name'] = (string) ($data['source']['board_name'] ?? '');
            }
            if ($result['source_version'] === '') {
                $result['source_version'] = (string) ($data['source']['phpbb_version'] ?? '');
            }
        }

        /** @var array<int, int> $userMap phpbb user_id => ap user id */
        $userMap = [];
        /** @var array<int, int> $forumMap phpbb forum_id => ap forum id */
        $forumMap = [];
        /** @var array<int, int> $topicMap phpbb topic_id => ap topic id */
        $topicMap = [];
        /** @var array<int, int> $postMap phpbb post_id => ap post id */
        $postMap = [];

        // Index posts by topic for first-post lookup.
        /** @var array<int, list<array<string, mixed>>> $postsByTopic */
        $postsByTopic = [];
        $posts = is_array($data['posts'] ?? null) ? $data['posts'] : [];
        foreach ($posts as $post) {
            if (!is_array($post)) {
                continue;
            }
            $tid = (int) ($post['topic_id'] ?? 0);
            if ($tid < 1) {
                continue;
            }
            if (!isset($postsByTopic[$tid])) {
                $postsByTopic[$tid] = [];
            }
            $postsByTopic[$tid][] = $post;
        }
        foreach ($postsByTopic as $tid => $list) {
            usort($list, static function (array $a, array $b): int {
                $ta = (int) ($a['post_time'] ?? 0);
                $tb = (int) ($b['post_time'] ?? 0);
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }

                return ((int) ($a['post_id'] ?? 0)) <=> ((int) ($b['post_id'] ?? 0));
            });
            $postsByTopic[$tid] = $list;
        }

        // 1) Users
        if ($importUsers && is_array($data['users'] ?? null)) {
            foreach ($data['users'] as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $map = self::importUser($user, $db, $result, $skipBots, $defaultRole);
                if ($map['user_id'] > 0 && $map['phpbb_id'] > 0) {
                    $userMap[$map['phpbb_id']] = $map['user_id'];
                }
            }
        }

        // 2) Forums (multi-pass for parents)
        if ($importForums && is_array($data['forums'] ?? null)) {
            $forums = array_values(array_filter($data['forums'], 'is_array'));
            usort($forums, static function (array $a, array $b): int {
                $la = (int) ($a['left_id'] ?? $a['forum_order'] ?? $a['forum_id'] ?? 0);
                $lb = (int) ($b['left_id'] ?? $b['forum_order'] ?? $b['forum_id'] ?? 0);

                return $la <=> $lb;
            });

            $pending = $forums;
            $guard = 0;
            $maxPasses = max(8, count($pending) + 2);
            while ($pending !== [] && $guard < $maxPasses) {
                $guard++;
                $next = [];
                foreach ($pending as $forum) {
                    $phpbbId = (int) ($forum['forum_id'] ?? 0);
                    if ($phpbbId < 1) {
                        $result['skipped']++;
                        continue;
                    }
                    if (isset($forumMap[$phpbbId])) {
                        continue;
                    }
                    $parentPhpbb = (int) ($forum['parent_id'] ?? 0);
                    if ($parentPhpbb > 0 && !isset($forumMap[$parentPhpbb])) {
                        $next[] = $forum;
                        continue;
                    }
                    $parentAp = $parentPhpbb > 0 ? ($forumMap[$parentPhpbb] ?? 0) : 0;
                    $apId = self::importForum($forum, $parentAp, $db, $result);
                    if ($apId > 0) {
                        $forumMap[$phpbbId] = $apId;
                    }
                }
                if (count($next) === count($pending)) {
                    foreach ($next as $forum) {
                        $phpbbId = (int) ($forum['forum_id'] ?? 0);
                        $result['warnings'][] = 'Could not import forum #' . $phpbbId
                            . ' (missing parent #' . (int) ($forum['parent_id'] ?? 0) . ').';
                        $result['skipped']++;
                    }
                    break;
                }
                $pending = $next;
            }
        }

        // 3) Topics + first post, then remaining posts
        if ($importTopics && is_array($data['topics'] ?? null)) {
            $topics = array_values(array_filter($data['topics'], 'is_array'));
            usort($topics, static function (array $a, array $b): int {
                $ta = (int) ($a['topic_time'] ?? 0);
                $tb = (int) ($b['topic_time'] ?? 0);
                if ($ta !== $tb) {
                    return $ta <=> $tb;
                }

                return ((int) ($a['topic_id'] ?? 0)) <=> ((int) ($b['topic_id'] ?? 0));
            });

            foreach ($topics as $topic) {
                $phpbbTopicId = (int) ($topic['topic_id'] ?? 0);
                $phpbbForumId = (int) ($topic['forum_id'] ?? 0);
                if ($phpbbTopicId < 1 || $phpbbForumId < 1) {
                    $result['skipped']++;
                    continue;
                }
                if (!isset($forumMap[$phpbbForumId])) {
                    $result['warnings'][] = 'Skipped topic #' . $phpbbTopicId . ' (forum not imported).';
                    $result['skipped']++;
                    continue;
                }

                $apForumId = $forumMap[$phpbbForumId];
                $topicPosts = $postsByTopic[$phpbbTopicId] ?? [];
                $firstPost = null;
                $firstPostId = (int) ($topic['topic_first_post_id'] ?? 0);
                if ($firstPostId > 0) {
                    foreach ($topicPosts as $p) {
                        if ((int) ($p['post_id'] ?? 0) === $firstPostId) {
                            $firstPost = $p;
                            break;
                        }
                    }
                }
                if ($firstPost === null && $topicPosts !== []) {
                    $firstPost = $topicPosts[0];
                    $firstPostId = (int) ($firstPost['post_id'] ?? 0);
                }

                $title = trim((string) ($topic['topic_title'] ?? ''));
                if ($title === '') {
                    $title = $firstPost !== null
                        ? trim((string) ($firstPost['post_subject'] ?? ''))
                        : '';
                }
                if ($title === '') {
                    $title = 'Topic ' . $phpbbTopicId;
                }

                $content = '';
                $bbcodeUid = '';
                if ($firstPost !== null) {
                    $content = (string) ($firstPost['post_text'] ?? $firstPost['post_content'] ?? '');
                    $bbcodeUid = (string) ($firstPost['bbcode_uid'] ?? '');
                }
                $content = self::cleanPostText($content, $bbcodeUid);
                if ($content === '') {
                    $content = '(empty post)';
                }

                $posterPhpbb = (int) ($topic['topic_poster'] ?? $firstPost['poster_id'] ?? 0);
                $posterAp = self::resolveUserId($posterPhpbb, $userMap, $defaultAuthor);

                $status = self::mapTopicStatus((int) ($topic['topic_status'] ?? 0));
                $type = self::mapTopicType((int) ($topic['topic_type'] ?? 0));
                $approved = self::mapVisibility(
                    $topic['topic_visibility'] ?? $topic['topic_approved'] ?? 1
                );

                if (!class_exists('AP_Forum', false)) {
                    $result['errors'][] = 'Forum subsystem is not loaded.';

                    return $result;
                }

                $topicId = AP_Forum::createTopic([
                    'forum_id' => $apForumId,
                    'topic_title' => $title,
                    'content' => $content,
                    'topic_poster' => $posterAp,
                    'poster_id' => $posterAp,
                    'topic_status' => $status,
                    'topic_type' => $type,
                    'topic_approved' => $approved,
                    'poster_ip' => (string) ($firstPost['poster_ip'] ?? ''),
                    'post_subject' => (string) ($firstPost['post_subject'] ?? $title),
                ], $db, [
                    'check_open' => false,
                    'check_permissions' => false,
                    'check_guard' => false,
                ]);

                if ($topicId < 1) {
                    $result['warnings'][] = 'Failed to import topic #' . $phpbbTopicId . ': ' . $title;
                    $result['skipped']++;
                    continue;
                }

                $topicMap[$phpbbTopicId] = $topicId;
                $result['topics']++;

                $topicRow = AP_Forum::getTopic($topicId, $db);
                $firstApPostId = $topicRow !== null ? (int) $topicRow->first_post_id : 0;
                if ($firstApPostId > 0 && $firstPostId > 0) {
                    $postMap[$firstPostId] = $firstApPostId;
                    $result['posts']++;
                }

                $topicTime = self::unixToDatetime((int) ($topic['topic_time'] ?? $firstPost['post_time'] ?? 0));
                $views = max(0, (int) ($topic['topic_views'] ?? 0));
                if ($firstApPostId > 0) {
                    $firstTime = self::unixToDatetime((int) ($firstPost['post_time'] ?? $topic['topic_time'] ?? 0));
                    self::stampPost($firstApPostId, $firstTime, $db);
                }

                // Remaining posts as replies.
                if ($importPosts) {
                    foreach ($topicPosts as $post) {
                        $phpbbPostId = (int) ($post['post_id'] ?? 0);
                        if ($phpbbPostId < 1 || isset($postMap[$phpbbPostId])) {
                            continue;
                        }
                        // Skip first post already imported.
                        if ($firstPostId > 0 && $phpbbPostId === $firstPostId) {
                            continue;
                        }

                        $replyText = self::cleanPostText(
                            (string) ($post['post_text'] ?? $post['post_content'] ?? ''),
                            (string) ($post['bbcode_uid'] ?? '')
                        );
                        if ($replyText === '') {
                            $replyText = '(empty post)';
                        }
                        $replyPoster = self::resolveUserId(
                            (int) ($post['poster_id'] ?? 0),
                            $userMap,
                            $defaultAuthor
                        );
                        $postApproved = self::mapVisibility(
                            $post['post_visibility'] ?? $post['post_approved'] ?? 1
                        );

                        $replyId = AP_Forum::createReply([
                            'topic_id' => $topicId,
                            'content' => $replyText,
                            'poster_id' => $replyPoster,
                            'poster_ip' => (string) ($post['poster_ip'] ?? ''),
                            'post_subject' => (string) ($post['post_subject'] ?? $title),
                            'post_approved' => $postApproved,
                        ], $db, [
                            'check_open' => false,
                            'check_permissions' => false,
                            'check_guard' => false,
                        ]);

                        if ($replyId < 1) {
                            $result['warnings'][] = 'Failed to import post #' . $phpbbPostId
                                . ' in topic #' . $phpbbTopicId;
                            $result['skipped']++;
                            continue;
                        }

                        $postMap[$phpbbPostId] = $replyId;
                        $result['posts']++;
                        $postTime = self::unixToDatetime((int) ($post['post_time'] ?? 0));
                        self::stampPost($replyId, $postTime, $db);
                    }
                }

                // Restore topic timestamps / views and last-post pointers.
                $lastTime = $topicTime;
                $lastPoster = $posterAp;
                $lastPostAp = $firstApPostId;
                if ($importPosts && $topicPosts !== []) {
                    $lastSrc = $topicPosts[array_key_last($topicPosts)];
                    $lastSrcId = (int) ($lastSrc['post_id'] ?? 0);
                    if ($lastSrcId > 0 && isset($postMap[$lastSrcId])) {
                        $lastPostAp = $postMap[$lastSrcId];
                        $lastTime = self::unixToDatetime((int) ($lastSrc['post_time'] ?? 0));
                        $lastPoster = self::resolveUserId(
                            (int) ($lastSrc['poster_id'] ?? 0),
                            $userMap,
                            $defaultAuthor
                        );
                    }
                }
                self::stampTopic($topicId, [
                    'topic_time' => $topicTime,
                    'topic_modified' => $lastTime,
                    'topic_last_post_time' => $lastTime,
                    'topic_views' => $views,
                    'last_post_id' => $lastPostAp,
                    'last_poster_id' => $lastPoster,
                    'topic_status' => $status,
                    'topic_type' => $type,
                ], $db);
            }
        } elseif ($importPosts && $posts !== []) {
            $result['warnings'][] = 'Posts were present but topic import is disabled; posts skipped.';
        }

        $result['user_map'] = $userMap;
        $result['forum_map'] = $forumMap;
        $result['topic_map'] = $topicMap;
        $result['post_map'] = $postMap;
        $result['ok'] = $result['errors'] === [];

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_phpbb_imported', $result, $db);
        }

        return $result;
    }

    /**
     * Import from a live phpBB database connection.
     *
     * Connection keys: driver (mysql|sqlite|pgsql), host, name/database, user,
     * password, charset, table_prefix (default phpbb_).
     *
     * @param array<string, mixed> $connection
     * @param array<string, mixed> $args Passed through to importFromArray
     *
     * @return array<string, mixed>
     */
    public static function importFromDatabase(array $connection, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();

        try {
            $source = self::connectSource($connection);
        } catch (Throwable $e) {
            $result['errors'][] = 'Could not connect to phpBB database: ' . $e->getMessage();

            return $result;
        }

        $prefix = self::normalizeTablePrefix(
            (string) ($connection['table_prefix'] ?? $connection['prefix'] ?? 'phpbb_')
        );

        try {
            $extracted = self::extractFromPdo($source, $prefix);
        } catch (Throwable $e) {
            $result['errors'][] = 'Failed to read phpBB tables: ' . $e->getMessage();

            return $result;
        }

        if ($extracted['errors'] !== []) {
            $result['errors'] = $extracted['errors'];

            return $result;
        }

        $result['source_name'] = (string) ($extracted['source']['board_name'] ?? '');
        $result['source_version'] = (string) ($extracted['source']['phpbb_version'] ?? '');

        return self::importFromArray($extracted, $db, $args, $result);
    }

    /**
     * Handle a multipart JSON export upload.
     *
     * @param array<string, mixed> $file Typically $_FILES['phpbb']
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function handleUpload(array $file, ?AP_DB $db = null, array $args = []): array
    {
        $result = self::emptyResult();

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $result['errors'][] = self::uploadErrorMessage($error);

            return $result;
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            if (!(defined('AP_PHPBB_ALLOW_LOCAL') && AP_PHPBB_ALLOW_LOCAL) || !is_readable($tmp)) {
                $result['errors'][] = 'Invalid upload.';

                return $result;
            }
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, ['json', 'txt'], true)) {
            $result['errors'][] = 'Please upload a phpBB JSON export (.json).';

            return $result;
        }

        $head = (string) @file_get_contents($tmp, false, null, 0, 2048);
        $looksJson = $head !== '' && (
            str_contains($head, self::JSON_FORMAT)
            || (
                (str_contains($head, '{') || str_contains($head, '['))
                && str_contains($head, '"users"')
                && (str_contains($head, '"forums"') || str_contains($head, '"topics"'))
            )
        );
        if (!$looksJson) {
            $size = @filesize($tmp);
            if (is_int($size) && $size > 0 && $size <= 65536) {
                $full = (string) @file_get_contents($tmp);
                if (!self::isPhpbbJson($full)) {
                    $result['errors'][] = 'File does not look like an AgoraPress phpBB export JSON.';

                    return $result;
                }
            } else {
                $result['errors'][] = 'File does not look like an AgoraPress phpBB export JSON.';

                return $result;
            }
        }

        return self::importFromFile($tmp, $db, $args);
    }

    /**
     * Lightweight detection of our portable phpBB JSON export.
     */
    public static function isPhpbbJson(string $json): bool
    {
        $sample = substr(ltrim($json, "\xEF\xBB\xBF \t\r\n"), 0, 8000);
        if ($sample === '') {
            return false;
        }
        if (str_contains($sample, self::JSON_FORMAT)) {
            return true;
        }
        // Heuristic: JSON object with users + forums or topics keys.
        if (($sample[0] ?? '') !== '{' && ($sample[0] ?? '') !== '[') {
            return false;
        }
        $hasUsers = str_contains($sample, '"users"');
        $hasForums = str_contains($sample, '"forums"');
        $hasTopics = str_contains($sample, '"topics"');

        return $hasUsers && ($hasForums || $hasTopics);
    }

    /**
     * Parse portable JSON without writing to the database.
     *
     * @return array<string, mixed>
     */
    public static function parseJson(string $json): array
    {
        $out = [
            'errors' => [],
            'warnings' => [],
            'format' => '',
            'version' => 0,
            'source' => [],
            'users' => [],
            'forums' => [],
            'topics' => [],
            'posts' => [],
        ];

        $json = ltrim($json, "\xEF\xBB\xBF");
        if (trim($json) === '') {
            $out['errors'][] = 'Empty phpBB export.';

            return $out;
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $out['errors'][] = 'Invalid JSON: ' . $e->getMessage();

            return $out;
        }

        if (!is_array($data)) {
            $out['errors'][] = 'phpBB export must be a JSON object.';

            return $out;
        }

        $out['format'] = (string) ($data['format'] ?? '');
        $out['version'] = (int) ($data['version'] ?? 0);
        $out['source'] = is_array($data['source'] ?? null) ? $data['source'] : [];
        $out['users'] = is_array($data['users'] ?? null) ? $data['users'] : [];
        $out['forums'] = is_array($data['forums'] ?? null) ? $data['forums'] : [];
        $out['topics'] = is_array($data['topics'] ?? null) ? $data['topics'] : [];
        $out['posts'] = is_array($data['posts'] ?? null) ? $data['posts'] : [];

        if (
            $out['format'] !== ''
            && $out['format'] !== self::JSON_FORMAT
            && !str_contains($out['format'], 'phpbb')
        ) {
            $out['warnings'][] = 'Unexpected format "' . $out['format'] . '"; attempting import anyway.';
        }

        if ($out['users'] === [] && $out['forums'] === [] && $out['topics'] === [] && $out['posts'] === []) {
            $out['errors'][] = 'phpBB export has no users, forums, topics, or posts.';
        }

        return $out;
    }

    /**
     * Extract normalized rows from a phpBB PDO connection.
     *
     * @return array<string, mixed>
     */
    public static function extractFromPdo(PDO $pdo, string $tablePrefix = 'phpbb_'): array
    {
        $prefix = self::normalizeTablePrefix($tablePrefix);
        $out = [
            'errors' => [],
            'warnings' => [],
            'format' => self::JSON_FORMAT,
            'version' => 1,
            'source' => [
                'board_name' => '',
                'phpbb_version' => '',
            ],
            'users' => [],
            'forums' => [],
            'topics' => [],
            'posts' => [],
        ];

        $usersTable = $prefix . 'users';
        $forumsTable = $prefix . 'forums';
        $topicsTable = $prefix . 'topics';
        $postsTable = $prefix . 'posts';
        $configTable = $prefix . 'config';

        if (!self::tableExists($pdo, $usersTable) || !self::tableExists($pdo, $forumsTable)) {
            $out['errors'][] = 'phpBB tables not found with prefix "' . $prefix
                . '". Expected at least ' . $usersTable . ' and ' . $forumsTable . '.';

            return $out;
        }

        // Board meta from config when available.
        if (self::tableExists($pdo, $configTable)) {
            try {
                $stmt = $pdo->query(
                    'SELECT config_name, config_value FROM ' . self::quoteIdent($pdo, $configTable)
                    . " WHERE config_name IN ('sitename', 'version', 'phpbb_version')"
                );
                if ($stmt instanceof PDOStatement) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $name = (string) ($row['config_name'] ?? '');
                        $val = (string) ($row['config_value'] ?? '');
                        if ($name === 'sitename') {
                            $out['source']['board_name'] = $val;
                        }
                        if ($name === 'version' || $name === 'phpbb_version') {
                            $out['source']['phpbb_version'] = $val;
                        }
                    }
                }
            } catch (Throwable) {
                // optional
            }
        }

        try {
            $sql = 'SELECT * FROM ' . self::quoteIdent($pdo, $usersTable)
                . ' ORDER BY user_id ASC';
            $stmt = $pdo->query($sql);
            if ($stmt instanceof PDOStatement) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $out['users'][] = self::normalizeUserRow($row);
                }
            }
        } catch (Throwable $e) {
            $out['errors'][] = 'Failed reading users: ' . $e->getMessage();

            return $out;
        }

        try {
            $sql = 'SELECT * FROM ' . self::quoteIdent($pdo, $forumsTable)
                . ' ORDER BY left_id ASC, forum_id ASC';
            $stmt = $pdo->query($sql);
            if ($stmt instanceof PDOStatement) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $out['forums'][] = self::normalizeForumRow($row);
                }
            }
        } catch (Throwable $e) {
            // left_id may not exist on very old boards — retry simple order.
            try {
                $sql = 'SELECT * FROM ' . self::quoteIdent($pdo, $forumsTable)
                    . ' ORDER BY forum_id ASC';
                $stmt = $pdo->query($sql);
                if ($stmt instanceof PDOStatement) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $out['forums'][] = self::normalizeForumRow($row);
                    }
                }
            } catch (Throwable $e2) {
                $out['errors'][] = 'Failed reading forums: ' . $e2->getMessage();

                return $out;
            }
        }

        if (self::tableExists($pdo, $topicsTable)) {
            try {
                $sql = 'SELECT * FROM ' . self::quoteIdent($pdo, $topicsTable)
                    . ' ORDER BY topic_id ASC';
                $stmt = $pdo->query($sql);
                if ($stmt instanceof PDOStatement) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $out['topics'][] = self::normalizeTopicRow($row);
                    }
                }
            } catch (Throwable $e) {
                $out['errors'][] = 'Failed reading topics: ' . $e->getMessage();

                return $out;
            }
        }

        if (self::tableExists($pdo, $postsTable)) {
            try {
                $sql = 'SELECT * FROM ' . self::quoteIdent($pdo, $postsTable)
                    . ' ORDER BY post_id ASC';
                $stmt = $pdo->query($sql);
                if ($stmt instanceof PDOStatement) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $out['posts'][] = self::normalizePostRow($row);
                    }
                }
            } catch (Throwable $e) {
                $out['errors'][] = 'Failed reading posts: ' . $e->getMessage();

                return $out;
            }
        }

        return $out;
    }

    /**
     * Strip phpBB BBCode UIDs and normalize stored post text for AgoraPress.
     */
    public static function cleanPostText(string $text, string $bbcodeUid = ''): string
    {
        $text = str_replace("\0", '', $text);
        if ($text === '') {
            return '';
        }

        // phpBB sometimes stores HTML line breaks.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;

        // Strip smiley HTML comments: <!-- s:) --><img ... /><!-- s:) -->
        $text = preg_replace('/<!--\s*s.*?-->/s', '', $text) ?? $text;
        $text = preg_replace('/<!--\s*e.*?-->/s', '', $text) ?? $text;
        $text = preg_replace('/<!--\s*m.*?-->/s', '', $text) ?? $text;

        // Remove BBCode UID suffixes: [b:uid], [/b:uid], [url=x:uid], [list=1:uid]
        $uid = trim($bbcodeUid);
        if ($uid !== '' && preg_match('/^[a-zA-Z0-9]+$/', $uid)) {
            $text = str_replace(':' . $uid, '', $text);
        } else {
            // Generic hex-ish UID (5–10 chars) after tag name or attribute value.
            $text = preg_replace(
                '/(\[\/?[a-zA-Z*][a-zA-Z0-9*]*(?:=[^\]]*)?):[a-fA-F0-9]{5,10}([^\]]*\])/',
                '$1$2',
                $text
            ) ?? $text;
        }

        // Decode common entities once (phpBB double-encodes some content).
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse excessive blank lines.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Maximum upload / import size in bytes.
     */
    public static function maxBytes(): int
    {
        if (defined('AP_PHPBB_MAX_BYTES') && is_int(AP_PHPBB_MAX_BYTES) && AP_PHPBB_MAX_BYTES > 0) {
            return AP_PHPBB_MAX_BYTES;
        }
        $filter = self::DEFAULT_MAX_BYTES;
        if (function_exists('ap_apply_filters')) {
            $filter = (int) ap_apply_filters('ap_phpbb_max_bytes', $filter);
        }

        return $filter > 0 ? $filter : self::DEFAULT_MAX_BYTES;
    }

    /**
     * Human-readable byte size.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / 1048576, 1) . ' MiB';
    }

    /**
     * Convert a phpBB Unix timestamp to local datetime string.
     */
    public static function unixToDatetime(int $timestamp): string
    {
        if ($timestamp < 1) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Map phpBB topic_status to AP_Forum constants.
     */
    public static function mapTopicStatus(int $status): string
    {
        return match ($status) {
            1 => AP_Forum::TOPIC_STATUS_LOCKED,
            2 => AP_Forum::TOPIC_STATUS_MOVED,
            default => AP_Forum::TOPIC_STATUS_OPEN,
        };
    }

    /**
     * Map phpBB topic_type to AP_Forum constants.
     */
    public static function mapTopicType(int $type): string
    {
        return match ($type) {
            1 => AP_Forum::TOPIC_TYPE_STICKY,
            2 => AP_Forum::TOPIC_TYPE_ANNOUNCE,
            3 => AP_Forum::TOPIC_TYPE_GLOBAL,
            default => AP_Forum::TOPIC_TYPE_NORMAL,
        };
    }

    /**
     * Map phpBB forum_type to AP_Forum type.
     */
    public static function mapForumType(int $type): string
    {
        return match ($type) {
            0 => AP_Forum::FORUM_TYPE_CATEGORY,
            2 => AP_Forum::FORUM_TYPE_LINK,
            default => AP_Forum::FORUM_TYPE_FORUM,
        };
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function emptyResult(): array
    {
        return [
            'ok' => false,
            'users' => 0,
            'users_created' => 0,
            'users_mapped' => 0,
            'forums' => 0,
            'topics' => 0,
            'posts' => 0,
            'skipped' => 0,
            'source_name' => '',
            'source_version' => '',
            'errors' => [],
            'warnings' => [],
            'user_map' => [],
            'forum_map' => [],
            'topic_map' => [],
            'post_map' => [],
        ];
    }

    private static function resolveDb(?AP_DB $db): AP_DB
    {
        if ($db instanceof AP_DB) {
            return $db;
        }
        if (function_exists('ap_db')) {
            return ap_db();
        }
        throw new RuntimeException('No database connection available for phpBB import.');
    }

    /**
     * @param array<string, mixed> $connection
     *
     * @throws AP_DB_Exception|PDOException|InvalidArgumentException
     */
    private static function connectSource(array $connection): PDO
    {
        $driver = strtolower(trim((string) ($connection['driver'] ?? $connection['dbms'] ?? 'mysql')));
        if ($driver === 'mysqli' || $driver === 'mariadb') {
            $driver = 'mysql';
        }
        if ($driver === 'postgres' || $driver === 'postgresql') {
            $driver = 'pgsql';
        }
        if (!in_array($driver, ['mysql', 'sqlite', 'pgsql'], true)) {
            throw new InvalidArgumentException('Unsupported phpBB source driver: ' . $driver);
        }

        $name = (string) ($connection['name'] ?? $connection['database'] ?? $connection['dbname'] ?? '');
        $user = (string) ($connection['user'] ?? $connection['username'] ?? '');
        $password = (string) ($connection['password'] ?? $connection['pass'] ?? '');
        $host = (string) ($connection['host'] ?? 'localhost');
        $charset = (string) ($connection['charset'] ?? 'utf8mb4');

        if ($driver !== 'sqlite' && $name === '') {
            throw new InvalidArgumentException('phpBB database name is required.');
        }
        if ($driver === 'sqlite' && $name === '') {
            throw new InvalidArgumentException('phpBB SQLite database path is required.');
        }

        return AP_DB::createPdo($driver, $name, $user, $password, $host, $charset);
    }

    private static function normalizeTablePrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return 'phpbb_';
        }
        // Allow only safe identifier characters.
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $prefix) ?? 'phpbb_';
        if ($prefix === '') {
            return 'phpbb_';
        }

        return $prefix;
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name = ?"
                );
                $stmt->execute([$table]);

                return (bool) $stmt->fetchColumn();
            }
            // MySQL / pgsql: try a cheap probe.
            $pdo->query('SELECT 1 FROM ' . self::quoteIdent($pdo, $table) . ' WHERE 1=0');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function quoteIdent(PDO $pdo, string $ident): string
    {
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '', $ident) ?? '';
        if ($ident === '') {
            throw new InvalidArgumentException('Invalid table identifier.');
        }
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            return '`' . $ident . '`';
        }

        return '"' . $ident . '"';
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeUserRow(array $row): array
    {
        return [
            'user_id' => (int) ($row['user_id'] ?? 0),
            'username' => (string) ($row['username'] ?? $row['username_clean'] ?? ''),
            'user_email' => (string) ($row['user_email'] ?? ''),
            'user_type' => (int) ($row['user_type'] ?? 0),
            'user_regdate' => (int) ($row['user_regdate'] ?? 0),
            'user_posts' => (int) ($row['user_posts'] ?? 0),
            'user_sig' => (string) ($row['user_sig'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeForumRow(array $row): array
    {
        return [
            'forum_id' => (int) ($row['forum_id'] ?? 0),
            'parent_id' => (int) ($row['parent_id'] ?? 0),
            'forum_name' => (string) ($row['forum_name'] ?? ''),
            'forum_desc' => (string) ($row['forum_desc'] ?? ''),
            'forum_type' => (int) ($row['forum_type'] ?? 1),
            'forum_status' => (int) ($row['forum_status'] ?? 0),
            'forum_order' => (int) ($row['left_id'] ?? $row['forum_order'] ?? 0),
            'left_id' => (int) ($row['left_id'] ?? 0),
            'forum_link' => (string) ($row['forum_link'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizeTopicRow(array $row): array
    {
        return [
            'topic_id' => (int) ($row['topic_id'] ?? 0),
            'forum_id' => (int) ($row['forum_id'] ?? 0),
            'topic_title' => (string) ($row['topic_title'] ?? ''),
            'topic_poster' => (int) ($row['topic_poster'] ?? 0),
            'topic_time' => (int) ($row['topic_time'] ?? 0),
            'topic_views' => (int) ($row['topic_views'] ?? 0),
            'topic_status' => (int) ($row['topic_status'] ?? 0),
            'topic_type' => (int) ($row['topic_type'] ?? 0),
            'topic_first_post_id' => (int) ($row['topic_first_post_id'] ?? 0),
            'topic_last_post_id' => (int) ($row['topic_last_post_id'] ?? 0),
            'topic_visibility' => $row['topic_visibility'] ?? $row['topic_approved'] ?? 1,
            'topic_approved' => $row['topic_approved'] ?? $row['topic_visibility'] ?? 1,
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function normalizePostRow(array $row): array
    {
        return [
            'post_id' => (int) ($row['post_id'] ?? 0),
            'topic_id' => (int) ($row['topic_id'] ?? 0),
            'forum_id' => (int) ($row['forum_id'] ?? 0),
            'poster_id' => (int) ($row['poster_id'] ?? 0),
            'poster_ip' => (string) ($row['poster_ip'] ?? ''),
            'post_time' => (int) ($row['post_time'] ?? 0),
            'post_username' => (string) ($row['post_username'] ?? ''),
            'post_subject' => (string) ($row['post_subject'] ?? ''),
            'post_text' => (string) ($row['post_text'] ?? ''),
            'bbcode_uid' => (string) ($row['bbcode_uid'] ?? ''),
            'bbcode_bitfield' => (string) ($row['bbcode_bitfield'] ?? ''),
            'post_visibility' => $row['post_visibility'] ?? $row['post_approved'] ?? 1,
            'post_approved' => $row['post_approved'] ?? $row['post_visibility'] ?? 1,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $result
     *
     * @return array{user_id: int, phpbb_id: int, login: string, created: bool}
     */
    private static function importUser(
        array $user,
        AP_DB $db,
        array &$result,
        bool $skipBots,
        string $defaultRole
    ): array {
        $phpbbId = (int) ($user['user_id'] ?? 0);
        $type = (int) ($user['user_type'] ?? 0);
        $login = class_exists('AP_User', false)
            ? AP_User::sanitizeUserLogin((string) ($user['username'] ?? $user['user_login'] ?? ''))
            : preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($user['username'] ?? '')) ?? '';

        // Skip Anonymous (id 1 commonly) and bots.
        if ($phpbbId === 1 || strtolower($login) === 'anonymous') {
            $result['skipped']++;

            return ['user_id' => 0, 'phpbb_id' => $phpbbId, 'login' => $login, 'created' => false];
        }
        if ($skipBots && $type === self::USER_TYPE_IGNORE) {
            $result['skipped']++;

            return ['user_id' => 0, 'phpbb_id' => $phpbbId, 'login' => $login, 'created' => false];
        }

        $email = class_exists('AP_User', false)
            ? AP_User::sanitizeEmail((string) ($user['user_email'] ?? $user['email'] ?? ''))
            : trim((string) ($user['user_email'] ?? ''));

        if ($login === '' && $email === '') {
            $result['warnings'][] = 'Skipped phpBB user #' . $phpbbId . ' (empty login and email).';
            $result['skipped']++;

            return ['user_id' => 0, 'phpbb_id' => $phpbbId, 'login' => '', 'created' => false];
        }

        $existing = null;
        if ($login !== '') {
            $existing = AP_User::getByLogin($login, $db);
        }
        if ($existing === null && $email !== '') {
            $existing = AP_User::getByEmail($email, $db);
        }
        // Also match prior import by meta.
        if ($existing === null && $phpbbId > 0) {
            $byMeta = self::findUserByPhpbbId($phpbbId, $db);
            if ($byMeta !== null) {
                $existing = $byMeta;
            }
        }

        if ($existing !== null) {
            $result['users']++;
            $result['users_mapped']++;
            if ($phpbbId > 0) {
                AP_User::updateMeta((int) $existing->ID, self::META_PHPBB_USER_ID, (string) $phpbbId, $db);
            }

            return [
                'user_id' => (int) $existing->ID,
                'phpbb_id' => $phpbbId,
                'login' => $existing->user_login,
                'created' => false,
            ];
        }

        if ($login === '') {
            $login = 'phpbb_' . ($phpbbId > 0 ? (string) $phpbbId : substr(md5($email), 0, 8));
            $login = AP_User::sanitizeUserLogin($login);
        }
        if ($email === '' || !AP_User::isValidEmail($email)) {
            $safeLogin = preg_replace('/[^a-z0-9._-]/i', '', $login) ?: 'user';
            $email = $safeLogin . '@imported.invalid';
        }

        $role = $defaultRole;
        if ($type === self::USER_TYPE_FOUNDER) {
            $role = 'administrator';
        }

        $password = AP_User::generatePassword(20);
        $display = trim((string) ($user['username'] ?? $login));
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => $password,
            'display_name' => $display !== '' ? $display : $login,
            'role' => $role,
        ], $db);

        if (!$created['ok'] || $created['id'] < 1) {
            $err = $created['errors'] !== [] ? implode(' ', $created['errors']) : 'unknown error';
            $result['warnings'][] = 'Could not create user "' . $login . '": ' . $err;
            $result['skipped']++;

            return ['user_id' => 0, 'phpbb_id' => $phpbbId, 'login' => $login, 'created' => false];
        }

        $uid = (int) $created['id'];
        if ($phpbbId > 0) {
            AP_User::updateMeta($uid, self::META_PHPBB_USER_ID, (string) $phpbbId, $db);
        }
        AP_User::updateMeta($uid, self::META_NEEDS_PASSWORD_RESET, '1', $db);

        $result['users']++;
        $result['users_created']++;

        return ['user_id' => $uid, 'phpbb_id' => $phpbbId, 'login' => $login, 'created' => true];
    }

    private static function findUserByPhpbbId(int $phpbbId, AP_DB $db): ?AP_User
    {
        if ($phpbbId < 1) {
            return null;
        }
        try {
            $metaTable = $db->quoteIdentifier($db->table('usermeta'));
            $row = $db->getRow(
                'SELECT user_id FROM ' . $metaTable
                . ' WHERE meta_key = ? AND meta_value = ? LIMIT 1',
                [self::META_PHPBB_USER_ID, (string) $phpbbId]
            );
            if ($row === null) {
                return null;
            }
            $uid = (int) (is_object($row) ? ($row->user_id ?? 0) : ($row['user_id'] ?? 0));
            if ($uid < 1) {
                return null;
            }

            return AP_User::getById($uid, $db);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $forum
     * @param array<string, mixed> $result
     */
    private static function importForum(array $forum, int $parentApId, AP_DB $db, array &$result): int
    {
        $name = trim((string) ($forum['forum_name'] ?? $forum['name'] ?? ''));
        if ($name === '') {
            $name = 'Forum ' . (int) ($forum['forum_id'] ?? 0);
        }

        $type = self::mapForumType((int) ($forum['forum_type'] ?? 1));
        // phpBB forum_status: 0 unlocked, 1 locked
        $status = ((int) ($forum['forum_status'] ?? 0) === 1)
            ? AP_Forum::FORUM_STATUS_CLOSED
            : AP_Forum::FORUM_STATUS_OPEN;

        $desc = (string) ($forum['forum_desc'] ?? $forum['description'] ?? '');
        // phpBB forum_desc may contain BBCode UIDs rarely; strip generically.
        $desc = self::cleanPostText($desc, '');

        $id = AP_Forum::insertForum([
            'forum_name' => $name,
            'parent_id' => $parentApId,
            'forum_type' => $type,
            'forum_status' => $status,
            'forum_desc' => $desc,
            'forum_order' => (int) ($forum['forum_order'] ?? $forum['left_id'] ?? 0),
        ], $db);

        if ($id < 1) {
            $result['warnings'][] = 'Failed to import forum: ' . $name;
            $result['skipped']++;

            return 0;
        }

        $result['forums']++;

        return $id;
    }

    /**
     * @param array<int, int> $userMap
     */
    private static function resolveUserId(int $phpbbUserId, array $userMap, int $defaultAuthor): int
    {
        if ($phpbbUserId > 0 && isset($userMap[$phpbbUserId])) {
            return $userMap[$phpbbUserId];
        }

        return max(0, $defaultAuthor);
    }

    /**
     * phpBB visibility: 1 approved, 0 unapproved, 2 deleted, 3 reapprove.
     * Older boards used post_approved 0/1.
     */
    private static function mapVisibility(mixed $value): int
    {
        $v = (int) $value;

        return $v === 1 ? 1 : 0;
    }

    private static function stampPost(int $postId, string $datetime, AP_DB $db): void
    {
        if ($postId < 1 || $datetime === '') {
            return;
        }
        try {
            $db->update('forum_posts', [
                'post_time' => $datetime,
                'post_modified' => $datetime,
            ], ['post_id' => $postId]);
        } catch (Throwable) {
            // non-fatal
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function stampTopic(int $topicId, array $fields, AP_DB $db): void
    {
        if ($topicId < 1 || $fields === []) {
            return;
        }
        $allowed = [
            'topic_time',
            'topic_modified',
            'topic_last_post_time',
            'topic_views',
            'last_post_id',
            'last_poster_id',
            'topic_status',
            'topic_type',
        ];
        $update = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $update[$key] = $fields[$key];
            }
        }
        if ($update === []) {
            return;
        }
        try {
            $db->update('topics', $update, ['topic_id' => $topicId]);
        } catch (Throwable) {
            // non-fatal
        }
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the maximum allowed size.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension blocked the upload.',
            default => 'Upload failed (error code ' . $error . ').',
        };
    }
}
