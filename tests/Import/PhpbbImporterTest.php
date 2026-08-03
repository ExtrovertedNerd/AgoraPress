<?php

/**
 * Tests for AP_Phpbb_Importer (phpBB board import).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Import;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use AP_Phpbb_Importer;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Phpbb_Importer::class)]
final class PhpbbImporterTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-content-format.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-phpbb-importer.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
    }

    public function testIsPhpbbJsonDetectsFormat(): void
    {
        $this->assertTrue(AP_Phpbb_Importer::isPhpbbJson($this->sampleJson()));
        $this->assertTrue(AP_Phpbb_Importer::isPhpbbJson(
            '{"format":"agorapress-phpbb-export","users":[],"forums":[]}'
        ));
        $this->assertFalse(AP_Phpbb_Importer::isPhpbbJson('<html>nope</html>'));
        $this->assertFalse(AP_Phpbb_Importer::isPhpbbJson(''));
        $this->assertFalse(AP_Phpbb_Importer::isPhpbbJson('{"foo":1}'));
    }

    public function testCleanPostTextStripsBbcodeUid(): void
    {
        $uid = 'a1b2c3';
        $raw = '[b:' . $uid . ']Bold[/b:' . $uid . '] and [url=https://example.com:' . $uid . ']link[/url:' . $uid . ']';
        $clean = AP_Phpbb_Importer::cleanPostText($raw, $uid);
        $this->assertStringContainsString('[b]Bold[/b]', $clean);
        $this->assertStringContainsString('[url=https://example.com]link[/url]', $clean);
        $this->assertStringNotContainsString($uid, $clean);
    }

    public function testCleanPostTextStripsSmileyCommentsAndBr(): void
    {
        $raw = "Hello<!-- s:) --><img src=\"x\" /><!-- s:) --><br />world";
        $clean = AP_Phpbb_Importer::cleanPostText($raw, '');
        $this->assertStringContainsString("Hello", $clean);
        $this->assertStringContainsString("world", $clean);
        $this->assertStringNotContainsString('<!--', $clean);
        $this->assertStringNotContainsString('<br', $clean);
    }

    public function testParseJsonExtractsCollections(): void
    {
        $parsed = AP_Phpbb_Importer::parseJson($this->sampleJson());
        $this->assertSame([], $parsed['errors']);
        $this->assertSame(AP_Phpbb_Importer::JSON_FORMAT, $parsed['format']);
        $this->assertSame('Demo Board', $parsed['source']['board_name']);
        $this->assertCount(2, $parsed['users']);
        $this->assertCount(2, $parsed['forums']);
        $this->assertCount(1, $parsed['topics']);
        $this->assertCount(2, $parsed['posts']);
    }

    public function testParseJsonRejectsInvalid(): void
    {
        $parsed = AP_Phpbb_Importer::parseJson('not json');
        $this->assertNotEmpty($parsed['errors']);
    }

    public function testImportFromStringCreatesForumContent(): void
    {
        $result = AP_Phpbb_Importer::importFromString($this->sampleJson(), $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(2, $result['users']);
        $this->assertSame(2, $result['users_created']);
        $this->assertSame(2, $result['forums']);
        $this->assertSame(1, $result['topics']);
        $this->assertSame(2, $result['posts']);

        $alice = AP_User::getByLogin('alice', $this->db);
        $this->assertNotNull($alice);
        $this->assertSame('alice@example.com', $alice->user_email);
        $meta = AP_User::getMeta((int) $alice->ID, AP_Phpbb_Importer::META_PHPBB_USER_ID, $this->db);
        $this->assertSame('2', $meta);
        $reset = AP_User::getMeta((int) $alice->ID, AP_Phpbb_Importer::META_NEEDS_PASSWORD_RESET, $this->db);
        $this->assertSame('1', $reset);

        $forums = AP_Forum::getForums(['limit' => 50], $this->db);
        $names = array_map(static fn ($f) => (string) $f->forum_name, $forums);
        $this->assertContains('General', $names);
        $this->assertContains('News', $names);

        $news = null;
        foreach ($forums as $f) {
            if ((string) $f->forum_name === 'News') {
                $news = $f;
                break;
            }
        }
        $this->assertNotNull($news);
        $this->assertSame(AP_Forum::FORUM_TYPE_FORUM, (string) $news->forum_type);

        $topics = AP_Forum::getTopics((int) $news->forum_id, ['limit' => 10], $this->db);
        $this->assertNotEmpty($topics);
        $topic = $topics[0];
        $this->assertSame('Welcome to the board', (string) $topic->topic_title);
        $this->assertSame(AP_Forum::TOPIC_STATUS_OPEN, (string) $topic->topic_status);
        $this->assertSame((int) $alice->ID, (int) $topic->topic_poster);
        $this->assertSame(42, (int) $topic->topic_views);
        $this->assertStringStartsWith('2020-01-15', (string) $topic->topic_time);

        $posts = AP_Forum::getPosts((int) $topic->topic_id, ['limit' => 20], $this->db);
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('[b]Hello[/b]', (string) $posts[0]->post_content);
        $this->assertStringContainsString('Reply body', (string) $posts[1]->post_content);
        $this->assertStringNotContainsString('x7y8z9', (string) $posts[0]->post_content);

        $bob = AP_User::getByLogin('bob', $this->db);
        $this->assertNotNull($bob);
        $this->assertSame((int) $bob->ID, (int) $posts[1]->poster_id);
    }

    public function testImportMapsExistingUserByLogin(): void
    {
        AP_User::create([
            'user_login' => 'alice',
            'user_email' => 'alice@example.com',
            'user_pass' => 'Password123!',
            'display_name' => 'Alice Existing',
            'role' => 'subscriber',
        ], $this->db);

        $result = AP_Phpbb_Importer::importFromString($this->sampleJson(), $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(1, $result['users_mapped']);
        $this->assertSame(1, $result['users_created']); // bob only
    }

    public function testImportFromSqliteSourceDatabase(): void
    {
        $sourcePath = tempnam(sys_get_temp_dir(), 'ap_phpbb_src_');
        $this->assertNotFalse($sourcePath);
        @unlink($sourcePath);
        $sourcePath .= '.sqlite';

        try {
            $pdo = new PDO('sqlite:' . $sourcePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->seedPhpbbSqlite($pdo, 'phpbb_');

            $result = AP_Phpbb_Importer::importFromDatabase([
                'driver' => 'sqlite',
                'name' => $sourcePath,
                'table_prefix' => 'phpbb_',
            ], $this->db);

            $this->assertTrue($result['ok'], implode('; ', $result['errors']));
            $this->assertGreaterThanOrEqual(1, $result['users']);
            $this->assertGreaterThanOrEqual(1, $result['forums']);
            $this->assertGreaterThanOrEqual(1, $result['topics']);
            $this->assertGreaterThanOrEqual(1, $result['posts']);
            $this->assertSame('SQLite Board', $result['source_name']);
        } finally {
            @unlink($sourcePath);
        }
    }

    public function testImportFromFileAndProceduralHelpers(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ap_phpbb_json_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $this->sampleJson());

        try {
            $result = AP_Phpbb_Importer::importFromFile($tmp, $this->db);
            $this->assertTrue($result['ok'], implode('; ', $result['errors']));

            // Fresh DB for procedural path would be heavy; just assert helpers exist.
            $this->assertTrue(function_exists('ap_import_phpbb'));
            $this->assertTrue(function_exists('ap_import_phpbb_string'));
            $this->assertTrue(function_exists('ap_import_phpbb_database'));
            $this->assertTrue(function_exists('ap_import_phpbb_upload'));
            $this->assertTrue(function_exists('ap_is_phpbb_json'));
            $this->assertTrue(function_exists('ap_parse_phpbb_json'));
            $this->assertTrue(function_exists('ap_clean_phpbb_post_text'));

            $this->assertTrue(ap_is_phpbb_json($this->sampleJson()));
            $parsed = ap_parse_phpbb_json($this->sampleJson());
            $this->assertSame([], $parsed['errors']);
            $cleaned = ap_clean_phpbb_post_text('[b:abc12]x[/b:abc12]', 'abc12');
            $this->assertSame('[b]x[/b]', $cleaned);
        } finally {
            @unlink($tmp);
        }
    }

    public function testImportFromFileMissing(): void
    {
        $missing = AP_Phpbb_Importer::importFromFile(
            '/tmp/does-not-exist-phpbb-' . bin2hex(random_bytes(4)) . '.json',
            $this->db
        );
        $this->assertFalse($missing['ok']);
        $this->assertNotEmpty($missing['errors']);
    }

    public function testSkipBots(): void
    {
        $json = json_encode([
            'format' => AP_Phpbb_Importer::JSON_FORMAT,
            'version' => 1,
            'source' => ['board_name' => 'Bots', 'phpbb_version' => '3.3.0'],
            'users' => [
                [
                    'user_id' => 50,
                    'username' => 'Botty',
                    'user_email' => 'bot@example.com',
                    'user_type' => AP_Phpbb_Importer::USER_TYPE_IGNORE,
                ],
                [
                    'user_id' => 51,
                    'username' => 'human',
                    'user_email' => 'human@example.com',
                    'user_type' => AP_Phpbb_Importer::USER_TYPE_NORMAL,
                ],
            ],
            'forums' => [],
            'topics' => [],
            'posts' => [],
        ], JSON_THROW_ON_ERROR);

        $result = AP_Phpbb_Importer::importFromString($json, $this->db, ['skip_bots' => true]);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertSame(1, $result['users']);
        $this->assertNull(AP_User::getByLogin('Botty', $this->db));
        $this->assertNotNull(AP_User::getByLogin('human', $this->db));
    }

    public function testMapHelpers(): void
    {
        $this->assertSame(AP_Forum::TOPIC_STATUS_LOCKED, AP_Phpbb_Importer::mapTopicStatus(1));
        $this->assertSame(AP_Forum::TOPIC_TYPE_STICKY, AP_Phpbb_Importer::mapTopicType(1));
        $this->assertSame(AP_Forum::TOPIC_TYPE_ANNOUNCE, AP_Phpbb_Importer::mapTopicType(2));
        $this->assertSame(AP_Forum::TOPIC_TYPE_GLOBAL, AP_Phpbb_Importer::mapTopicType(3));
        $this->assertSame(AP_Forum::FORUM_TYPE_CATEGORY, AP_Phpbb_Importer::mapForumType(0));
        $this->assertSame(AP_Forum::FORUM_TYPE_FORUM, AP_Phpbb_Importer::mapForumType(1));
        $this->assertSame(AP_Forum::FORUM_TYPE_LINK, AP_Phpbb_Importer::mapForumType(2));
        $dt = AP_Phpbb_Importer::unixToDatetime(1579093200);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dt);
        $this->assertSame(date('Y-m-d H:i:s', 1579093200), $dt);
    }

    private function sampleJson(): string
    {
        $data = [
            'format' => AP_Phpbb_Importer::JSON_FORMAT,
            'version' => 1,
            'source' => [
                'board_name' => 'Demo Board',
                'phpbb_version' => '3.3.10',
            ],
            'users' => [
                [
                    'user_id' => 2,
                    'username' => 'alice',
                    'user_email' => 'alice@example.com',
                    'user_type' => 0,
                    'user_regdate' => 1577836800,
                ],
                [
                    'user_id' => 3,
                    'username' => 'bob',
                    'user_email' => 'bob@example.com',
                    'user_type' => 0,
                    'user_regdate' => 1577923200,
                ],
            ],
            'forums' => [
                [
                    'forum_id' => 1,
                    'parent_id' => 0,
                    'forum_name' => 'General',
                    'forum_desc' => 'Root category',
                    'forum_type' => 0,
                    'forum_status' => 0,
                    'left_id' => 1,
                    'forum_order' => 1,
                ],
                [
                    'forum_id' => 2,
                    'parent_id' => 1,
                    'forum_name' => 'News',
                    'forum_desc' => 'Announcements',
                    'forum_type' => 1,
                    'forum_status' => 0,
                    'left_id' => 2,
                    'forum_order' => 2,
                ],
            ],
            'topics' => [
                [
                    'topic_id' => 10,
                    'forum_id' => 2,
                    'topic_title' => 'Welcome to the board',
                    'topic_poster' => 2,
                    'topic_time' => 1579093200,
                    'topic_views' => 42,
                    'topic_status' => 0,
                    'topic_type' => 0,
                    'topic_first_post_id' => 100,
                    'topic_last_post_id' => 101,
                    'topic_visibility' => 1,
                ],
            ],
            'posts' => [
                [
                    'post_id' => 100,
                    'topic_id' => 10,
                    'forum_id' => 2,
                    'poster_id' => 2,
                    'poster_ip' => '127.0.0.1',
                    'post_time' => 1579093200,
                    'post_subject' => 'Welcome to the board',
                    'post_text' => '[b:x7y8z9]Hello[/b:x7y8z9] from phpBB',
                    'bbcode_uid' => 'x7y8z9',
                    'post_visibility' => 1,
                ],
                [
                    'post_id' => 101,
                    'topic_id' => 10,
                    'forum_id' => 2,
                    'poster_id' => 3,
                    'poster_ip' => '127.0.0.1',
                    'post_time' => 1579179600,
                    'post_subject' => 'Re: Welcome to the board',
                    'post_text' => 'Reply body from bob',
                    'bbcode_uid' => 'aabbcc',
                    'post_visibility' => 1,
                ],
            ],
        ];

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    private function seedPhpbbSqlite(PDO $pdo, string $prefix): void
    {
        $pdo->exec('CREATE TABLE ' . $prefix . 'config (
            config_name TEXT PRIMARY KEY,
            config_value TEXT
        )');
        $pdo->exec('CREATE TABLE ' . $prefix . 'users (
            user_id INTEGER PRIMARY KEY,
            username TEXT,
            user_email TEXT,
            user_type INTEGER,
            user_regdate INTEGER,
            user_posts INTEGER DEFAULT 0,
            user_sig TEXT DEFAULT ""
        )');
        $pdo->exec('CREATE TABLE ' . $prefix . 'forums (
            forum_id INTEGER PRIMARY KEY,
            parent_id INTEGER,
            forum_name TEXT,
            forum_desc TEXT,
            forum_type INTEGER,
            forum_status INTEGER,
            left_id INTEGER,
            forum_order INTEGER,
            forum_link TEXT DEFAULT ""
        )');
        $pdo->exec('CREATE TABLE ' . $prefix . 'topics (
            topic_id INTEGER PRIMARY KEY,
            forum_id INTEGER,
            topic_title TEXT,
            topic_poster INTEGER,
            topic_time INTEGER,
            topic_views INTEGER,
            topic_status INTEGER,
            topic_type INTEGER,
            topic_first_post_id INTEGER,
            topic_last_post_id INTEGER,
            topic_visibility INTEGER
        )');
        $pdo->exec('CREATE TABLE ' . $prefix . 'posts (
            post_id INTEGER PRIMARY KEY,
            topic_id INTEGER,
            forum_id INTEGER,
            poster_id INTEGER,
            poster_ip TEXT,
            post_time INTEGER,
            post_username TEXT DEFAULT "",
            post_subject TEXT,
            post_text TEXT,
            bbcode_uid TEXT,
            bbcode_bitfield TEXT DEFAULT "",
            post_visibility INTEGER
        )');

        $pdo->exec("INSERT INTO {$prefix}config (config_name, config_value) VALUES
            ('sitename', 'SQLite Board'),
            ('version', '3.3.5')");
        $pdo->exec("INSERT INTO {$prefix}users (user_id, username, user_email, user_type, user_regdate) VALUES
            (1, 'Anonymous', '', 2, 0),
            (2, 'carol', 'carol@example.com', 0, 1600000000)");
        $pdo->exec("INSERT INTO {$prefix}forums (forum_id, parent_id, forum_name, forum_desc, forum_type, forum_status, left_id, forum_order) VALUES
            (1, 0, 'Main', 'Main forum', 1, 0, 1, 1)");
        $pdo->exec("INSERT INTO {$prefix}topics (topic_id, forum_id, topic_title, topic_poster, topic_time, topic_views, topic_status, topic_type, topic_first_post_id, topic_last_post_id, topic_visibility) VALUES
            (5, 1, 'First topic', 2, 1600001000, 7, 0, 0, 20, 20, 1)");
        $pdo->exec("INSERT INTO {$prefix}posts (post_id, topic_id, forum_id, poster_id, poster_ip, post_time, post_subject, post_text, bbcode_uid, post_visibility) VALUES
            (20, 5, 1, 2, '10.0.0.1', 1600001000, 'First topic', '[i:uid99]Hi[/i:uid99]', 'uid99', 1)");
    }
}
