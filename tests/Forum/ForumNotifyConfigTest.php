<?php

/**
 * Tests for topic-notify site options (AP_Forum_Notify).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_Cron;
use AP_DB;
use AP_Forum_Notify;
use AP_Installer;
use AP_Mail;
use AP_Migrator;
use AP_Options;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Forum_Notify::class)]
final class ForumNotifyConfigTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-installer.php';
        require_once $this->root . '/ap-includes/class-ap-forum-notify.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-cron.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();
        AP_Cron::reset();
        AP_Mail::resetForTests();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
    }

    protected function tearDown(): void
    {
        AP_Options::flushCache();
        AP_Cron::reset();
        AP_Mail::resetForTests();
    }

    public function testOptionConstantsAndDefaults(): void
    {
        $this->assertSame('forum_topic_notify_enabled', AP_Forum_Notify::OPTION_ENABLED);
        $this->assertSame('forum_notify_max_per_minute', AP_Forum_Notify::OPTION_MAX_PER_MINUTE);
        $this->assertSame('forum_notify_email', AP_Forum_Notify::META_NOTIFY_EMAIL);
        $this->assertSame('ap_forum_topic_notify', AP_Forum_Notify::CRON_HOOK);
        $this->assertFalse(AP_Forum_Notify::DEFAULT_ENABLED);
        $this->assertFalse(AP_Forum_Notify::DEFAULT_USER_ENABLED);
        $this->assertSame(4, AP_Forum_Notify::DEFAULT_MAX_PER_MINUTE);
        $this->assertSame(1, AP_Forum_Notify::MIN_PER_MINUTE);
        $this->assertSame(60, AP_Forum_Notify::MAX_PER_MINUTE);

        $map = AP_Forum_Notify::defaultOptionMap();
        $this->assertSame('0', $map[AP_Forum_Notify::OPTION_ENABLED]);
        $this->assertSame('4', $map[AP_Forum_Notify::OPTION_MAX_PER_MINUTE]);
    }

    public function testSchema13SeedsOffAndFour(): void
    {
        $this->assertSame(
            '0',
            (string) AP_Options::get(AP_Forum_Notify::OPTION_ENABLED, 'missing', $this->db)
        );
        $this->assertSame(
            '4',
            (string) AP_Options::get(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, 'missing', $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertFalse(ap_forum_topic_notify_enabled($this->db));
        $this->assertFalse(AP_Forum_Notify::shouldShowChrome($this->db));
        $this->assertFalse(ap_forum_notify_should_show_chrome($this->db));
        $this->assertSame(4, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame(4, ap_forum_notify_max_per_minute($this->db));
    }

    public function testMissingOptionIsTreatedAsDefault(): void
    {
        $this->db->delete('options', ['option_name' => AP_Forum_Notify::OPTION_ENABLED]);
        $this->db->delete('options', ['option_name' => AP_Forum_Notify::OPTION_MAX_PER_MINUTE]);
        AP_Options::flushCache();

        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertFalse(ap_forum_topic_notify_enabled($this->db));
        $this->assertFalse(AP_Forum_Notify::shouldShowChrome($this->db));
        $this->assertFalse(ap_forum_notify_should_show_chrome($this->db));
        $this->assertSame(4, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame(4, ap_forum_notify_max_per_minute($this->db));
    }

    public function testEnabledWhenOptionIsOne(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::isEnabled($this->db));
        $this->assertTrue(ap_forum_topic_notify_enabled($this->db));
        $this->assertTrue(AP_Forum_Notify::shouldShowChrome($this->db));
        $this->assertTrue(ap_forum_notify_should_show_chrome($this->db));

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertFalse(ap_forum_topic_notify_enabled($this->db));
        $this->assertFalse(AP_Forum_Notify::shouldShowChrome($this->db));
        $this->assertFalse(ap_forum_notify_should_show_chrome($this->db));
    }

    public function testSiteOffMeansNoChromeNoEnqueueNoSend(): void
    {
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertFalse(AP_Forum_Notify::shouldShowChrome($this->db));
        $this->assertFalse(ap_forum_notify_should_show_chrome($this->db));

        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();

        $this->assertFalse(AP_Forum_Notify::enqueueReply(12, 34, $this->db));
        $this->assertFalse(ap_forum_notify_enqueue_reply(12, 34, $this->db));
        $this->assertFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [12, 34], $this->db)
        );

        $this->assertFalse(AP_Forum_Notify::send(
            'member@example.com',
            '[Example] New reply in Hello',
            'A reply landed.',
            [],
            $this->db
        ));
        $this->assertFalse(ap_forum_notify_send(
            'member@example.com',
            '[Example] New reply in Hello',
            'A reply landed.',
            [],
            $this->db
        ));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testSiteOnEnqueuesCronButSendDoesNotCallMail(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::shouldShowChrome($this->db));

        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();

        $this->assertTrue(AP_Forum_Notify::enqueueReply(12, 34, $this->db));
        $this->assertNotFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [12, 34], $this->db)
        );
        $this->assertTrue(ap_forum_notify_enqueue_reply(12, 34, $this->db));

        $this->assertFalse(AP_Forum_Notify::enqueueReply(0, 34, $this->db));
        $this->assertFalse(AP_Forum_Notify::enqueueReply(12, 0, $this->db));

        $this->assertFalse(AP_Forum_Notify::send(
            'member@example.com',
            '[Example] New reply in Hello',
            'A reply landed.',
            [],
            $this->db
        ));
        $this->assertSame([], AP_Mail::getTestOutbox());

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::enqueueReply(99, 100, $this->db));
        $this->assertFalse(
            AP_Cron::nextScheduled(AP_Forum_Notify::CRON_HOOK, [99, 100], $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::send(
            'member@example.com',
            '[Example] New reply in Hello',
            'A reply landed.',
            [],
            $this->db
        ));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testSanitizeEnabled(): void
    {
        $this->assertSame('1', AP_Forum_Notify::sanitizeEnabled(true));
        $this->assertSame('1', AP_Forum_Notify::sanitizeEnabled('1'));
        $this->assertSame('1', AP_Forum_Notify::sanitizeEnabled('on'));
        $this->assertSame('1', AP_Forum_Notify::sanitizeEnabled('yes'));
        $this->assertSame('0', AP_Forum_Notify::sanitizeEnabled(false));
        $this->assertSame('0', AP_Forum_Notify::sanitizeEnabled('0'));
        $this->assertSame('0', AP_Forum_Notify::sanitizeEnabled('off'));
        $this->assertSame('0', AP_Forum_Notify::sanitizeEnabled(null));
    }

    public function testMaxPerMinuteClampedAndDefaulted(): void
    {
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute(null));
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute(''));
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute('nope'));
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute(0));
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute(-5));
        $this->assertSame(1, AP_Forum_Notify::sanitizeMaxPerMinute(1));
        $this->assertSame(4, AP_Forum_Notify::sanitizeMaxPerMinute('4'));
        $this->assertSame(12, AP_Forum_Notify::sanitizeMaxPerMinute('12'));
        $this->assertSame(60, AP_Forum_Notify::sanitizeMaxPerMinute(999));

        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, '8', $this->db);
        $this->assertSame(8, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame(8, ap_forum_notify_max_per_minute($this->db));

        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, 'bogus', $this->db);
        $this->assertSame(4, AP_Forum_Notify::getMaxPerMinute($this->db));
    }

    public function testUpdateSettingsPersistsBothKeys(): void
    {
        $this->assertTrue(AP_Forum_Notify::updateSettings([
            'forum_topic_notify_enabled' => '1',
            'forum_notify_max_per_minute' => 10,
        ], $this->db));

        $this->assertTrue(AP_Forum_Notify::isEnabled($this->db));
        $this->assertSame(10, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame('1', (string) AP_Options::get(AP_Forum_Notify::OPTION_ENABLED, 'x', $this->db));
        $this->assertSame('10', (string) AP_Options::get(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, 'x', $this->db));

        $this->assertTrue(AP_Forum_Notify::updateSettings([
            'enabled' => false,
        ], $this->db));
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertSame(10, AP_Forum_Notify::getMaxPerMinute($this->db));
    }

    public function testInstallerSeedOptionsWritesNotifyDefaults(): void
    {
        $this->db->delete('options', ['option_name' => AP_Forum_Notify::OPTION_ENABLED]);
        $this->db->delete('options', ['option_name' => AP_Forum_Notify::OPTION_MAX_PER_MINUTE]);
        AP_Options::flushCache();

        $this->assertNull(AP_Options::get(AP_Forum_Notify::OPTION_ENABLED, null, $this->db));
        $this->assertNull(AP_Options::get(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, null, $this->db));

        AP_Installer::seedOptions(
            $this->db,
            ['title' => 'Notify Seed', 'url' => 'https://example.com'],
            ['email' => 'admin@example.com']
        );
        AP_Options::flushCache();

        $this->assertSame(
            '0',
            (string) AP_Options::get(AP_Forum_Notify::OPTION_ENABLED, 'missing', $this->db)
        );
        $this->assertSame(
            '4',
            (string) AP_Options::get(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, 'missing', $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertSame(4, AP_Forum_Notify::getMaxPerMinute($this->db));
    }

    public function testSeedDefaultsDoesNotOverwriteStoredValues(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        AP_Options::update(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, '9', $this->db);

        AP_Forum_Notify::seedDefaults($this->db);
        AP_Options::flushCache();

        $this->assertSame('1', (string) AP_Options::get(AP_Forum_Notify::OPTION_ENABLED, 'x', $this->db));
        $this->assertSame('9', (string) AP_Options::get(AP_Forum_Notify::OPTION_MAX_PER_MINUTE, 'x', $this->db));
    }

    public function testDoesNotShareRateLimitMailBucketName(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-forum-notify.php');
        $this->assertStringContainsString('rate_limit_mail', $src);
        $this->assertStringContainsString('does not consume', strtolower($src));
        $this->assertSame('forum_notify_max_per_minute', AP_Forum_Notify::OPTION_MAX_PER_MINUTE);
        $this->assertNotSame('rate_limit_mail', AP_Forum_Notify::OPTION_MAX_PER_MINUTE);
    }

    public function testGuestAndMissingUserMetaAreOff(): void
    {
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled(0, $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled(-3, $this->db));
        $this->assertSame('0', AP_Forum_Notify::userNotifyStoredValue(0, $this->db));
        $this->assertFalse(ap_forum_user_notify_enabled(0, $this->db));
        $this->assertFalse(AP_Forum_Notify::setUserNotifyEnabled(0, true, $this->db));
        $this->assertFalse(AP_Forum_Notify::seedUserDefault(0, $this->db));

        $id = $this->insertUserRow('notify-missing');
        $this->assertNull(AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertFalse(ap_forum_user_notify_enabled($id, $this->db));
        $this->assertSame('0', AP_Forum_Notify::userNotifyStoredValue($id, $this->db));
    }

    public function testCreateSeedsUserNotifyMetaOff(): void
    {
        $id = $this->createMember('notify-seed');
        $this->assertSame(
            '0',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertFalse(ap_forum_user_notify_enabled($id, $this->db));
        $this->assertSame('0', AP_Forum_Notify::userNotifyStoredValue($id, $this->db));
    }

    public function testSeedUserDefaultWritesZeroAndDoesNotOverwriteOn(): void
    {
        $id = $this->insertUserRow('notify-seed-default');
        $this->assertNull(AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db));

        $this->assertTrue(AP_Forum_Notify::seedUserDefault($id, $this->db));
        $this->assertSame(
            '0',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));

        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($id, true, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertSame(
            '1',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );

        $this->assertTrue(AP_Forum_Notify::seedUserDefault($id, $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertSame(
            '1',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
    }

    public function testSetUserNotifyEnabledPersistsSanitizedFlag(): void
    {
        $id = $this->createMember('notify-set');
        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($id, 'yes', $this->db));
        $this->assertTrue(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertTrue(ap_forum_user_notify_enabled($id, $this->db));
        $this->assertTrue(ap_forum_set_user_notify_enabled($id, true, $this->db));
        $this->assertSame('1', AP_Forum_Notify::userNotifyStoredValue($id, $this->db));
        $this->assertSame(
            '1',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );

        $this->assertTrue(AP_Forum_Notify::setUserNotifyEnabled($id, 'off', $this->db));
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($id, $this->db));
        $this->assertSame('0', AP_Forum_Notify::userNotifyStoredValue($id, $this->db));
        $this->assertSame(
            '0',
            AP_User::getMeta($id, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
    }

    public function testInstallerAdminUserGetsNotifyMetaOff(): void
    {
        $adminId = AP_Installer::seedAdminUser($this->db, [
            'username' => 'notifyadmin',
            'email' => 'admin@example.com',
            'password' => 'securepass99',
        ]);
        $this->assertGreaterThan(0, $adminId);
        $this->assertSame(
            '0',
            AP_User::getMeta($adminId, AP_Forum_Notify::META_NOTIFY_EMAIL, $this->db)
        );
        $this->assertFalse(AP_Forum_Notify::isUserNotifyEnabled($adminId, $this->db));
    }

    private function createMember(string $login): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $login . '@example.com',
            'user_pass' => 'securepass0',
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertGreaterThan(0, (int) $created['id']);

        return (int) $created['id'];
    }

    private function insertUserRow(string $login): int
    {
        $ok = $this->db->insert('users', [
            'user_login' => $login,
            'user_pass' => AP_User::hashPassword('securepass0'),
            'user_nicename' => $login,
            'user_email' => $login . '@example.com',
            'user_url' => '',
            'user_registered' => gmdate('Y-m-d H:i:s'),
            'user_activation_key' => '',
            'user_status' => 0,
            'display_name' => $login,
        ]);
        $this->assertSame(1, $ok);
        $id = (int) $this->db->lastInsertId();
        $this->assertGreaterThan(0, $id);

        return $id;
    }
}
