<?php

/**
 * AP-J1: moderation for logged-in members (comment_moderation_members).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Comment;

use AP_Comment;
use AP_Comment_Member_Moderation;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Comment_Member_Moderation::class)]
final class MemberModerationTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    private int $postId;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        foreach (
            [
                'version', 'hooks', 'class-ap-db', 'class-ap-migrator', 'class-ap-options', 'class-ap-user',
                'class-ap-post', 'class-ap-roles', 'class-ap-comment', 'class-ap-comment-member-moderation',
                'functions',
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
        AP_Options::flushCache();
        $this->postId = AP_Post::insert([
            'post_author' => 1, 'post_title' => 'Open post', 'post_content' => 'Body',
            'post_status' => 'publish', 'post_type' => 'post', 'comment_status' => 'open',
        ], $this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
    }

    public function testModesAndDefault(): void
    {
        $this->assertSame(['off', 'first_comment', 'all', 'links_heuristic'], AP_Comment_Member_Moderation::MODES);
        $this->assertSame('first_comment', AP_Comment_Member_Moderation::mode($this->db));
        AP_Options::update('comment_moderation_members', 'bogus', $this->db);
        $this->assertSame('first_comment', AP_Comment_Member_Moderation::mode($this->db));
        AP_Options::update('comment_moderation_members', 'all', $this->db);
        $this->assertSame('all', AP_Comment_Member_Moderation::mode($this->db));
    }

    public function testLinkCountAndKeywords(): void
    {
        $this->assertSame(0, AP_Comment_Member_Moderation::linkCount('plain text'));
        $two = 'see http://a.example and <a href="https://b.example">b</a>';
        $this->assertSame(2, AP_Comment_Member_Moderation::linkCount($two));
        $this->assertSame(1, AP_Comment_Member_Moderation::linkCount('www.spam.example now'));
        AP_Options::update('moderation_keys', "casino\n  Viagra \n\n", $this->db);
        $this->assertTrue(AP_Comment_Member_Moderation::hasModerationKeyword('Best CASINO bonus', $this->db));
        $this->assertTrue(AP_Comment_Member_Moderation::hasModerationKeyword('cheap viagra', $this->db));
        $this->assertFalse(AP_Comment_Member_Moderation::hasModerationKeyword('a nice post', $this->db));
    }

    public function testFirstCommentFromNewSubscriberIsHeldThenTrusted(): void
    {
        $sub = $this->user('newbie', 'subscriber');
        $first = $this->comment($sub, 'Hello, first comment.');
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($first));

        AP_Comment::setStatus($first, AP_Comment::STATUS_APPROVED, $this->db);
        $second = $this->comment($sub, 'Second comment, now trusted.');
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($second));

        // Spammy content is still held in first_comment mode.
        $spam = $this->comment($sub, 'http://a.example http://b.example http://c.example');
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($spam));
    }

    public function testTrustedAuthorPublishesImmediately(): void
    {
        $editor = $this->user('ed', 'editor');
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($this->comment($editor, 'Editor note.')));
        $admin = $this->user('boss', 'administrator');
        AP_Options::update('comment_moderation_members', 'all', $this->db);
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($this->comment($admin, 'Admin note.')));
    }

    public function testEachMode(): void
    {
        $sub = $this->user('pat', 'subscriber');
        $twoLinks = 'x http://a.example http://b.example';

        AP_Options::update('comment_moderation_members', 'off', $this->db);
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($this->comment($sub, 'off mode')));

        AP_Options::update('comment_moderation_members', 'all', $this->db);
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($this->comment($sub, 'all mode')));

        AP_Options::update('comment_moderation_members', 'links_heuristic', $this->db);
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($this->comment($sub, 'no links here')));
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($this->comment($sub, $twoLinks)));
        AP_Options::update('moderation_keys', 'casino', $this->db);
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($this->comment($sub, 'casino time')));
        AP_Options::update('comment_max_links', '5', $this->db);
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($this->comment($sub, $twoLinks)));
    }

    public function testExplicitStatusAndGuestsUnchanged(): void
    {
        $guest = AP_Comment::insert([
            'comment_post_ID' => $this->postId, 'comment_author' => 'Guest', 'comment_author_email' => 'g@example.test',
            'comment_content' => 'guest says hi',
        ], $this->db, ['run_spam' => false]);
        $this->assertSame(AP_Comment::STATUS_HOLD, $this->status($guest));
        $sub = $this->user('imp', 'subscriber');
        $forced = AP_Comment::insert([
            'comment_post_ID' => $this->postId, 'user_id' => $sub, 'comment_content' => 'import',
            'comment_approved' => '1',
        ], $this->db, ['run_spam' => false]);
        $this->assertSame(AP_Comment::STATUS_APPROVED, $this->status($forced));
    }

    public function testDiscussionScreenHasSetting(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-admin/options-discussion.php');
        $this->assertStringContainsString('comment_moderation_members', $src);
        $this->assertStringContainsString('first_comment', $src);
    }

    private function comment(int $userId, string $content): int
    {
        $id = AP_Comment::insert([
            'comment_post_ID' => $this->postId, 'user_id' => $userId, 'comment_author' => 'u' . $userId,
            'comment_author_email' => 'u' . $userId . '@example.test', 'comment_content' => $content,
        ], $this->db, ['run_spam' => false]);
        $this->assertGreaterThan(0, $id, $content);

        return $id;
    }

    private function status(int $id): string
    {
        return (string) $this->db->getVar(
            'SELECT comment_approved FROM ' . $this->db->table('comments') . ' WHERE comment_ID = ?',
            [$id]
        );
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
