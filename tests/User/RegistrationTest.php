<?php

/**
 * Tests for registration, email verification, and password reset.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\User;

use AP_DB;
use AP_Mail;
use AP_Migrator;
use AP_Options;
use AP_Registration;
use AP_Roles;
use AP_User;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Registration::class)]
#[CoversClass(AP_Mail::class)]
final class RegistrationTest extends TestCase
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
        require_once $this->root . '/ap-includes/class-ap-session.php';
        require_once $this->root . '/ap-includes/class-ap-roles.php';
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-registration.php';
        require_once $this->root . '/ap-includes/functions.php';

        if (!defined('AP_AUTH_KEY')) {
            define('AP_AUTH_KEY', 'test-auth-key-' . str_repeat('c', 32));
        }
        if (!defined('AP_AUTH_SALT')) {
            define('AP_AUTH_SALT', 'test-auth-salt-' . str_repeat('d', 32));
        }
        if (!defined('AP_LOGGED_IN_KEY')) {
            define('AP_LOGGED_IN_KEY', 'test-logged-in-key-' . str_repeat('a', 32));
        }
        if (!defined('AP_LOGGED_IN_SALT')) {
            define('AP_LOGGED_IN_SALT', 'test-logged-in-salt-' . str_repeat('b', 32));
        }
        if (!defined('AP_SITEURL')) {
            define('AP_SITEURL', 'https://example.test');
        }

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');

        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();

        AP_Options::flushCache();
        AP_Roles::flushCache();
        AP_Roles::ensureDefaults($this->db);

        $this->setOption('users_can_register', '1');
        $this->setOption('require_email_verification', '1');
        $this->setOption('default_role', 'subscriber');
        $this->setOption('blogname', 'Test Site');
        $this->setOption('admin_email', 'admin@example.test');
        $this->setOption('siteurl', 'https://example.test');
        $this->setOption('home', 'https://example.test');

        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();
    }

    protected function tearDown(): void
    {
        AP_Mail::resetForTests();
        AP_Options::flushCache();
    }

    private function setOption(string $name, string $value): void
    {
        AP_Options::update($name, $value, $this->db);
    }

    /**
     * Public register() always requires a GET-issued ticket and empty honeypot.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withFormGate(array $data, ?int $issuedAt = null): array
    {
        $ticket = AP_Registration::createFormTicket(
            $issuedAt ?? (time() - AP_Registration::MIN_FILL_SECONDS)
        );
        if (!array_key_exists('ap_form_ticket', $data)) {
            $data['ap_form_ticket'] = $ticket['token'];
        }
        if (!array_key_exists('ap_hp', $data)) {
            $data['ap_hp'] = '';
        }

        return $data;
    }

    private function assertMailLinkNotice(mixed $message): void
    {
        $body = (string) $message;
        $this->assertStringContainsString('This link expires in 24 hours.', $body);
        $this->assertStringContainsString('spam folder', $body);
        $this->assertStringContainsString('sending server may be new', $body);
    }

    public function testUsersCanRegisterAndRequireVerificationFlags(): void
    {
        $this->assertTrue(AP_Registration::usersCanRegister($this->db));
        $this->assertTrue(AP_Registration::requireEmailVerification($this->db));
        $this->assertTrue(ap_users_can_register($this->db));
        $this->assertTrue(ap_require_email_verification($this->db));

        $this->setOption('users_can_register', '0');
        $this->assertFalse(AP_Registration::usersCanRegister($this->db));

        $this->setOption('require_email_verification', '0');
        $this->assertFalse(AP_Registration::requireEmailVerification($this->db));
    }

    public function testCaptchaDisabledByDefault(): void
    {
        $this->assertSame(AP_Registration::CAPTCHA_OFF, AP_Registration::captchaMode($this->db));
        $this->assertFalse(AP_Registration::isCaptchaEnabled($this->db));
        $this->assertFalse(ap_registration_captcha_enabled($this->db));
        $this->assertSame('off', ap_registration_captcha_mode($this->db));

        $challenge = AP_Registration::createCaptchaChallenge($this->db);
        $this->assertSame('off', $challenge['mode'] ?? null);

        // Registration succeeds without captcha fields when mode is off.
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'nocapuser',
            'user_email' => 'nocap@example.test',
            'user_pass' => 'securepass0',
        ]), $this->db);
        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
    }

    public function testMathCaptchaRequiredWhenEnabled(): void
    {
        $this->setOption('registration_captcha', 'math');
        $this->assertTrue(AP_Registration::isCaptchaEnabled($this->db));
        $this->assertSame(AP_Registration::CAPTCHA_MATH, AP_Registration::captchaMode($this->db));

        // Missing answer → reject.
        $missing = AP_Registration::register($this->withFormGate([
            'user_login' => 'capmiss',
            'user_email' => 'capmiss@example.test',
            'user_pass' => 'securepass1',
        ]), $this->db);
        $this->assertFalse($missing['ok']);
        $this->assertNotEmpty($missing['errors']);
        $this->assertNull(AP_User::getByLogin('capmiss', $this->db));

        $challenge = AP_Registration::createMathChallenge();
        $this->assertSame('math', $challenge['mode']);
        $this->assertNotSame('', $challenge['token']);
        $answer = (string) ($challenge['a'] + $challenge['b']);

        // Wrong answer → reject.
        $wrong = AP_Registration::register($this->withFormGate([
            'user_login' => 'capwrong',
            'user_email' => 'capwrong@example.test',
            'user_pass' => 'securepass1',
            'captcha_token' => $challenge['token'],
            'captcha_answer' => (string) ((int) $answer + 1),
            'ap_hp' => '',
        ]), $this->db);
        $this->assertFalse($wrong['ok']);
        $this->assertNull(AP_User::getByLogin('capwrong', $this->db));

        // Honeypot filled → reject (generic message).
        $challenge2 = AP_Registration::createMathChallenge();
        $answer2 = (string) ($challenge2['a'] + $challenge2['b']);
        $hp = AP_Registration::register($this->withFormGate([
            'user_login' => 'caphp',
            'user_email' => 'caphp@example.test',
            'user_pass' => 'securepass1',
            'captcha_token' => $challenge2['token'],
            'captcha_answer' => $answer2,
            'ap_hp' => 'http://spam.example',
        ]), $this->db);
        $this->assertFalse($hp['ok']);
        $this->assertNull(AP_User::getByLogin('caphp', $this->db));

        // Correct answer + empty honeypot → ok.
        $challenge3 = AP_Registration::createMathChallenge();
        $answer3 = (string) ($challenge3['a'] + $challenge3['b']);
        $ok = AP_Registration::register($this->withFormGate([
            'user_login' => 'capok',
            'user_email' => 'capok@example.test',
            'user_pass' => 'securepass1',
            'captcha_token' => $challenge3['token'],
            'captcha_answer' => $answer3,
            'ap_hp' => '',
        ]), $this->db);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertNotNull(AP_User::getByLogin('capok', $this->db));
    }

    public function testVerifyCaptchaHelpers(): void
    {
        $this->setOption('registration_captcha', 'off');
        $pass = ap_registration_verify_captcha([], $this->db);
        $this->assertTrue($pass['ok']);

        $this->setOption('registration_captcha', 'math');
        $challenge = ap_registration_create_captcha($this->db);
        $this->assertSame('math', $challenge['mode'] ?? '');
        $fail = ap_registration_verify_captcha([
            'captcha_token' => (string) ($challenge['token'] ?? ''),
            'captcha_answer' => '999',
        ], $this->db);
        $this->assertFalse($fail['ok']);

        $sum = (int) ($challenge['a'] ?? 0) + (int) ($challenge['b'] ?? 0);
        $ok = ap_registration_verify_captcha([
            'captcha_token' => (string) ($challenge['token'] ?? ''),
            'captcha_answer' => (string) $sum,
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
    }

    public function testPublicRegisterFormGateAlwaysOnWhenCaptchaOff(): void
    {
        $this->setOption('registration_captcha', 'off');
        $this->assertFalse(AP_Registration::isCaptchaEnabled($this->db));

        $generic = 'Could not complete registration. Please try again.';
        $base = [
            'user_login' => 'gateuser',
            'user_email' => 'gateuser@example.test',
            'user_pass' => 'securepass0',
        ];

        $naked = AP_Registration::register($base, $this->db);
        $this->assertFalse($naked['ok']);
        $this->assertSame([$generic], $naked['errors']);
        $this->assertNull(AP_User::getByLogin('gateuser', $this->db));

        $invalid = AP_Registration::register($base + [
            'ap_form_ticket' => 'not-a-ticket',
            'ap_hp' => '',
        ], $this->db);
        $this->assertFalse($invalid['ok']);
        $this->assertSame([$generic], $invalid['errors']);

        $fresh = AP_Registration::createFormTicket(time());
        $tooFast = AP_Registration::register($base + [
            'ap_form_ticket' => $fresh['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertFalse($tooFast['ok']);
        $this->assertSame([$generic], $tooFast['errors']);
        $this->assertNull(AP_User::getByLogin('gateuser', $this->db));

        $expired = AP_Registration::createFormTicket(
            time() - AP_Registration::FORM_TICKET_TTL - 5
        );
        $stale = AP_Registration::register($base + [
            'ap_form_ticket' => $expired['token'],
            'ap_hp' => '',
        ], $this->db);
        $this->assertFalse($stale['ok']);
        $this->assertSame([$generic], $stale['errors']);

        $hp = AP_Registration::register($this->withFormGate(array_merge($base, [
            'user_login' => 'gatehp',
            'user_email' => 'gatehp@example.test',
            'ap_hp' => 'http://spam.example',
        ])), $this->db);
        $this->assertFalse($hp['ok']);
        $this->assertSame([$generic], $hp['errors']);
        $this->assertNull(AP_User::getByLogin('gatehp', $this->db));

        $ok = AP_Registration::register($this->withFormGate([
            'user_login' => 'gateok',
            'user_email' => 'gateok@example.test',
            'user_pass' => 'securepass0',
        ]), $this->db);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertNotNull(AP_User::getByLogin('gateok', $this->db));

        $ticket = ap_registration_create_form_ticket(time() - AP_Registration::MIN_FILL_SECONDS);
        $this->assertNotSame('', $ticket['token'] ?? '');
        $this->assertSame(AP_Registration::FIELD_FORM_TICKET, $ticket['field'] ?? null);
        $this->assertSame(AP_Registration::MIN_FILL_SECONDS, $ticket['min_fill'] ?? null);
        $this->assertSame(AP_Registration::FORM_TICKET_TTL, $ticket['ttl'] ?? null);
        $gate = ap_registration_verify_form_gate([
            'ap_form_ticket' => (string) ($ticket['token'] ?? ''),
            'ap_hp' => '',
        ], $this->db);
        $this->assertTrue($gate['ok'], implode('; ', $gate['errors']));

        $tampered = (string) $ticket['token'];
        $flipAt = max(0, strlen($tampered) - 2);
        $tampered[$flipAt] = $tampered[$flipAt] === 'A' ? 'B' : 'A';
        $badHmac = AP_Registration::register([
            'user_login' => 'gatetamper',
            'user_email' => 'gatetamper@example.test',
            'user_pass' => 'securepass0',
            'ap_form_ticket' => $tampered,
            'ap_hp' => '',
        ], $this->db);
        $this->assertFalse($badHmac['ok']);
        $this->assertSame([$generic], $badHmac['errors']);
        $this->assertNull(AP_User::getByLogin('gatetamper', $this->db));

        $websiteHp = AP_Registration::register($this->withFormGate([
            'user_login' => 'gateweb',
            'user_email' => 'gateweb@example.test',
            'user_pass' => 'securepass0',
            'website' => 'http://spam.example',
        ]), $this->db);
        $this->assertFalse($websiteHp['ok']);
        $this->assertSame([$generic], $websiteHp['errors']);
        $this->assertNull(AP_User::getByLogin('gateweb', $this->db));

        $fresh = AP_Registration::createFormTicket(time() - 1);
        $reused = AP_Registration::formTicketForDisplay((string) $fresh['token']);
        $this->assertSame($fresh['token'], $reused['token']);
        $this->assertSame($fresh['issued_at'], $reused['issued_at']);
        $replaced = AP_Registration::formTicketForDisplay('not-a-ticket');
        $this->assertNotSame('', $replaced['token']);
        $this->assertNotSame('not-a-ticket', $replaced['token']);
        $viaHelper = ap_registration_form_ticket_for_display((string) $fresh['token']);
        $this->assertSame($fresh['token'], $viaHelper['token'] ?? null);
    }

    public function testRegisterClosedWhenOptionOff(): void
    {
        $this->setOption('users_can_register', '0');
        $result = AP_Registration::register([
            'user_login' => 'newbie',
            'user_email' => 'newbie@example.test',
            'user_pass' => 'password99',
        ], $this->db);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('closed', $result['errors'][0] ?? '');
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testRegisterWithEmailVerification(): void
    {
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'alice',
            'user_email' => 'alice@example.test',
            'user_pass' => 'securepass1',
            'role' => 'administrator', // must be ignored for public reg
        ]), $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue($result['needs_verification']);
        $this->assertTrue($result['mail_sent']);
        $this->assertNotSame('', $result['plain_key']);
        $this->assertNotNull($result['user']);
        $this->assertSame(AP_Registration::STATUS_PENDING, $result['user']->user_status);
        $this->assertStringStartsWith('activate:', $result['user']->user_activation_key);

        // Public registration ignores client role → default subscriber.
        $role = AP_Roles::getUserRole($result['id'], $this->db);
        $this->assertSame('subscriber', $role);

        // Cannot log in until verified.
        $this->assertNull(AP_User::authenticate('alice', 'securepass1', $this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('alice@example.test', $outbox[0]['to']);
        $this->assertStringContainsString('Confirm your email', $outbox[0]['subject']);
        $this->assertStringContainsString('action=verifyemail', $outbox[0]['message']);
        $this->assertStringContainsString($result['plain_key'], $outbox[0]['message']);
        $this->assertMailLinkNotice($outbox[0]['message']);
    }

    public function testVerifyEmailActivatesAccount(): void
    {
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'bob',
            'user_email' => 'bob@example.test',
            'user_pass' => 'securepass2',
        ]), $this->db);
        $this->assertTrue($result['ok']);
        $key = $result['plain_key'];

        $bad = AP_Registration::verifyEmail('bob', 'wrong-key', $this->db);
        $this->assertFalse($bad['ok']);

        $ok = AP_Registration::verifyEmail('bob', $key, $this->db);
        $this->assertTrue($ok['ok'], implode('; ', $ok['errors']));
        $this->assertNotNull($ok['user']);
        $this->assertSame(0, $ok['user']->user_status);
        $this->assertSame('', $ok['user']->user_activation_key);

        $auth = AP_User::authenticate('bob', 'securepass2', $this->db);
        $this->assertNotNull($auth);
        $this->assertSame('bob', $auth->user_login);

        // Idempotent second verify.
        $again = AP_Registration::verifyEmail('bob', $key, $this->db);
        // Key was cleared — second verify with old key should fail unless already active empty key path.
        // After clear, status=0 and empty key → success without needing key.
        $this->assertTrue($again['ok']);
    }

    public function testRegisterWithoutVerificationIsActiveImmediately(): void
    {
        $this->setOption('require_email_verification', '0');
        AP_Mail::clearTestOutbox();

        $result = ap_register_user($this->withFormGate([
            'user_login' => 'carol',
            'user_email' => 'carol@example.test',
            'user_pass' => 'securepass3',
        ]), $this->db);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['needs_verification']);
        $this->assertFalse($result['mail_sent']);
        $this->assertSame(0, $result['user']->user_status);
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertNotNull(AP_User::authenticate('carol', 'securepass3', $this->db));
    }

    public function testRegisterKeepsPendingUserWhenVerificationMailFails(): void
    {
        AP_Mail::failNextForTests('SMTP down');
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'mailfail',
            'user_email' => 'mailfail@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);

        $this->assertTrue($result['ok'], implode('; ', $result['errors']));
        $this->assertTrue($result['needs_verification']);
        $this->assertFalse($result['mail_sent']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('could not be sent', strtolower($result['errors'][0] ?? ''));
        $this->assertStringContainsString('created', strtolower($result['errors'][0] ?? ''));
        $this->assertStringNotContainsString(
            'check your email',
            strtolower(implode(' ', $result['errors']))
        );
        $this->assertSame('SMTP down', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());

        $user = AP_User::getByLogin('mailfail', $this->db);
        $this->assertNotNull($user);
        $this->assertSame(AP_Registration::STATUS_PENDING, $user->user_status);
        $this->assertTrue(AP_Registration::userAwaitsVerification($user));
        $this->assertNull(AP_User::authenticate('mailfail', 'securepass8', $this->db));
        // Cooldown meta is recorded only after a successful send, so resend is allowed.
        $this->assertNull(AP_User::getMeta($user->ID, 'ap_verification_sent', $this->db));
    }

    public function testResendVerificationSendsAfterFailedRegister(): void
    {
        AP_Mail::failNextForTests('SMTP down');
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'resendme',
            'user_email' => 'resendme@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['mail_sent']);
        AP_Mail::clearTestOutbox();

        $resend = AP_Registration::resendVerification('resendme@example.test', $this->db);
        $this->assertTrue($resend['ok'], implode('; ', $resend['errors']));
        $this->assertTrue($resend['sent']);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('resendme@example.test', $outbox[0]['to']);
        $this->assertStringContainsString('Confirm your email', $outbox[0]['subject']);
        $this->assertStringContainsString($resend['plain_key'], $outbox[0]['message']);
        $this->assertMailLinkNotice($outbox[0]['message']);
    }

    public function testResendVerificationReportsSendFailure(): void
    {
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'resendfail',
            'user_email' => 'resendfail@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);
        $this->assertTrue($result['ok']);
        AP_User::deleteMeta($result['id'], 'ap_verification_sent', $this->db);
        AP_Mail::clearTestOutbox();
        AP_Mail::failNextForTests('still down');

        $resend = AP_Registration::resendVerification('resendfail', $this->db);
        $this->assertFalse($resend['ok']);
        $this->assertFalse($resend['sent']);
        $this->assertStringContainsString('could not be sent', strtolower($resend['errors'][0] ?? ''));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $user = AP_User::getByLogin('resendfail', $this->db);
        $this->assertNotNull($user);
        $this->assertSame(AP_Registration::STATUS_PENDING, $user->user_status);
    }

    public function testUserAwaitsVerificationRequiresPendingActivateKey(): void
    {
        $pending = AP_Registration::register($this->withFormGate([
            'user_login' => 'awaiter',
            'user_email' => 'awaiter@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);
        $this->assertTrue($pending['ok']);
        $this->assertTrue(AP_Registration::userAwaitsVerification($pending['user']));

        $this->assertTrue(
            AP_Registration::verifyEmail('awaiter', $pending['plain_key'], $this->db)['ok']
        );
        $active = AP_User::getByLogin('awaiter', $this->db);
        $this->assertNotNull($active);
        $this->assertFalse(AP_Registration::userAwaitsVerification($active));
        $this->assertFalse(AP_Registration::userAwaitsVerification(null));

        $banned = AP_User::create([
            'user_login' => 'bannedstatus',
            'user_email' => 'bannedstatus@example.test',
            'user_pass' => 'securepass8',
            'user_status' => AP_Registration::STATUS_PENDING,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($banned['ok']);
        $this->assertFalse(AP_Registration::userAwaitsVerification($banned['user']));
    }

    public function testResendVerificationDoesNotLeakUnknownAccounts(): void
    {
        AP_Mail::clearTestOutbox();
        $resend = AP_Registration::resendVerification('nobody@example.test', $this->db);
        $this->assertTrue($resend['ok']);
        $this->assertFalse($resend['sent']);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testResendVerificationSkipsActiveAccounts(): void
    {
        AP_User::create([
            'user_login' => 'alreadyon',
            'user_email' => 'alreadyon@example.test',
            'user_pass' => 'securepass8',
            'user_status' => 0,
            'role' => 'subscriber',
        ], $this->db);
        AP_Mail::clearTestOutbox();

        $resend = AP_Registration::resendVerification('alreadyon', $this->db);
        $this->assertTrue($resend['ok']);
        $this->assertFalse($resend['sent']);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testPasswordResetFlow(): void
    {
        $created = AP_User::create([
            'user_login' => 'dave',
            'user_email' => 'dave@example.test',
            'user_pass' => 'oldpassword1',
            'user_status' => 0,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok']);

        AP_Mail::clearTestOutbox();
        $req = AP_Registration::requestPasswordReset('dave@example.test', $this->db);
        $this->assertTrue($req['ok']);
        $this->assertTrue($req['sent']);
        $this->assertNotSame('', $req['plain_key']);
        $this->assertSame([], $req['errors']);

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertStringContainsString('Password reset', $outbox[0]['subject']);
        $this->assertStringContainsString('action=rp', $outbox[0]['message']);
        $this->assertStringContainsString($req['plain_key'], $outbox[0]['message']);
        $this->assertMailLinkNotice($outbox[0]['message']);

        $user = AP_Registration::checkPasswordResetKey('dave', $req['plain_key'], $this->db);
        $this->assertNotNull($user);

        $this->assertNull(
            AP_Registration::checkPasswordResetKey('dave', 'bad-key', $this->db)
        );

        $reset = AP_Registration::resetPassword('dave', $req['plain_key'], 'newpassword9', $this->db);
        $this->assertTrue($reset['ok'], implode('; ', $reset['errors']));

        $this->assertNull(AP_User::authenticate('dave', 'oldpassword1', $this->db));
        $this->assertNotNull(AP_User::authenticate('dave', 'newpassword9', $this->db));

        // Key is single-use.
        $reuse = AP_Registration::resetPassword('dave', $req['plain_key'], 'anotherpass9', $this->db);
        $this->assertFalse($reuse['ok']);
    }

    public function testVerificationAndResetMailBodiesExplainExpiryAndSpamFolder(): void
    {
        $created = AP_User::create([
            'user_login' => 'mailbody',
            'user_email' => 'mailbody@example.test',
            'user_pass' => 'securepass9',
            'user_status' => 0,
            'role' => 'subscriber',
        ], $this->db);
        $this->assertTrue($created['ok']);
        $user = $created['user'];
        $this->assertNotNull($user);

        AP_Mail::clearTestOutbox();
        $this->assertTrue(AP_Registration::sendVerificationEmail($user, 'verify-plain-key', $this->db));
        $this->assertTrue(AP_Registration::sendPasswordResetEmail($user, 'reset-plain-key', $this->db));

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(2, $outbox);
        $this->assertMailLinkNotice($outbox[0]['message']);
        $this->assertMailLinkNotice($outbox[1]['message']);
        $this->assertStringContainsString('verify-plain-key', $outbox[0]['message']);
        $this->assertStringContainsString('reset-plain-key', $outbox[1]['message']);
    }

    public function testPasswordResetDoesNotClaimSuccessWhenMailFails(): void
    {
        AP_User::create([
            'user_login' => 'resetfail',
            'user_email' => 'resetfail@example.test',
            'user_pass' => 'oldpassword1',
            'user_status' => 0,
            'role' => 'subscriber',
        ], $this->db);

        AP_Mail::clearTestOutbox();
        AP_Mail::failNextForTests('SMTP down');
        $req = AP_Registration::requestPasswordReset('resetfail@example.test', $this->db);
        $this->assertFalse($req['ok']);
        $this->assertFalse($req['sent']);
        $this->assertNotEmpty($req['errors']);
        $this->assertStringContainsString('could not be sent', strtolower($req['errors'][0] ?? ''));
        $this->assertStringNotContainsString('check your email', strtolower(implode(' ', $req['errors'])));
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame('SMTP down', AP_Mail::lastError());
        $kept = AP_User::getByLogin('resetfail', $this->db);
        $this->assertNotNull($kept);
        $this->assertNull(AP_User::getMeta($kept->ID, 'ap_password_reset_sent', $this->db));

        // Immediate retry is allowed (cooldown is recorded only after a successful send).
        $retry = AP_Registration::requestPasswordReset('resetfail@example.test', $this->db);
        $this->assertTrue($retry['ok'], implode('; ', $retry['errors']));
        $this->assertTrue($retry['sent']);
        $this->assertCount(1, AP_Mail::getTestOutbox());
    }

    public function testPasswordResetDoesNotLeakUnknownAccounts(): void
    {
        AP_Mail::clearTestOutbox();
        $req = AP_Registration::requestPasswordReset('nobody@example.test', $this->db);
        $this->assertTrue($req['ok']);
        $this->assertFalse($req['sent']);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testPasswordResetSkipsPendingAccounts(): void
    {
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'erin',
            'user_email' => 'erin@example.test',
            'user_pass' => 'securepass4',
        ]), $this->db);
        $this->assertTrue($result['ok']);
        AP_Mail::clearTestOutbox();

        $req = AP_Registration::requestPasswordReset('erin', $this->db);
        $this->assertTrue($req['ok']);
        $this->assertFalse($req['sent']);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testPasswordResetCooldown(): void
    {
        AP_User::create([
            'user_login' => 'frank',
            'user_email' => 'frank@example.test',
            'user_pass' => 'securepass5',
            'role' => 'subscriber',
        ], $this->db);

        $first = AP_Registration::requestPasswordReset('frank', $this->db);
        $this->assertTrue($first['sent']);
        AP_Mail::clearTestOutbox();

        $second = AP_Registration::requestPasswordReset('frank', $this->db);
        $this->assertTrue($second['ok']);
        $this->assertFalse($second['sent']);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testProceduralHelpers(): void
    {
        $this->assertTrue(function_exists('ap_register_user'));
        $this->assertTrue(function_exists('ap_verify_user_email'));
        $this->assertTrue(function_exists('ap_request_password_reset'));
        $this->assertTrue(function_exists('ap_check_password_reset_key'));
        $this->assertTrue(function_exists('ap_reset_password'));
        $this->assertTrue(function_exists('ap_registration_captcha_mode'));
        $this->assertTrue(function_exists('ap_registration_captcha_enabled'));
        $this->assertTrue(function_exists('ap_registration_create_captcha'));
        $this->assertTrue(function_exists('ap_registration_verify_captcha'));
        $this->assertTrue(function_exists('ap_registration_create_form_ticket'));
        $this->assertTrue(function_exists('ap_registration_form_ticket_for_display'));
        $this->assertTrue(function_exists('ap_registration_verify_form_gate'));
        $this->assertTrue(function_exists('ap_mail'));
        $this->assertTrue(function_exists('ap_resend_user_verification'));

        $this->assertTrue(ap_mail('x@example.test', 'Subject', 'Body'));
        $outbox = AP_Mail::getTestOutbox();
        $this->assertNotEmpty($outbox);
        $this->assertSame('x@example.test', $outbox[array_key_last($outbox)]['to']);
    }

    public function testMailRejectsInvalidRecipient(): void
    {
        AP_Mail::clearTestOutbox();
        $this->assertFalse(AP_Mail::send('not-an-email', 'Hi', 'Body'));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testKeyExpiry(): void
    {
        $created = AP_User::create([
            'user_login' => 'gina',
            'user_email' => 'gina@example.test',
            'user_pass' => 'securepass6',
            'role' => 'subscriber',
        ], $this->db);
        $user = $created['user'];
        $this->assertNotNull($user);

        $plain = AP_Registration::issueKey($user, AP_Registration::PURPOSE_RESET, $this->db);
        $this->assertNotSame('', $plain);

        // Forge an expired stored key (timestamp far in the past).
        $oldTs = time() - AP_Registration::KEY_TTL - 10;
        $hmac = hash_hmac(
            'sha256',
            AP_Registration::PURPOSE_RESET . '|' . $user->ID . '|' . $oldTs . '|' . $plain,
            (string) AP_AUTH_KEY . (string) AP_AUTH_SALT
        );
        $this->db->update(
            'users',
            ['user_activation_key' => AP_Registration::PURPOSE_RESET . ':' . $oldTs . ':' . $hmac],
            ['ID' => $user->ID]
        );
        $user = AP_User::getById($user->ID, $this->db);
        $this->assertNotNull($user);
        $this->assertFalse(
            AP_Registration::validateKey($user, $plain, AP_Registration::PURPOSE_RESET)
        );
    }

    public function testLoginPageExposesRegistrationActions(): void
    {
        $src = file_get_contents($this->root . '/ap-admin/login.php');
        $this->assertIsString($src);
        foreach (
            [
                "action === 'register'",
                "action === 'lostpassword'",
                "action === 'rp'",
                "action === 'verifyemail'",
                'ap_register_user',
                'ap_request_password_reset',
                'ap_reset_password',
                'ap_verify_user_email',
                'ap_resend_user_verification',
                "action === 'resend'",
                'mail_sent',
                'captcha_answer',
                'ap_hp',
                'ap_form_ticket',
                'formTicketForDisplay',
                'class="ap-hp"',
                'Resend verification',
            ] as $needle
        ) {
            $this->assertStringContainsString($needle, $src);
        }

        // Failed send must not redirect to the "check your email" success flash.
        $confirmPos = strpos($src, "checkemail' => 'confirm'");
        $this->assertNotFalse($confirmPos);
        $failedBranch = strpos($src, "mail_sent");
        $this->assertNotFalse($failedBranch);
        $this->assertLessThan(
            $confirmPos,
            $failedBranch,
            'mail_sent failure path must run before the checkemail=confirm redirect'
        );
        $this->assertStringNotContainsString(
            "checkemail' => 'confirm'",
            substr($src, $failedBranch, 200)
        );

        // Honeypot is always-on (after the captcha branch), not nested in captcha-on only.
        $hpPos = strpos($src, 'class="ap-hp"');
        $this->assertNotFalse($hpPos);
        $captchaMath = strpos($src, "(\$captchaChallenge['mode'] ?? '') === 'math'");
        $this->assertNotFalse($captchaMath);
        $this->assertGreaterThan(
            $captchaMath,
            $hpPos,
            'honeypot markup must render for every open register form, not only math captcha'
        );

        $lostStart = strpos($src, "action === 'lostpassword'");
        $this->assertNotFalse($lostStart);
        $lostEnd = strpos($src, "action === 'resend'");
        $this->assertNotFalse($lostEnd);
        $this->assertGreaterThan($lostStart, $lostEnd);
        $lostBlock = substr($src, $lostStart, $lostEnd - $lostStart);
        $resetFailPos = strpos($lostBlock, "if (!\$result['ok'])");
        $resetConfirmPos = strpos($lostBlock, "checkemail' => 'confirm_reset'");
        $this->assertNotFalse($resetFailPos);
        $this->assertNotFalse($resetConfirmPos);
        $this->assertLessThan(
            $resetConfirmPos,
            $resetFailPos,
            'lost-password must not redirect to confirm_reset when send() fails'
        );

        $this->assertStringContainsString('use Resend verification', $src);
        $this->assertStringNotContainsString(
            'Check your inbox for the confirmation link',
            $src
        );
        $this->assertStringContainsString('empty($result[\'mail_sent\'])', $src);
        $this->assertStringNotContainsString(
            "mail_sent'] ?? true",
            $src
        );
    }

    public function testFailedSendDoesNotPrintCheckYourEmailAsSuccess(): void
    {
        AP_Mail::failNextForTests('SMTP down');
        $result = AP_Registration::register($this->withFormGate([
            'user_login' => 'nosuccessflash',
            'user_email' => 'nosuccessflash@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['needs_verification']);
        $this->assertFalse($result['mail_sent']);
        $this->assertNotEmpty($result['errors']);

        // login.php prints $errors as ap-notice--error on the same request and
        // does not redirect to checkemail=confirm when mail_sent is empty.
        $printed = $this->renderLoginNotices([], $result['errors']);
        $this->assertStringContainsString('ap-notice--error', $printed);
        $this->assertStringNotContainsString('ap-notice--success', $printed);
        $lower = strtolower($printed);
        $this->assertStringContainsString('could not be sent', $lower);
        $this->assertStringNotContainsString('check your email', $lower);
        $this->assertStringNotContainsString('registration complete', $lower);

        $src = (string) file_get_contents($this->root . '/ap-admin/login.php');
        $flash = 'Please check your email to verify your account';
        $this->assertSame(1, substr_count($src, $flash));
        $confirmIf = strpos($src, "if (\$checkEmail === 'confirm')");
        $flashPos = strpos($src, $flash);
        $this->assertNotFalse($confirmIf);
        $this->assertNotFalse($flashPos);
        $this->assertLessThan(
            $flashPos,
            $confirmIf,
            'success flash must be assigned only after checkemail=confirm'
        );

        $success = AP_Registration::register($this->withFormGate([
            'user_login' => 'successflash',
            'user_email' => 'successflash@example.test',
            'user_pass' => 'securepass8',
        ]), $this->db);
        $this->assertTrue($success['ok']);
        $this->assertTrue($success['mail_sent']);
        $this->assertSame([], $success['errors']);
        $this->assertStringContainsString(
            "checkemail' => 'confirm'",
            $src
        );
    }

    /**
     * Same notice markup login.php uses for $messages / $errors.
     *
     * @param list<string> $messages
     * @param list<string> $errors
     */
    private function renderLoginNotices(array $messages, array $errors): string
    {
        $html = '';
        foreach ($messages as $msg) {
            $html .= '<div class="ap-notice ap-notice--success">' . $msg . '</div>';
        }
        foreach ($errors as $err) {
            $html .= '<div class="ap-notice ap-notice--error">' . $err . '</div>';
        }

        return $html;
    }
}
