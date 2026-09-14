<?php

/**
 * Tests for topic-notify site options (AP_Forum_Notify).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Forum;

use AP_DB;
use AP_Forum_Notify;
use AP_Installer;
use AP_Migrator;
use AP_Options;
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
        require_once $this->root . '/ap-includes/functions.php';

        AP_Options::flushCache();

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
    }

    public function testOptionConstantsAndDefaults(): void
    {
        $this->assertSame('forum_topic_notify_enabled', AP_Forum_Notify::OPTION_ENABLED);
        $this->assertSame('forum_notify_max_per_minute', AP_Forum_Notify::OPTION_MAX_PER_MINUTE);
        $this->assertFalse(AP_Forum_Notify::DEFAULT_ENABLED);
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
        $this->assertSame(4, AP_Forum_Notify::getMaxPerMinute($this->db));
        $this->assertSame(4, ap_forum_notify_max_per_minute($this->db));
    }

    public function testEnabledWhenOptionIsOne(): void
    {
        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '1', $this->db);
        $this->assertTrue(AP_Forum_Notify::isEnabled($this->db));
        $this->assertTrue(ap_forum_topic_notify_enabled($this->db));

        AP_Options::update(AP_Forum_Notify::OPTION_ENABLED, '0', $this->db);
        $this->assertFalse(AP_Forum_Notify::isEnabled($this->db));
        $this->assertFalse(ap_forum_topic_notify_enabled($this->db));
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
}
