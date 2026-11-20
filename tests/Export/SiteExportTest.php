<?php

/**
 * Full-site JSON export (item 6): admin-only, secrets redacted.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Export;

use AP_DB;
use AP_Forum;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_Site_Export;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Site_Export::class)]
final class SiteExportTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        foreach (
            [
                'version', 'hooks', 'class-ap-db', 'class-ap-migrator', 'class-ap-options', 'class-ap-user',
                'class-ap-post', 'class-ap-roles', 'class-ap-forum', 'class-ap-site-export', 'functions',
            ] as $f
        ) {
            require_once $this->root . '/ap-includes/' . $f . '.php';
        }
        AP_Roles::flushCache();
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        (new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath()))->migrate();
        $GLOBALS['apdb'] = $this->db;
        AP_Roles::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        unset($GLOBALS['apdb']);
    }

    public function testOnlyAdministratorsMayExport(): void
    {
        $this->assertFalse(AP_Site_Export::canExport(0, $this->db));
        $this->assertFalse(AP_Site_Export::canExport($this->user('ed', 'editor'), $this->db));
        $this->assertFalse(AP_Site_Export::canExport($this->user('sub', 'subscriber'), $this->db));
        $this->assertTrue(AP_Site_Export::canExport($this->user('boss', 'administrator'), $this->db));
    }

    public function testExportHasContentTablesAndRedactsSecrets(): void
    {
        $admin = $this->user('boss', 'administrator');
        $postId = AP_Post::insert([
            'post_author' => $admin, 'post_title' => 'Hello export', 'post_content' => 'Body',
            'post_status' => 'publish', 'post_type' => 'post',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);
        AP_Forum::insertForum(['forum_name' => 'Board'], $this->db);
        AP_Options::update('blogname', 'Export Site', $this->db);
        AP_Options::update('smtp_password', 'hunter2-secret', $this->db);
        AP_Options::update('agent_bridge_keys', 'k-secret-123', $this->db);
        $this->db->insert('usermeta', [
            'user_id' => $admin, 'meta_key' => 'session_tokens', 'meta_value' => 'tok-secret',
        ]);
        $this->db->insert('usermeta', ['user_id' => $admin, 'meta_key' => 'nickname', 'meta_value' => 'Boss']);
        $this->db->insert('messages', [
            'sender_id' => $admin, 'recipient_id' => $admin, 'parent_id' => 0, 'subject' => 'private-dm',
            'message_content' => 'dm-secret', 'sent_at' => '2026-11-20 16:00:00', 'sender_deleted' => 0,
            'recipient_deleted' => 0,
        ]);

        $data = AP_Site_Export::build($this->db);
        $this->assertSame('agorapress-export', $data['format']);
        $this->assertSame(1, $data['format_version']);
        $this->assertSame(AP_VERSION, $data['ap_version']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['generated_gmt']);
        $tables = [
            'posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'comments', 'users', 'options',
            'forums', 'topics', 'forum_posts',
        ];
        foreach ($tables as $t) {
            $this->assertArrayHasKey($t, $data['tables'], $t);
        }
        $this->assertArrayNotHasKey('messages', $data['tables']);
        $this->assertArrayNotHasKey('schema_migrations', $data['tables']);
        $this->assertSame('Hello export', $data['tables']['posts'][0]['post_title']);
        $this->assertSame('', $data['tables']['users'][0]['user_pass']);

        $json = AP_Site_Export::toJson($data);
        $this->assertJson($json);
        $this->assertStringContainsString('Export Site', $json);
        $this->assertStringContainsString('Boss', $json);
        foreach (['hunter2-secret', 'k-secret-123', 'tok-secret', 'dm-secret', '$argon2', '$2y$'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, $secret);
        }
    }

    public function testNonSecretWordListOptionsSurviveExport(): void
    {
        AP_Options::update('moderation_keys', "viagra\ncasino-words", $this->db);
        AP_Options::update('forum_spam_blacklist', 'spamword-list', $this->db);
        AP_Options::update('rate_limit_password_reset_max', '5', $this->db);
        AP_Options::update('smtp_pass', 'smtp-secret-9', $this->db);
        AP_Options::update('hall_of_fame_token', 'hof-secret-7', $this->db);

        $json = AP_Site_Export::toJson(AP_Site_Export::build($this->db));
        $this->assertStringContainsString('casino-words', $json);
        $this->assertStringContainsString('spamword-list', $json);
        $this->assertStringContainsString('rate_limit_password_reset_max', $json);
        $this->assertStringNotContainsString('smtp-secret-9', $json);
        $this->assertStringNotContainsString('hof-secret-7', $json);
        $this->assertFalse(AP_Site_Export::isSecretOption('moderation_keys'));
        $this->assertTrue(AP_Site_Export::isSecretOption('agent_bridge_keys'));
    }

    public function testFilenameIsSafe(): void
    {
        $name = AP_Site_Export::filename('Ken\'s "Site"/../x', 1795203324);
        $this->assertMatchesRegularExpression('/^agorapress-export-[a-z0-9-]+-\d{8}-\d{6}\.json$/', $name);
    }

    public function testAdminScreenChecksCapabilityAndNonce(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/export.php');
        $this->assertStringContainsString('AP_Site_Export::canExport', $src);
        $this->assertStringContainsString("ap_check_nonce", $src);
        $this->assertStringContainsString("'site-export'", $src);
    }

    private function user(string $login, string $role): int
    {
        $this->db->insert('users', [
            'user_login' => $login, 'user_pass' => AP_User::hashPassword('x-' . $login), 'user_nicename' => $login,
            'user_email' => $login . '@example.test', 'user_url' => '', 'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '', 'user_status' => 0, 'display_name' => $login,
        ]);
        $id = (int) $this->db->lastInsertId();
        AP_Roles::setUserRole($id, $role, $this->db);

        return $id;
    }
}
