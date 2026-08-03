<?php

/**
 * Tests for AP_Privacy — personal data export / erase + policy page.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Comment;
use AP_DB;
use AP_Migrator;
use AP_Options;
use AP_Post;
use AP_Privacy;
use AP_Roles;
use AP_Taxonomy;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Privacy::class)]
final class PrivacyTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-post.php';
        require_once $this->root . '/ap-includes/class-ap-taxonomy.php';
        require_once $this->root . '/ap-includes/class-ap-comment.php';
        require_once $this->root . '/ap-includes/class-ap-privacy.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);
        AP_Post::ensureBuiltins();
        AP_Taxonomy::ensureBuiltins();
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Post::resetRegistry();
        AP_Taxonomy::resetRegistry();
    }

    private function createUser(string $login, string $role = 'author', string $email = ''): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email !== '' ? $email : $login . '@example.test',
            'password' => 'password-secret-99',
            'display_name' => ucfirst($login),
            'role' => $role,
            'first_name' => $login,
            'description' => 'Bio for ' . $login,
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors'] ?? []));

        return $created['id'];
    }

    public function testPrivacyPolicyPageRoundTrip(): void
    {
        $adminId = $this->createUser('adminpol', 'administrator');
        $pageId = AP_Post::insert([
            'post_author' => $adminId,
            'post_title' => 'Privacy Policy',
            'post_content' => 'We respect your privacy.',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_name' => 'privacy-policy',
        ], $this->db);
        $this->assertGreaterThan(0, $pageId);

        $this->assertSame(0, AP_Privacy::getPrivacyPolicyPageId($this->db));
        $this->assertTrue(AP_Privacy::setPrivacyPolicyPageId($pageId, $this->db));
        $this->assertSame($pageId, AP_Privacy::getPrivacyPolicyPageId($this->db));
        $this->assertSame($pageId, ap_get_privacy_policy_page_id($this->db));

        $this->assertFalse(AP_Privacy::setPrivacyPolicyPageId(999999, $this->db));
        $this->assertTrue(AP_Privacy::setPrivacyPolicyPageId(0, $this->db));
        $this->assertSame(0, AP_Privacy::getPrivacyPolicyPageId($this->db));
    }

    public function testResolveUserByLoginEmailAndId(): void
    {
        $id = $this->createUser('lookupme', 'subscriber', 'lookup@example.test');

        $byId = AP_Privacy::resolveUser($id, $this->db);
        $this->assertNotNull($byId);
        $this->assertSame($id, $byId->ID);

        $byLogin = AP_Privacy::resolveUser('lookupme', $this->db);
        $this->assertNotNull($byLogin);
        $this->assertSame($id, $byLogin->ID);

        $byEmail = AP_Privacy::resolveUser('lookup@example.test', $this->db);
        $this->assertNotNull($byEmail);
        $this->assertSame($id, $byEmail->ID);

        $this->assertNull(AP_Privacy::resolveUser('nobody-here', $this->db));
    }

    public function testExportIncludesProfilePostsCommentsAndOmitsPassword(): void
    {
        $userId = $this->createUser('alice', 'author', 'alice@example.test');
        AP_User::updateMeta($userId, 'nickname', 'ally', $this->db);
        AP_User::updateMeta($userId, 'session_tokens', 'should-not-export', $this->db);

        $postId = AP_Post::insert([
            'post_author' => $userId,
            'post_title' => 'Hello privacy',
            'post_content' => 'Body text',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $commentId = AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'Alice',
            'comment_author_email' => 'alice@example.test',
            'comment_author_IP' => '203.0.113.10',
            'comment_content' => 'My comment',
            'comment_approved' => '1',
            'user_id' => $userId,
        ], $this->db);
        $this->assertGreaterThan(0, $commentId);

        $package = AP_Privacy::exportPersonalData($userId, $this->db);
        $this->assertTrue($package['ok']);
        $this->assertSame($userId, $package['user_id']);
        $this->assertNotSame([], $package['groups']);

        $byId = [];
        foreach ($package['groups'] as $group) {
            $byId[$group['group_id']] = $group;
        }

        $this->assertArrayHasKey('user', $byId);
        $this->assertSame(1, $byId['user']['item_count']);
        $profile = $byId['user']['data'][0];
        $this->assertSame('alice', $profile['user_login']);
        $this->assertSame('alice@example.test', $profile['user_email']);
        $this->assertArrayNotHasKey('user_pass', $profile);

        $this->assertArrayHasKey('usermeta', $byId);
        $metaKeys = array_column($byId['usermeta']['data'], 'meta_key');
        $this->assertContains('nickname', $metaKeys);
        $this->assertNotContains('session_tokens', $metaKeys);

        $this->assertArrayHasKey('posts', $byId);
        $this->assertGreaterThanOrEqual(1, $byId['posts']['item_count']);
        $titles = array_column($byId['posts']['data'], 'post_title');
        $this->assertContains('Hello privacy', $titles);

        $this->assertArrayHasKey('comments', $byId);
        $this->assertGreaterThanOrEqual(1, $byId['comments']['item_count']);
        $this->assertSame('203.0.113.10', $byId['comments']['data'][0]['comment_author_IP']);

        $json = AP_Privacy::exportPersonalDataJson($userId, $this->db);
        $this->assertTrue($json['ok']);
        $this->assertStringContainsString('agorapress-personal-data-export', $json['json']);
        $this->assertStringContainsString('alice', $json['filename']);
        $decoded = json_decode($json['json'], true);
        $this->assertIsArray($decoded);
        $this->assertSame('agorapress-personal-data-export', $decoded['format']);
        $this->assertStringNotContainsString('password-secret-99', $json['json']);
    }

    public function testEraseAnonymizesCommentsReassignsPostsAndDeletesUser(): void
    {
        $adminId = $this->createUser('superadmin', 'administrator');
        $authorId = $this->createUser('toerase', 'author', 'erase-me@example.test');
        $reassignId = $this->createUser('keeper', 'editor');

        $postId = AP_Post::insert([
            'post_author' => $authorId,
            'post_title' => 'Keep this post',
            'post_content' => 'Public content',
            'post_status' => 'publish',
            'post_type' => 'post',
        ], $this->db);
        $this->assertGreaterThan(0, $postId);

        $commentId = AP_Comment::insert([
            'comment_post_ID' => $postId,
            'comment_author' => 'To Erase',
            'comment_author_email' => 'erase-me@example.test',
            'comment_author_IP' => '198.51.100.5',
            'comment_content' => 'Please forget me',
            'comment_approved' => '1',
            'user_id' => $authorId,
        ], $this->db);
        $this->assertGreaterThan(0, $commentId);

        $result = AP_Privacy::erasePersonalData($authorId, [
            'reassign' => $reassignId,
        ], $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors'] ?? []));
        $this->assertNull(AP_User::getById($authorId, $this->db));

        $post = AP_Post::get($postId, $this->db);
        $this->assertNotNull($post);
        $this->assertSame($reassignId, $post->post_author);
        $this->assertSame('Keep this post', $post->post_title);

        $comment = AP_Comment::get($commentId, $this->db);
        $this->assertNotNull($comment);
        $this->assertSame(AP_Privacy::ANONYMOUS_DISPLAY_NAME, $comment->comment_author);
        $this->assertSame('', $comment->comment_author_email);
        $this->assertSame('', $comment->comment_author_IP);
        $this->assertSame(0, $comment->user_id);
        $this->assertSame('Please forget me', $comment->comment_content);

        // Admin still exists.
        $this->assertNotNull(AP_User::getById($adminId, $this->db));
    }

    public function testCannotEraseSoleAdministrator(): void
    {
        $adminId = $this->createUser('onlyadmin', 'administrator');
        $this->assertTrue(AP_Privacy::isSoleAdministrator($adminId, $this->db));

        $result = AP_Privacy::erasePersonalData($adminId, [], $this->db);
        $this->assertFalse($result['ok']);
        $this->assertNotSame([], $result['errors']);
        $this->assertNotNull(AP_User::getById($adminId, $this->db));
    }

    public function testEraseForumDataWhenPresent(): void
    {
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-private-message.php';

        $adminId = $this->createUser('forumadmin', 'administrator');
        $userId = $this->createUser('forumuser', 'subscriber', 'forumuser@example.test');

        $forumId = \AP_Forum::insertForum([
            'forum_name' => 'General',
            'forum_type' => 'forum',
        ], $this->db);
        $this->assertGreaterThan(0, $forumId);

        $topicId = \AP_Forum::createTopic([
            'forum_id' => $forumId,
            'topic_title' => 'My topic',
            'content' => 'hello world',
            'poster_id' => $userId,
            'poster_ip' => '203.0.113.50',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);

        $topicPosts = \AP_Forum::getPosts($topicId, [], $this->db);
        $this->assertNotSame([], $topicPosts);
        $postId = (int) ($topicPosts[0]->post_id ?? 0);
        $this->assertGreaterThan(0, $postId);

        $msgId = \AP_Private_Message::send([
            'sender_id' => $userId,
            'recipient_id' => $adminId,
            'subject' => 'Hi',
            'message_content' => 'secret',
        ], $this->db, [
            'check_enabled' => false,
            'check_bans' => false,
            'skip_permission' => true,
        ]);
        $this->assertGreaterThan(0, $msgId);

        $topics = $this->db->table('topics');
        $posts = $this->db->table('forum_posts');
        $messages = $this->db->table('messages');

        $export = AP_Privacy::exportPersonalData($userId, $this->db);
        $this->assertTrue($export['ok']);
        $groups = [];
        foreach ($export['groups'] as $g) {
            $groups[$g['group_id']] = $g;
        }
        $this->assertGreaterThanOrEqual(1, $groups['forum_topics']['item_count'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $groups['forum_posts']['item_count'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $groups['private_messages']['item_count'] ?? 0);

        $erase = AP_Privacy::erasePersonalData($userId, [], $this->db);
        $this->assertTrue($erase['ok'], implode('; ', $erase['errors'] ?? []));
        $this->assertNull(AP_User::getById($userId, $this->db));

        $topic = $this->db->getRow(
            'SELECT topic_poster, last_poster_id FROM ' . $this->db->quoteIdentifier($topics)
            . ' WHERE topic_id = ?',
            [$topicId]
        );
        $this->assertNotNull($topic);
        $this->assertSame(0, (int) $topic->topic_poster);

        $fpost = $this->db->getRow(
            'SELECT poster_id, poster_ip FROM ' . $this->db->quoteIdentifier($posts)
            . ' WHERE post_id = ?',
            [$postId]
        );
        $this->assertNotNull($fpost);
        $this->assertSame(0, (int) $fpost->poster_id);
        $this->assertSame('', (string) $fpost->poster_ip);

        $pmCount = (int) $this->db->getVar(
            'SELECT COUNT(*) FROM ' . $this->db->quoteIdentifier($messages)
            . ' WHERE sender_id = ? OR recipient_id = ?',
            [$userId, $userId]
        );
        $this->assertSame(0, $pmCount);
    }

    public function testPrivacyCapabilitiesOnAdministratorOnly(): void
    {
        $adminId = $this->createUser('privadmin', 'administrator');
        $editorId = $this->createUser('priveditor', 'editor');

        $this->assertTrue(AP_Roles::userCan($adminId, 'manage_privacy_options', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'export_others_personal_data', null, $this->db));
        $this->assertTrue(AP_Roles::userCan($adminId, 'erase_others_personal_data', null, $this->db));

        $this->assertFalse(AP_Roles::userCan($editorId, 'manage_privacy_options', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($editorId, 'export_others_personal_data', null, $this->db));
        $this->assertFalse(AP_Roles::userCan($editorId, 'erase_others_personal_data', null, $this->db));
    }

    public function testExportMissingUserFails(): void
    {
        $package = AP_Privacy::exportPersonalData(99999, $this->db);
        $this->assertFalse($package['ok']);
        $this->assertNotSame([], $package['errors']);
    }
}
