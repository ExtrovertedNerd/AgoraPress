<?php

/**
 * SPEC-minimum move-topic tests: dest counters, category refused,
 * missing dest cap refused, slug unchanged.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum;
use AP_Forum_Moderation;
use AP_Forum_Permissions;
use AP_Group;
use AP_Migrator;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Moderation::class)]
final class ForumMoveTopicTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-forum.php';
        require_once $this->root . '/ap-includes/class-ap-group.php';
        require_once $this->root . '/ap-includes/class-ap-forum-permissions.php';
        require_once $this->root . '/ap-includes/class-ap-forum-moderation.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        $GLOBALS['apdb'] = $this->db;
        AP_Roles::ensureDefaults($this->db);
        AP_Group::ensureSystemGroups($this->db);
        AP_Forum_Permissions::ensureDefaults($this->db);
    }

    protected function tearDown(): void
    {
        AP_Roles::flushCache();
        AP_Group::flushCache();
        AP_Forum_Permissions::flushCache();
        unset($GLOBALS['apdb']);
    }

    /**
     * SPEC: Move dest counters (topic_count, post_count, last_*).
     */
    public function testMoveTopicUpdatesDestCounters(): void
    {
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Count Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Count Dest'], $this->db);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $destId);

        $destStayId = AP_Forum::createTopic([
            'forum_id' => $destId,
            'topic_title' => 'Already on dest',
            'content' => 'Dest opening',
            'poster_id' => 11,
        ], $this->db);
        $this->assertGreaterThan(0, $destStayId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Moving thread',
            'content' => 'Source opening',
            'poster_id' => 21,
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $replyId = AP_Forum::createReply([
            'topic_id' => $topicId,
            'content' => 'Source reply',
            'poster_id' => 22,
        ], $this->db);
        $this->assertGreaterThan(0, $replyId);

        $sourceBefore = AP_Forum::getForum($sourceId, $this->db);
        $destBefore = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(1, (int) ($sourceBefore?->topic_count ?? -1));
        $this->assertSame(2, (int) ($sourceBefore?->post_count ?? -1));
        $this->assertSame($topicId, (int) ($sourceBefore?->last_topic_id ?? 0));
        $this->assertSame($replyId, (int) ($sourceBefore?->last_post_id ?? 0));
        $this->assertSame(22, (int) ($sourceBefore?->last_poster_id ?? 0));
        $this->assertSame(1, (int) ($destBefore?->topic_count ?? -1));
        $this->assertSame(1, (int) ($destBefore?->post_count ?? -1));
        $this->assertSame($destStayId, (int) ($destBefore?->last_topic_id ?? 0));

        $this->assertTrue(AP_Forum_Moderation::moveTopic($topicId, $destId, 0, $this->db));

        $source = AP_Forum::getForum($sourceId, $this->db);
        $dest = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(0, (int) ($source?->topic_count ?? -1));
        $this->assertSame(0, (int) ($source?->post_count ?? -1));
        $this->assertSame(0, (int) ($source?->last_topic_id ?? -1));
        $this->assertSame(0, (int) ($source?->last_post_id ?? -1));
        $this->assertSame(0, (int) ($source?->last_poster_id ?? -1));
        $this->assertSame(AP_Forum::EMPTY_DATETIME, (string) ($source?->last_post_time ?? ''));

        $this->assertSame(2, (int) ($dest?->topic_count ?? 0));
        $this->assertSame(3, (int) ($dest?->post_count ?? 0));
        $this->assertSame($topicId, (int) ($dest?->last_topic_id ?? 0));
        $this->assertSame($replyId, (int) ($dest?->last_post_id ?? 0));
        $this->assertSame(22, (int) ($dest?->last_poster_id ?? 0));
        $this->assertNotSame(AP_Forum::EMPTY_DATETIME, (string) ($dest?->last_post_time ?? ''));

        $this->assertSame($destId, (int) (AP_Forum::getTopic($destStayId, $this->db)?->forum_id ?? 0));
        $moved = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame($topicId, (int) ($moved?->topic_id ?? 0));
        $this->assertSame($destId, (int) ($moved?->forum_id ?? 0));
        foreach (AP_Forum::getPosts($topicId, [], $this->db) as $post) {
            $this->assertSame($destId, (int) $post->forum_id);
        }
    }

    /**
     * SPEC: Move category refused.
     */
    public function testMoveTopicRefusesCategory(): void
    {
        $categoryId = AP_Forum::insertForum([
            'forum_name' => 'Refuse Cat',
            'forum_type' => AP_Forum::FORUM_TYPE_CATEGORY,
        ], $this->db);
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Refuse Cat Source'], $this->db);
        $this->assertGreaterThan(0, $categoryId);
        $this->assertGreaterThan(0, $sourceId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Stay off category',
            'content' => 'Body',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $slug = (string) (AP_Forum::getTopic($topicId, $this->db)?->topic_slug ?? '');
        $this->assertNotSame('', $slug);

        $sourceBefore = AP_Forum::getForum($sourceId, $this->db);
        $this->assertSame(1, (int) ($sourceBefore?->topic_count ?? -1));
        $this->assertSame(1, (int) ($sourceBefore?->post_count ?? -1));

        $this->assertFalse(AP_Forum_Moderation::moveTopic($topicId, $categoryId, 0, $this->db));

        $after = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame($topicId, (int) ($after?->topic_id ?? 0));
        $this->assertSame($sourceId, (int) ($after?->forum_id ?? 0));
        $this->assertSame($slug, (string) ($after?->topic_slug ?? ''));

        $source = AP_Forum::getForum($sourceId, $this->db);
        $category = AP_Forum::getForum($categoryId, $this->db);
        $this->assertSame(1, (int) ($source?->topic_count ?? -1));
        $this->assertSame(1, (int) ($source?->post_count ?? -1));
        $this->assertSame($topicId, (int) ($source?->last_topic_id ?? 0));
        $this->assertSame(0, (int) ($category?->topic_count ?? -1));
        $this->assertSame(0, (int) ($category?->post_count ?? -1));
        $this->assertSame(0, (int) ($category?->last_topic_id ?? -1));
    }

    /**
     * SPEC: Move missing dest cap refused.
     */
    public function testMoveTopicRefusesMissingDestCap(): void
    {
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Cap Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Cap Dest'], $this->db);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $destId);

        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Stay unless dest moderate',
            'content' => 'Body',
        ], $this->db);
        $this->assertGreaterThan(0, $topicId);
        $slug = (string) (AP_Forum::getTopic($topicId, $this->db)?->topic_slug ?? '');
        $this->assertNotSame('', $slug);

        $localId = $this->createUser('src_only_mod', 'src_only_mod@example.test');
        $groupId = AP_Group::create(['group_name' => 'Source-only mods'], $this->db);
        $this->assertGreaterThan(0, $groupId);
        $this->assertGreaterThan(0, AP_Group::addMember($groupId, $localId, AP_Group::ROLE_MEMBER, $this->db));
        $this->assertTrue(AP_Forum_Permissions::setPermission(
            $sourceId,
            $groupId,
            AP_Forum_Permissions::PERM_MODERATE,
            true,
            $this->db
        ));
        $this->assertTrue(AP_Forum_Permissions::userCanModerate($localId, $sourceId, $this->db));
        $this->assertFalse(AP_Forum_Permissions::userCanModerate($localId, $destId, $this->db));

        $destBefore = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(0, (int) ($destBefore?->topic_count ?? -1));
        $this->assertSame(0, (int) ($destBefore?->post_count ?? -1));

        $this->assertFalse(AP_Forum_Moderation::moveTopic($topicId, $destId, $localId, $this->db));

        $after = AP_Forum::getTopic($topicId, $this->db);
        $this->assertSame($topicId, (int) ($after?->topic_id ?? 0));
        $this->assertSame($sourceId, (int) ($after?->forum_id ?? 0));
        $this->assertSame($slug, (string) ($after?->topic_slug ?? ''));

        $source = AP_Forum::getForum($sourceId, $this->db);
        $dest = AP_Forum::getForum($destId, $this->db);
        $this->assertSame(1, (int) ($source?->topic_count ?? -1));
        $this->assertSame(1, (int) ($source?->post_count ?? -1));
        $this->assertSame($topicId, (int) ($source?->last_topic_id ?? 0));
        $this->assertSame(0, (int) ($dest?->topic_count ?? -1));
        $this->assertSame(0, (int) ($dest?->post_count ?? -1));
        $this->assertSame(0, (int) ($dest?->last_topic_id ?? -1));
        $this->assertSame(0, (int) ($dest?->last_post_id ?? -1));
    }

    /**
     * SPEC: Move slug unchanged (same topic_id; dest slug collision does not re-uniquify).
     */
    public function testMoveTopicKeepsSlugUnchanged(): void
    {
        $sourceId = AP_Forum::insertForum(['forum_name' => 'Slug Source'], $this->db);
        $destId = AP_Forum::insertForum(['forum_name' => 'Slug Dest'], $this->db);
        $this->assertGreaterThan(0, $sourceId);
        $this->assertGreaterThan(0, $destId);

        $destTwinId = AP_Forum::createTopic([
            'forum_id' => $destId,
            'topic_title' => 'Keep this slug',
            'content' => 'Already in dest',
        ], $this->db);
        $topicId = AP_Forum::createTopic([
            'forum_id' => $sourceId,
            'topic_title' => 'Keep this slug',
            'content' => 'Moving',
        ], $this->db);
        $this->assertGreaterThan(0, $destTwinId);
        $this->assertGreaterThan(0, $topicId);

        $before = AP_Forum::getTopic($topicId, $this->db);
        $destTwin = AP_Forum::getTopic($destTwinId, $this->db);
        $this->assertNotNull($before);
        $this->assertNotNull($destTwin);
        $slug = (string) ($before->topic_slug ?? '');
        $this->assertSame('keep-this-slug', $slug);
        $this->assertSame('keep-this-slug', (string) ($destTwin->topic_slug ?? ''));
        $this->assertSame($topicId, (int) $before->topic_id);

        $this->assertTrue(AP_Forum_Moderation::moveTopic($topicId, $destId, 0, $this->db));

        $after = AP_Forum::getTopic($topicId, $this->db);
        $this->assertNotNull($after);
        $this->assertSame($topicId, (int) $after->topic_id);
        $this->assertSame($destId, (int) $after->forum_id);
        $this->assertSame($slug, (string) ($after->topic_slug ?? ''));
        $this->assertSame('keep-this-slug', (string) ($after->topic_slug ?? ''));
        $this->assertNotSame(AP_Forum::TOPIC_STATUS_MOVED, (string) ($after->topic_status ?? ''));
        $this->assertSame('keep-this-slug', (string) (AP_Forum::getTopic($destTwinId, $this->db)?->topic_slug ?? ''));
        $this->assertSame($destId, (int) (AP_Forum::getTopic($destTwinId, $this->db)?->forum_id ?? 0));
    }

    private function createUser(string $login, string $email, string $role = 'subscriber'): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'user_pass' => 'Password123!',
            'display_name' => $login,
            'role' => $role,
        ], $this->db);
        $this->assertTrue($created['ok'] ?? false, implode('; ', $created['errors'] ?? ['create failed']));
        $id = (int) ($created['id'] ?? 0);
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
