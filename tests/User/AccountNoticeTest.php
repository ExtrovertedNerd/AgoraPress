<?php

/**
 * Credential-change notices: account address, time, IP, no secrets.
 *
 * An email change notifies the previous address and confirms the new one.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_Account_Notice;
use AP_Admin_User_Edit;
use AP_DB;
use AP_Mail;
use AP_Migrator;
use AP_Options;
use AP_Rate_Limit;
use AP_Registration;
use AP_Roles;
use AP_Session;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(AP_Account_Notice::class)]
#[CoversClass(AP_User::class)]
#[CoversClass(AP_Registration::class)]
final class AccountNoticeTest extends TestCase
{
    private string $root;

    private AP_DB $db;

    /**
     * Captured `ap_password_changed` / `ap_email_changed` arguments.
     *
     * @var list<array{hook: string, user_id: int, actor_id: int, context: string}>
     */
    private array $credentialHooks = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-transient.php';
        require_once $this->root . '/ap-includes/class-ap-rate-limit.php';
        require_once $this->root . '/ap-includes/class-ap-user.php';
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-nonce.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-registration.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin.php';
        require_once $this->root . '/ap-admin/includes/class-ap-admin-user-edit.php';

        $this->defineConstants();

        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Mail::resetForTests();
        AP_Rate_Limit::disable();
        AP_Session::enableTestMode();
        AP_Session::resetCurrentUser();

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        AP_Roles::ensureDefaults($this->db);

        AP_Options::update('blogname', 'Example Site', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('admin_email', 'admin@example.test', $this->db);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        unset($_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_CLIENT_IP']);

        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        AP_Roles::flushCache();
        AP_Options::flushCache();
        AP_Mail::resetForTests();
        AP_Rate_Limit::resetTestState();
        unset(
            $GLOBALS['apdb'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_FORWARDED_FOR'],
            $_SERVER['HTTP_X_REAL_IP'],
            $_SERVER['HTTP_CLIENT_IP']
        );
        if (class_exists('AP_Session', false)) {
            AP_Session::resetCurrentUser();
            AP_Session::disableTestMode();
        }
    }

    public function testProfilePasswordChangeEmailsTheAccountAddress(): void
    {
        $id = $this->createUser('noticeowner', 'owner@example.test');
        $newPassword = 'replaced-secret-99';

        $saved = $this->saveProfile($id, 'owner@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
            'display_name' => 'Owner Renamed',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertNotNull($saved['user']);
        $this->assertSame('Owner Renamed', $saved['user']->display_name);
        $this->assertTrue(AP_User::checkPassword($newPassword, $saved['user']->user_pass));

        $message = $this->passwordNotice('owner@example.test');
        $this->assertStringContainsString('Hello noticeowner,', $message['message']);
        $this->assertStringContainsString('What changed: password', $message['message']);
        $this->assertStringContainsString('IP address: 203.0.113.10', $message['message']);
        $this->assertStringContainsString(
            'If you did not make this change, reset your password and contact the site administrator.',
            $message['message']
        );
        $this->assertStringContainsString('This message does not include your password.', $message['message']);
        $this->assertStringContainsString(
            'https://example.test/ap-admin/login.php?action=lostpassword',
            $message['message']
        );
        $this->assertStringNotContainsString('key=', $message['message']);
        $this->assertMatchesRegularExpression(
            '/When: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC/',
            $message['message']
        );
        $this->assertSame(1, preg_match(
            '/When: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC/',
            $message['message'],
            $when
        ));
        $stamp = strtotime($when[1] . ' UTC');
        $this->assertNotFalse($stamp);
        $this->assertLessThanOrEqual(10, abs(time() - $stamp));
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($message));
        $this->assertStringNotContainsString('password123', $this->encodedMail($message));
        $this->assertStringNotContainsString($saved['user']->user_pass, $this->encodedMail($message));
    }

    public function testNoticeDropsInjectedHeaderBreaks(): void
    {
        AP_Options::update('blogname', "Example\r\nBcc: injected@example.test", $this->db);
        $_SERVER['REMOTE_ADDR'] = "203.0.113.10\r\nBcc: injected@example.test";

        $id = $this->createUser('injectuser', 'inject@example.test');
        $saved = AP_User::update($id, ['password' => 'inject-secret-99'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));

        $message = $this->passwordNotice('inject@example.test');
        $this->assertStringNotContainsString("\r", $message['subject']);
        $this->assertStringNotContainsString("\n", $message['subject']);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[\r\n])Bcc:/', $message['headers']);
        $this->assertStringContainsString('IP address: unknown', $message['message']);
        $this->assertStringNotContainsString('203.0.113.10', $message['message']);
        $this->assertStringNotContainsString('inject-secret-99', $this->encodedMail($message));
    }

    public function testOtherProfileFieldsDoNotEmail(): void
    {
        $id = $this->createUser('quietuser', 'quiet@example.test');
        $saved = $this->saveProfile($id, 'quiet@example.test', [
            'display_name' => 'Quiet Name',
            'description' => 'Bio only',
            'current_password' => 'not-used',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('Quiet Name', $saved['user']->display_name ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_User::checkPassword('password123', (string) $saved['user']?->user_pass));
    }

    public function testRejectedPasswordChangeDoesNotEmail(): void
    {
        $id = $this->createUser('refused', 'refused@example.test');

        $missing = $this->saveProfile($id, 'refused@example.test', [
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'display_name' => 'Still Here',
        ]);
        $this->assertFalse($missing['ok']);
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame('Still Here', $missing['user']->display_name ?? '');
        $this->assertTrue(AP_User::checkPassword('password123', (string) $missing['user']?->user_pass));

        $wrong = $this->saveProfile($id, 'refused@example.test', [
            'pass1' => 'new-password-99',
            'pass2' => 'new-password-99',
            'current_password' => 'wrong-password',
        ]);
        $this->assertFalse($wrong['ok']);
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_User::checkPassword('password123', (string) $wrong['user']?->user_pass));

        $short = $this->saveProfile($id, 'refused@example.test', [
            'pass1' => 'short',
            'pass2' => 'short',
            'current_password' => 'password123',
        ]);
        $this->assertFalse($short['ok']);
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue(AP_User::checkPassword('password123', (string) $short['user']?->user_pass));
    }

    public function testEmailChangeAloneDoesNotSendPasswordNotice(): void
    {
        $id = $this->createUser('mailonly', 'mailonly@example.test');
        $saved = $this->saveProfile($id, 'mailonly-new@example.test', [
            'current_password' => 'password123',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('mailonly-new@example.test', $saved['user']->user_email ?? '');
        $this->assertCount(2, AP_Mail::getTestOutbox());
        foreach (AP_Mail::getTestOutbox() as $message) {
            $this->assertStringNotContainsString('Your password was changed', $message['subject']);
            $this->assertStringNotContainsString('password123', $this->encodedMail($message));
        }
        $this->emailNotice('mailonly@example.test', 'Your email address was changed');
        $this->emailNotice('mailonly-new@example.test', 'Your new email address is confirmed');
    }

    public function testPasswordAndEmailChangeNotifiesThePreviousAddress(): void
    {
        $id = $this->createUser('bothchange', 'both-old@example.test');
        $newPassword = 'both-secret-99';
        $saved = $this->saveProfile($id, 'both-new@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('both-new@example.test', $saved['user']->user_email ?? '');
        $this->assertTrue(AP_User::checkPassword($newPassword, (string) $saved['user']?->user_pass));

        $this->assertCount(3, AP_Mail::getTestOutbox());
        $message = $this->passwordNotice('both-old@example.test');
        $this->assertStringNotContainsString('both-new@example.test', $message['to']);
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($message));

        $old = $this->emailNotice('both-old@example.test', 'Your email address was changed');
        $confirm = $this->emailNotice('both-new@example.test', 'Your new email address is confirmed');
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($old));
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($confirm));
        $this->assertStringNotContainsString('password123', $this->encodedMail($old));
        $this->assertStringNotContainsString('password123', $this->encodedMail($confirm));
        $this->assertStringNotContainsString((string) $saved['user']?->user_pass, $this->encodedMail($old));
        $this->assertStringNotContainsString((string) $saved['user']?->user_pass, $this->encodedMail($confirm));
    }

    public function testAdminPasswordChangeEmailsTheTargetAccount(): void
    {
        $adminId = $this->createUser('noticeadmin', 'noticeadmin@example.test', 'administrator');
        $targetId = $this->createUser('noticetarget', 'target@example.test', 'subscriber');
        $newPassword = 'admin-set-secret-99';

        $saved = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $targetId, $adminId),
            'user_ID' => $targetId,
            'user_email' => 'target@example.test',
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'display_name' => 'Target',
        ], $adminId, 'update', $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertTrue(AP_User::checkPassword($newPassword, (string) $saved['user']?->user_pass));

        $message = $this->passwordNotice('target@example.test');
        $this->assertStringNotContainsString('noticeadmin@example.test', $message['to']);
        $this->assertStringContainsString('IP address: 203.0.113.10', $message['message']);
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($message));
        $this->assertStringNotContainsString('password123', $this->encodedMail($message));
    }

    public function testMailFailureDoesNotUndoThePasswordChange(): void
    {
        $id = $this->createUser('mailfail', 'mailfail@example.test');
        AP_Mail::failNextForTests('SMTP down');

        $saved = AP_User::update($id, ['password' => 'kept-secret-99'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertTrue(AP_User::checkPassword('kept-secret-99', (string) $saved['user']?->user_pass));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame('SMTP down', AP_Mail::lastError());
    }

    public function testCreateAndRehashDoNotEmail(): void
    {
        $created = AP_User::create([
            'user_login' => 'freshaccount',
            'user_email' => 'fresh@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertSame([], AP_Mail::getTestOutbox());

        $legacy = password_hash('legacy-password-1', PASSWORD_BCRYPT);
        $this->assertIsString($legacy);
        $this->db->update('users', ['user_pass' => $legacy], ['ID' => $created['id']]);
        AP_Mail::clearTestOutbox();

        $authed = AP_User::authenticate('freshaccount', 'legacy-password-1', $this->db);
        $this->assertInstanceOf(AP_User::class, $authed);
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertTrue($authed->verifyPassword('legacy-password-1'));
    }

    public function testPasswordResetNoticeOmitsTheResetKey(): void
    {
        $this->createUser('resetnotice', 'resetnotice@example.test');
        AP_Mail::clearTestOutbox();

        $req = AP_Registration::requestPasswordReset('resetnotice@example.test', $this->db);
        $this->assertTrue($req['ok'], implode('; ', $req['errors']));
        $this->assertNotSame('', $req['plain_key']);
        AP_Mail::clearTestOutbox();

        $newPassword = 'reset-secret-99';
        $reset = AP_Registration::resetPassword(
            'resetnotice',
            $req['plain_key'],
            $newPassword,
            $this->db
        );
        $this->assertTrue($reset['ok'], implode('; ', $reset['errors']));
        $this->assertNotNull(AP_User::authenticate('resetnotice', $newPassword, $this->db));

        $message = $this->passwordNotice('resetnotice@example.test');
        $this->assertStringNotContainsString($req['plain_key'], $this->encodedMail($message));
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($message));
        $this->assertStringContainsString('What changed: password', $message['message']);
        $this->assertStringContainsString('IP address: 203.0.113.10', $message['message']);
    }

    public function testNoticeSendsWhenTheMailRateLimitIsLocked(): void
    {
        AP_Rate_Limit::enable();
        AP_Rate_Limit::setTestLimits(AP_Rate_Limit::ACTION_MAIL, [
            'max' => 1,
            'window' => 3600,
            'lockout' => 3600,
        ]);
        $GLOBALS['apdb'] = $this->db;

        $locked = AP_Rate_Limit::hit(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::ipBucket(),
            $this->db
        );
        $this->assertFalse($locked['allowed']);
        $this->assertFalse(AP_Mail::send('blocked@example.test', 'Blocked', 'nope'));
        AP_Mail::clearTestOutbox();

        $id = $this->createUser('ratemail', 'ratemail@example.test');
        $saved = AP_User::update($id, ['password' => 'rate-secret-99'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $message = $this->passwordNotice('ratemail@example.test');
        $this->assertStringNotContainsString('rate-secret-99', $this->encodedMail($message));
    }

    public function testOutboxOffDoesNotOpenTheLiveTransport(): void
    {
        AP_Mail::disableTestMode();
        $calls = 0;
        ap_add_filter('ap_mail_send', static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 10, 2);

        $id = $this->createUser('nolive', 'nolive@example.test');
        $saved = AP_User::update($id, ['password' => 'nolive-secret-99'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertTrue(AP_User::checkPassword('nolive-secret-99', (string) $saved['user']?->user_pass));
        $this->assertSame(0, $calls);
    }

    public function testNoticeApiHasNoPasswordArgument(): void
    {
        foreach (['notifyPasswordChanged', 'notifyEmailChanged'] as $name) {
            $method = new ReflectionMethod(AP_Account_Notice::class, $name);
            foreach ($method->getParameters() as $param) {
                $this->assertStringNotContainsString('pass', strtolower($param->getName()));
            }
        }

        $user = new AP_User();
        $user->user_email = 'not-an-email';
        $this->assertFalse(AP_Account_Notice::notifyPasswordChanged($user, $this->db));
        $this->assertFalse(AP_Account_Notice::notifyEmailChanged(
            $user,
            $this->db,
            'not-an-email',
            'also-not-an-email'
        ));
        $this->assertFalse(AP_Account_Notice::notifyEmailChanged($user, $this->db));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testEmailChangeNotifiesOldAddressAndConfirmsNew(): void
    {
        $id = $this->createUser('emailmove', 'email-old@example.test');
        $saved = $this->saveProfile($id, 'email-new@example.test', [
            'current_password' => 'password123',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('email-new@example.test', $saved['user']->user_email ?? '');
        $this->assertTrue(AP_User::checkPassword('password123', (string) $saved['user']?->user_pass));
        $this->assertCount(2, AP_Mail::getTestOutbox());

        $old = $this->emailNotice('email-old@example.test', 'Your email address was changed');
        $confirm = $this->emailNotice('email-new@example.test', 'Your new email address is confirmed');
        $this->assertSame(
            ['email-old@example.test', 'email-new@example.test'],
            array_column(AP_Mail::getTestOutbox(), 'to')
        );

        foreach ([$old, $confirm] as $message) {
            $this->assertStringContainsString('Hello emailmove,', $message['message']);
            $this->assertStringContainsString('What changed: email address', $message['message']);
            $this->assertStringContainsString('Previous address: email-old@example.test', $message['message']);
            $this->assertStringContainsString('New address: email-new@example.test', $message['message']);
            $this->assertStringContainsString('IP address: 203.0.113.10', $message['message']);
            $this->assertStringContainsString('This message does not include your password.', $message['message']);
            $this->assertStringNotContainsString('key=', $message['message']);
            $this->assertStringNotContainsString('action=verifyemail', $message['message']);
            $this->assertStringNotContainsString('password123', $this->encodedMail($message));
            $this->assertStringNotContainsString((string) $saved['user']?->user_pass, $this->encodedMail($message));
            $this->assertMatchesRegularExpression(
                '/When: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} UTC/',
                $message['message']
            );
        }

        $this->assertStringContainsString(
            'If you did not make this change, contact the site administrator.',
            $old['message']
        );
        $this->assertStringContainsString(
            'Password reset messages are sent to the new address.',
            $old['message']
        );
        $this->assertStringContainsString(
            'This message confirms that this address is now the email for your account on Example Site.',
            $confirm['message']
        );
        $this->assertStringContainsString('The previous address was also notified.', $confirm['message']);
        $this->assertStringContainsString(
            'If you did not make this change, do not use this account and contact the site administrator.',
            $confirm['message']
        );

        $this->assertSame(1, preg_match(
            '/When: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC/',
            $old['message'],
            $oldWhen
        ));
        $this->assertSame(1, preg_match(
            '/When: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC/',
            $confirm['message'],
            $newWhen
        ));
        $this->assertSame($oldWhen[1], $newWhen[1]);
        $stamp = strtotime($oldWhen[1] . ' UTC');
        $this->assertNotFalse($stamp);
        $this->assertLessThanOrEqual(10, abs(time() - $stamp));
    }

    public function testRejectedEmailChangeDoesNotNotify(): void
    {
        $id = $this->createUser('emailrefuse', 'emailrefuse@example.test');
        $missing = $this->saveProfile($id, 'emailrefuse-new@example.test');
        $this->assertFalse($missing['ok']);
        $this->assertSame('emailrefuse@example.test', $missing['user']->user_email ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());

        $wrong = $this->saveProfile($id, 'emailrefuse-new@example.test', [
            'current_password' => 'wrong-password',
        ]);
        $this->assertFalse($wrong['ok']);
        $this->assertSame('emailrefuse@example.test', $wrong['user']->user_email ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertStringNotContainsString('wrong-password', json_encode($wrong) ?: '');
    }

    public function testCaseOnlyEmailChangeDoesNotNotify(): void
    {
        $id = $this->createUser('emailcase', 'emailcase@example.test');
        $saved = $this->saveProfile($id, 'EmailCase@Example.test', [
            'current_password' => 'password123',
        ]);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('EmailCase@Example.test', $saved['user']->user_email ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertFalse(AP_Account_Notice::notifyEmailChanged(
            $saved['user'],
            $this->db,
            'emailcase@example.test',
            'EmailCase@Example.test'
        ));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testAdminEmailChangeNotifiesBothAddresses(): void
    {
        $adminId = $this->createUser('emailadmin', 'emailadmin@example.test', 'administrator');
        $targetId = $this->createUser('emailtarget', 'emailtarget@example.test', 'subscriber');

        $saved = AP_Admin_User_Edit::save([
            '_ap_nonce' => ap_create_nonce('update-user-' . $targetId, $adminId),
            'user_ID' => $targetId,
            'user_email' => 'emailtarget-new@example.test',
            'display_name' => 'Target',
        ], $adminId, 'update', $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('emailtarget-new@example.test', $saved['user']->user_email ?? '');
        $this->assertCount(2, AP_Mail::getTestOutbox());

        $old = $this->emailNotice('emailtarget@example.test', 'Your email address was changed');
        $confirm = $this->emailNotice('emailtarget-new@example.test', 'Your new email address is confirmed');
        $this->assertStringNotContainsString('emailadmin@example.test', $old['to']);
        $this->assertStringNotContainsString('emailadmin@example.test', $confirm['to']);
        $this->assertStringContainsString('IP address: 203.0.113.10', $old['message']);
        $this->assertStringContainsString('The previous address was also notified.', $confirm['message']);
        $this->assertStringNotContainsString('password123', $this->encodedMail($old));
        $this->assertStringNotContainsString('password123', $this->encodedMail($confirm));
    }

    public function testDirectEmailUpdateNotifiesBothAddresses(): void
    {
        $id = $this->createUser('emaildirect', 'direct-old@example.test');
        $saved = AP_User::update($id, ['email' => 'direct-new@example.test'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('direct-new@example.test', $saved['user']->user_email ?? '');
        $this->assertCount(2, AP_Mail::getTestOutbox());
        $this->emailNotice('direct-old@example.test', 'Your email address was changed');
        $this->emailNotice('direct-new@example.test', 'Your new email address is confirmed');
    }

    public function testEmailNoticeDropsInjectedHeaderBreaks(): void
    {
        AP_Options::update('blogname', "Example\r\nBcc: injected@example.test", $this->db);
        $_SERVER['REMOTE_ADDR'] = "203.0.113.10\r\nBcc: injected@example.test";

        $id = $this->createUser('emailinject', 'inject-old@example.test');
        $rejected = AP_User::update($id, [
            'email' => "inject-new@example.test\r\nBcc: injected@example.test",
        ], $this->db);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('inject-old@example.test', $rejected['user']->user_email ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());

        $saved = AP_User::update($id, ['email' => 'inject-new@example.test'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));

        $old = $this->emailNotice('inject-old@example.test', 'Your email address was changed');
        $confirm = $this->emailNotice('inject-new@example.test', 'Your new email address is confirmed');
        foreach ([$old, $confirm] as $message) {
            $this->assertStringNotContainsString("\r", $message['subject']);
            $this->assertStringNotContainsString("\n", $message['subject']);
            $this->assertDoesNotMatchRegularExpression('/(?:^|[\r\n])Bcc:/', $message['headers']);
            $this->assertStringContainsString('IP address: unknown', $message['message']);
            $this->assertStringNotContainsString('203.0.113.10', $message['message']);
        }
        $recipients = array_column(AP_Mail::getTestOutbox(), 'to');
        $this->assertSame(
            ['inject-old@example.test', 'inject-new@example.test'],
            $recipients
        );

        AP_Mail::clearTestOutbox();
        AP_Options::update('blogname', 'Example Site', $this->db);
        $poison = "other-old@example.test\r\nBcc: injected@example.test";
        $this->assertTrue(AP_Account_Notice::notifyEmailChanged(
            $saved['user'],
            $this->db,
            $poison,
            'inject-new@example.test'
        ));
        $only = AP_Mail::getTestOutbox();
        $this->assertCount(1, $only);
        $this->assertSame('inject-new@example.test', $only[0]['to']);
        $this->assertStringContainsString('Previous address: unknown', $only[0]['message']);
        $this->assertStringNotContainsString('injected@example.test', $only[0]['to']);
        $this->assertStringNotContainsString('injected@example.test', $only[0]['message']);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[\r\n])Bcc:/', $only[0]['headers']);
    }

    public function testEmailNoticeSendsWhenTheMailRateLimitIsLocked(): void
    {
        AP_Rate_Limit::enable();
        AP_Rate_Limit::setTestLimits(AP_Rate_Limit::ACTION_MAIL, [
            'max' => 1,
            'window' => 3600,
            'lockout' => 3600,
        ]);
        $GLOBALS['apdb'] = $this->db;

        $locked = AP_Rate_Limit::hit(
            AP_Rate_Limit::ACTION_MAIL,
            AP_Rate_Limit::ipBucket(),
            $this->db
        );
        $this->assertFalse($locked['allowed']);
        $this->assertFalse(AP_Mail::send('blocked-email@example.test', 'Blocked', 'nope'));
        AP_Mail::clearTestOutbox();

        $id = $this->createUser('emailrate', 'emailrate-old@example.test');
        $saved = AP_User::update($id, ['email' => 'emailrate-new@example.test'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertCount(2, AP_Mail::getTestOutbox());
        $this->emailNotice('emailrate-old@example.test', 'Your email address was changed');
        $this->emailNotice('emailrate-new@example.test', 'Your new email address is confirmed');
    }

    public function testEmailNoticeFailureDoesNotUndoTheChange(): void
    {
        $id = $this->createUser('emailfail', 'emailfail-old@example.test');
        AP_Mail::failNextForTests('SMTP down');

        $saved = AP_User::update($id, ['email' => 'emailfail-new@example.test'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('emailfail-new@example.test', $saved['user']->user_email ?? '');
        $this->assertCount(1, AP_Mail::getTestOutbox());
        $confirm = $this->emailNotice('emailfail-new@example.test', 'Your new email address is confirmed');
        $this->assertStringContainsString(
            'The previous address could not be notified.',
            $confirm['message']
        );
        $this->assertStringNotContainsString('password123', $this->encodedMail($confirm));
    }

    public function testPasswordNoticeSkippedWhenOutboundMailIsDisabled(): void
    {
        AP_Options::update('mail_transport', 'none', $this->db);
        $id = $this->createUser('maildisabled', 'disabled@example.test');
        $calls = 0;
        ap_add_filter('ap_mail_send', static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 10, 2);

        $newPassword = 'disabled-secret-99';
        [$saved, $log] = $this->captureErrorLog(fn (): array => $this->saveProfile($id, 'disabled@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
            'display_name' => 'Still Saved',
        ]));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame([], $saved['errors']);
        $this->assertNotNull($saved['user']);
        $this->assertSame('Still Saved', $saved['user']->display_name);
        $this->assertTrue(AP_User::checkPassword($newPassword, $saved['user']->user_pass));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame(0, $calls);
        $this->assertStringContainsString('Outbound mail is disabled.', $log);
        $this->assertStringContainsString('(password change notice)', $log);
        $this->assertStringNotContainsString($newPassword, $log);
        $this->assertStringNotContainsString('password123', $log);
        $this->assertStringNotContainsString('disabled@example.test', $log);
        $this->assertSame('Outbound mail is disabled.', AP_Mail::lastError());
        $this->assertSame(
            'Outbound mail is disabled.',
            (string) AP_Options::get('mail_last_error', '', $this->db)
        );
    }

    public function testEmailNoticeSkippedWhenOutboundMailIsOff(): void
    {
        AP_Options::update('mail_transport', 'off', $this->db);
        $id = $this->createUser('mailoff', 'mailoff-old@example.test');
        $calls = 0;
        ap_add_filter('ap_mail_send', static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 10, 2);

        [$saved, $log] = $this->captureErrorLog(fn (): array => $this->saveProfile(
            $id,
            'mailoff-new@example.test',
            ['current_password' => 'password123']
        ));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertNotNull($saved['user']);
        $this->assertSame('mailoff-new@example.test', $saved['user']->user_email);
        $this->assertTrue(AP_User::checkPassword('password123', $saved['user']->user_pass));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame(0, $calls);
        $this->assertSame(1, substr_count($log, 'AgoraPress mail:'));
        $this->assertStringContainsString('Outbound mail is disabled.', $log);
        $this->assertStringContainsString('(email change notice)', $log);
        $this->assertStringNotContainsString('mailoff-old@example.test', $log);
        $this->assertStringNotContainsString('mailoff-new@example.test', $log);
        $this->assertStringNotContainsString('password123', $log);
    }

    public function testCaseOnlyEmailChangeDoesNotLogWhenMailIsDisabled(): void
    {
        AP_Options::update('mail_transport', 'disabled', $this->db);
        $id = $this->createUser('mailcaseoff', 'mailcaseoff@example.test');

        [$saved, $log] = $this->captureErrorLog(fn (): array => $this->saveProfile(
            $id,
            'MailCaseOff@Example.test',
            ['current_password' => 'password123']
        ));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('MailCaseOff@Example.test', $saved['user']->user_email ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame('', trim($log));
        $this->assertSame('', AP_Mail::lastError());
    }

    public function testNoticesSkippedWhenSmtpHostIsUnconfigured(): void
    {
        AP_Options::update('mail_transport', 'smtp', $this->db);
        AP_Options::update('smtp_host', '   ', $this->db);
        $id = $this->createUser('smtpskip', 'smtp-old@example.test');
        $calls = 0;
        ap_add_filter('ap_mail_send', static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 10, 2);

        $newPassword = 'smtp-secret-99';
        [$saved, $log] = $this->captureErrorLog(fn (): array => AP_User::update($id, [
            'password' => $newPassword,
            'email' => 'smtp-new@example.test',
        ], $this->db));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertNotNull($saved['user']);
        $this->assertSame('smtp-new@example.test', $saved['user']->user_email);
        $this->assertTrue(AP_User::checkPassword($newPassword, $saved['user']->user_pass));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame(0, $calls);
        $this->assertSame(1, substr_count($log, '(password change notice)'));
        $this->assertSame(1, substr_count($log, '(email change notice)'));
        $this->assertStringContainsString('SMTP host is empty', $log);
        $this->assertStringNotContainsString($newPassword, $log);
        $this->assertStringNotContainsString('password123', $log);
        $this->assertStringNotContainsString('smtp-old@example.test', $log);
        $this->assertStringNotContainsString('smtp-new@example.test', $log);
        $this->assertSame(
            'Outbound mail is not configured (SMTP host is empty).',
            (string) AP_Options::get('mail_last_error', '', $this->db)
        );
    }

    public function testPasswordNoticeSkippedWhenPhpMailIsUnavailable(): void
    {
        AP_Mail::setPhpMailAvailableForTests(false);
        $id = $this->createUser('nomailfn', 'nomailfn@example.test');
        $newPassword = 'nomail-secret-99';

        [$saved, $log] = $this->captureErrorLog(fn (): array => $this->saveProfile($id, 'nomailfn@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
        ]));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertNotNull($saved['user']);
        $this->assertTrue(AP_User::checkPassword($newPassword, $saved['user']->user_pass));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertStringContainsString('PHP mail() is unavailable', $log);
        $this->assertStringContainsString('(password change notice)', $log);
        $this->assertStringNotContainsString($newPassword, $log);
        $this->assertStringNotContainsString('password123', $log);
        $this->assertStringNotContainsString('nomailfn@example.test', $log);
    }

    public function testConfiguredSmtpStillSendsPasswordNotice(): void
    {
        AP_Options::update('mail_transport', 'smtp', $this->db);
        AP_Options::update('smtp_host', 'smtp.example.com', $this->db);
        $id = $this->createUser('smtpok', 'smtpok@example.test');
        $newPassword = 'smtpok-secret-99';

        $saved = $this->saveProfile($id, 'smtpok@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
        ]);

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $message = $this->passwordNotice('smtpok@example.test');
        $this->assertStringContainsString('What changed: password', $message['message']);
        $this->assertStringNotContainsString($newPassword, $this->encodedMail($message));
        $this->assertSame('', AP_Mail::lastError());
    }

    public function testProfileCredentialHooksCarrySelfContext(): void
    {
        $id = $this->createUser('hookowner', 'hookowner@example.test');
        $this->listenForCredentialChanges();

        $quiet = $this->saveProfile($id, 'hookowner@example.test', [
            'display_name' => 'Hook Owner',
            'current_password' => 'not-used',
        ]);
        $this->assertTrue($quiet['ok'], implode('; ', $quiet['errors']));
        $this->assertSame('Hook Owner', $quiet['user']->display_name ?? '');
        $this->assertSame([], $this->credentialHooks);
        $this->assertSame(0, ap_did_action('ap_password_changed'));
        $this->assertSame(0, ap_did_action('ap_email_changed'));

        $newPassword = 'hook-secret-99';
        $password = $this->saveProfile($id, 'hookowner@example.test', [
            'pass1' => $newPassword,
            'pass2' => $newPassword,
            'current_password' => 'password123',
        ]);
        $this->assertTrue($password['ok'], implode('; ', $password['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($newPassword, 'password123', 'hookowner@example.test');

        $this->credentialHooks = [];
        $email = $this->saveProfile($id, 'hookowner-new@example.test', [
            'current_password' => $newPassword,
        ]);
        $this->assertTrue($email['ok'], implode('; ', $email['errors']));
        $this->assertSame('hookowner-new@example.test', $email['user']->user_email ?? '');
        $this->assertSame([
            [
                'hook' => 'ap_email_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($newPassword, 'hookowner@example.test', 'hookowner-new@example.test');

        $this->credentialHooks = [];
        $bothPassword = 'hook-both-secret';
        $both = $this->saveProfile($id, 'hookowner-both@example.test', [
            'pass1' => $bothPassword,
            'pass2' => $bothPassword,
            'current_password' => $newPassword,
        ]);
        $this->assertTrue($both['ok'], implode('; ', $both['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
            [
                'hook' => 'ap_email_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertSame(2, ap_did_action('ap_password_changed'));
        $this->assertSame(2, ap_did_action('ap_email_changed'));
        $this->assertHooksOmit($bothPassword, $newPassword, 'hookowner-both@example.test');

        $this->credentialHooks = [];
        $rejected = $this->saveProfile($id, 'hookowner-stolen@example.test', [
            'pass1' => 'other-secret-99',
            'pass2' => 'other-secret-99',
            'current_password' => 'wrong-password',
            'display_name' => 'Still Hook Owner',
        ]);
        $this->assertFalse($rejected['ok']);
        $this->assertSame('Still Hook Owner', $rejected['user']->display_name ?? '');
        $this->assertSame('hookowner-both@example.test', $rejected['user']->user_email ?? '');
        $this->assertTrue(AP_User::checkPassword($bothPassword, (string) $rejected['user']?->user_pass));
        $this->assertSame([], $this->credentialHooks);

        $this->credentialHooks = [];
        $caseOnly = $this->saveProfile($id, 'HookOwner-Both@Example.test', [
            'current_password' => $bothPassword,
        ]);
        $this->assertTrue($caseOnly['ok'], implode('; ', $caseOnly['errors']));
        $this->assertSame('HookOwner-Both@Example.test', $caseOnly['user']->user_email ?? '');
        $this->assertSame([], $this->credentialHooks);
        $this->assertSame(2, ap_did_action('ap_email_changed'));
    }

    public function testAdminCredentialHooksCarryAdminContext(): void
    {
        $adminId = $this->createUser('hookadmin', 'hookadmin@example.test', 'administrator');
        $targetId = $this->createUser('hooktarget', 'hooktarget@example.test', 'subscriber');
        $editorId = $this->createUser('hookeditor', 'hookeditor@example.test', 'editor');
        $this->listenForCredentialChanges();

        $renamed = $this->saveOtherUser($adminId, $targetId, 'hooktarget@example.test', [
            'display_name' => 'Renamed Target',
        ]);
        $this->assertTrue($renamed['ok'], implode('; ', $renamed['errors']));
        $this->assertSame([], $this->credentialHooks);

        $email = $this->saveOtherUser($adminId, $targetId, 'hooktarget-new@example.test');
        $this->assertTrue($email['ok'], implode('; ', $email['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_email_changed',
                'user_id' => $targetId,
                'actor_id' => $adminId,
                'context' => 'admin',
            ],
        ], $this->credentialHooks);

        $this->credentialHooks = [];
        $secret = 'admin-reset-secret';
        $password = $this->saveOtherUser($adminId, $targetId, 'hooktarget-new@example.test', [
            'pass1' => $secret,
            'pass2' => $secret,
            'current_password' => 'not-the-target-password',
        ]);
        $this->assertTrue($password['ok'], implode('; ', $password['errors']));
        $this->assertTrue(AP_User::checkPassword($secret, (string) $password['user']?->user_pass));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $targetId,
                'actor_id' => $adminId,
                'context' => 'admin',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($secret, 'not-the-target-password', 'password123');

        $this->credentialHooks = [];
        $bothSecret = 'admin-both-secret';
        $both = $this->saveOtherUser($adminId, $targetId, 'hooktarget-both@example.test', [
            'pass1' => $bothSecret,
            'pass2' => $bothSecret,
        ]);
        $this->assertTrue($both['ok'], implode('; ', $both['errors']));
        $this->assertSame('admin', $this->credentialHooks[0]['context'] ?? '');
        $this->assertSame('admin', $this->credentialHooks[1]['context'] ?? '');
        $this->assertSame(
            ['ap_password_changed', 'ap_email_changed'],
            array_column($this->credentialHooks, 'hook')
        );
        $this->assertSame([$adminId, $adminId], array_column($this->credentialHooks, 'actor_id'));
        $this->assertSame([$targetId, $targetId], array_column($this->credentialHooks, 'user_id'));
        $this->assertHooksOmit($bothSecret, 'hooktarget-both@example.test', 'hooktarget-new@example.test');

        $this->credentialHooks = [];
        $selfPassword = 'self-edit-secret';
        $self = $this->saveOwnUserEdit($adminId, 'hookadmin@example.test', [
            'pass1' => $selfPassword,
            'pass2' => $selfPassword,
            'current_password' => 'password123',
        ]);
        $this->assertTrue($self['ok'], implode('; ', $self['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $adminId,
                'actor_id' => $adminId,
                'context' => 'self',
            ],
        ], $this->credentialHooks);

        $before = count($this->credentialHooks);
        $forbidden = $this->saveOtherUser($editorId, $targetId, 'hooktarget-editor@example.test', [
            'pass1' => 'editor-secret-99',
            'pass2' => 'editor-secret-99',
        ]);
        $this->assertFalse($forbidden['ok']);
        $this->assertCount($before, $this->credentialHooks);
        $this->assertHooksOmit('editor-secret-99', 'hooktarget-editor@example.test');
    }

    public function testInferredActorResetAndIgnoredCredentialHooks(): void
    {
        $id = $this->createUser('hookdirect', 'hookdirect@example.test');
        $otherId = $this->createUser('hookother', 'hookother@example.test', 'administrator');
        $this->listenForCredentialChanges();

        $created = AP_User::create([
            'user_login' => 'hookfresh',
            'user_email' => 'hookfresh@example.test',
            'password' => 'password123',
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        $this->assertSame([], $this->credentialHooks);

        $legacy = password_hash('legacy-password-1', PASSWORD_BCRYPT);
        $this->assertIsString($legacy);
        $this->db->update('users', ['user_pass' => $legacy], ['ID' => $created['id']]);
        $authed = AP_User::authenticate('hookfresh', 'legacy-password-1', $this->db);
        $this->assertInstanceOf(AP_User::class, $authed);
        $this->assertSame([], $this->credentialHooks);
        $this->assertSame(0, ap_did_action('ap_password_changed'));

        $short = AP_User::update($id, ['password' => 'short'], $this->db);
        $this->assertFalse($short['ok']);
        $duplicate = AP_User::update($id, ['email' => 'hookother@example.test'], $this->db);
        $this->assertFalse($duplicate['ok']);
        $missing = AP_User::update(0, ['password' => 'missing-secret'], $this->db);
        $this->assertFalse($missing['ok']);
        $this->assertSame([], $this->credentialHooks);

        AP_User::fireCredentialChange('password', $id, $id, 'other');
        AP_User::fireCredentialChange('nope', $id, $id, 'self');
        AP_User::fireCredentialChange('email', 0, $id, 'admin');
        AP_User::fireCredentialChange('password', $id, 0, 'self');
        $this->assertSame([], $this->credentialHooks);
        $this->assertSame(0, ap_did_action('ap_password_changed'));
        $this->assertSame(0, ap_did_action('ap_email_changed'));

        $directPassword = 'direct-secret-99';
        $direct = ap_update_user($id, ['password' => $directPassword], $this->db);
        $this->assertTrue($direct['ok'], implode('; ', $direct['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($directPassword);

        $this->assertTrue(AP_Session::setAuthCookie($otherId, false, $this->db));
        $this->credentialHooks = [];
        $seenPassword = 'seen-secret-99';
        $seen = AP_User::update($id, ['email' => 'hookdirect-new@example.test'], $this->db);
        $this->assertTrue($seen['ok'], implode('; ', $seen['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_email_changed',
                'user_id' => $id,
                'actor_id' => $otherId,
                'context' => 'admin',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($seenPassword, 'hookdirect-new@example.test', 'hookdirect@example.test');

        AP_Session::resetCurrentUser();
        $this->credentialHooks = [];
        $req = AP_Registration::requestPasswordReset('hookdirect-new@example.test', $this->db);
        $this->assertTrue($req['ok'], implode('; ', $req['errors']));
        $this->assertSame([], $this->credentialHooks);
        $this->assertTrue(AP_Session::setAuthCookie($otherId, false, $this->db));
        $resetPassword = 'reset-hook-secret';
        $reset = AP_Registration::resetPassword(
            'hookdirect',
            $req['plain_key'],
            $resetPassword,
            $this->db
        );
        $this->assertTrue($reset['ok'], implode('; ', $reset['errors']));
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($resetPassword, $req['plain_key']);
        $this->assertNotSame($otherId, $this->credentialHooks[0]['actor_id'] ?? 0);
    }

    public function testCredentialHooksFireWhenMailIsOff(): void
    {
        AP_Options::update('mail_transport', 'none', $this->db);
        $id = $this->createUser('hooknomail', 'hooknomail@example.test');
        $this->listenForCredentialChanges();

        $newPassword = 'nomail-hook-secret';
        [$saved, $log] = $this->captureErrorLog(fn (): array => AP_User::update($id, [
            'password' => $newPassword,
            'email' => 'hooknomail-new@example.test',
        ], $this->db));

        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertStringContainsString('(password change notice)', $log);
        $this->assertStringContainsString('(email change notice)', $log);
        $this->assertSame([
            [
                'hook' => 'ap_password_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
            [
                'hook' => 'ap_email_changed',
                'user_id' => $id,
                'actor_id' => $id,
                'context' => 'self',
            ],
        ], $this->credentialHooks);
        $this->assertHooksOmit($newPassword, 'hooknomail@example.test', 'hooknomail-new@example.test');
        $this->assertStringNotContainsString($newPassword, $log);
    }

    public function testEmailChangeWithOutboxOffDoesNotOpenTheLiveTransport(): void
    {
        AP_Mail::disableTestMode();
        $calls = 0;
        ap_add_filter('ap_mail_send', static function () use (&$calls): bool {
            $calls++;

            return true;
        }, 10, 2);

        $id = $this->createUser('emailnolive', 'emailnolive@example.test');
        $saved = AP_User::update($id, ['email' => 'emailnolive-new@example.test'], $this->db);
        $this->assertTrue($saved['ok'], implode('; ', $saved['errors']));
        $this->assertSame('emailnolive-new@example.test', $saved['user']->user_email ?? '');
        $this->assertSame(0, $calls);
    }

    private function defineConstants(): void
    {
        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-' . str_repeat('d', 32));
        }
        if (!defined('AP_NONCE_KEY')) {
            define('AP_NONCE_KEY', 'test-nonce-key-' . str_repeat('n', 32));
        }
        if (!defined('AP_NONCE_SALT')) {
            define('AP_NONCE_SALT', 'test-nonce-salt-' . str_repeat('s', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }
    }

    private function createUser(string $login, string $email, string $role = 'author'): int
    {
        $created = AP_User::create([
            'user_login' => $login,
            'user_email' => $email,
            'password' => 'password123',
            'role' => $role,
            'display_name' => $login,
        ], $this->db);
        $this->assertTrue($created['ok'], implode('; ', $created['errors']));
        AP_Mail::clearTestOutbox();

        return (int) $created['id'];
    }

    private function listenForCredentialChanges(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        $this->credentialHooks = [];
        foreach (['ap_password_changed', 'ap_email_changed'] as $hook) {
            ap_add_action(
                $hook,
                function (int $userId, int $actorId, string $context) use ($hook): void {
                    $this->credentialHooks[] = [
                        'hook' => $hook,
                        'user_id' => $userId,
                        'actor_id' => $actorId,
                        'context' => $context,
                    ];
                },
                10,
                3
            );
        }
    }

    private function assertHooksOmit(string ...$secrets): void
    {
        $encoded = json_encode($this->credentialHooks);
        $this->assertIsString($encoded);
        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, user: ?AP_User}
     */
    private function saveOtherUser(int $actorId, int $targetId, string $email, array $fields = []): array
    {
        return AP_Admin_User_Edit::save(array_merge([
            '_ap_nonce' => ap_create_nonce('update-user-' . $targetId, $actorId),
            'user_ID' => $targetId,
            'user_email' => $email,
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Kept',
        ], $fields), $actorId, 'update', $this->db);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, user: ?AP_User}
     */
    private function saveOwnUserEdit(int $id, string $email, array $fields = []): array
    {
        return $this->saveOtherUser($id, $id, $email, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{ok: bool, id: int, message_key: string, errors: list<string>, user: ?AP_User}
     */
    private function saveProfile(int $id, string $email, array $fields = []): array
    {
        return AP_Admin_User_Edit::save(array_merge([
            '_ap_nonce' => ap_create_nonce('update-profile-' . $id, $id),
            'user_ID' => $id,
            'user_email' => $email,
            'pass1' => '',
            'pass2' => '',
            'display_name' => 'Kept',
        ], $fields), $id, 'profile', $this->db);
    }

    /**
     * @return array{to: string, subject: string, message: string, headers: string}
     */
    private function passwordNotice(string $to): array
    {
        $matches = [];
        foreach (AP_Mail::getTestOutbox() as $message) {
            if ($message['to'] === $to && str_contains($message['subject'], 'Your password was changed')) {
                $matches[] = $message;
            }
        }
        $this->assertCount(1, $matches, 'Expected one password-change notice to ' . $to);

        return $matches[0];
    }

    /**
     * @return array{to: string, subject: string, message: string, headers: string}
     */
    private function emailNotice(string $to, string $subjectPart): array
    {
        $matches = [];
        foreach (AP_Mail::getTestOutbox() as $message) {
            if ($message['to'] === $to && str_contains($message['subject'], $subjectPart)) {
                $matches[] = $message;
            }
        }
        $this->assertCount(1, $matches, 'Expected one "' . $subjectPart . '" notice to ' . $to);

        return $matches[0];
    }

    /**
     * Run `$callback` while PHP `error_log` writes to a temp file.
     *
     * @template T
     * @param callable(): T $callback
     * @return array{0: T, 1: string}
     */
    private function captureErrorLog(callable $callback): array
    {
        $logFile = tempnam(sys_get_temp_dir(), 'ap-mail-skip-');
        $this->assertIsString($logFile);
        $previous = ini_set('error_log', $logFile);
        $logged = '';
        try {
            $result = $callback();
        } finally {
            if ($previous !== false) {
                ini_set('error_log', (string) $previous);
            }
            if (is_file($logFile)) {
                $logged = (string) file_get_contents($logFile);
                unlink($logFile);
            }
        }

        return [$result, $logged];
    }

    /**
     * @param array{to: string, subject: string, message: string, headers: string} $message
     */
    private function encodedMail(array $message): string
    {
        $encoded = json_encode($message);
        $this->assertIsString($encoded);

        return $encoded;
    }
}
