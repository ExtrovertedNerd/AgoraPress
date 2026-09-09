<?php

/**
 * Unit tests for AP_Mail (test outbox, validation, header sanitization).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Mail;
use AP_Rate_Limit;
use AP_SMTP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_Mail::class)]
final class MailTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/class-ap-mail.php';
        require_once $this->root . '/ap-includes/class-ap-smtp.php';

        AP_Mail::resetForTests();
        AP_SMTP::resetForTests();
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::disable();
        }
        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();
    }

    protected function tearDown(): void
    {
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::enable();
        }
        AP_Mail::resetForTests();
        AP_SMTP::resetForTests();
    }

    public function testTestModeCapturesOutboundMail(): void
    {
        $this->assertTrue(AP_Mail::isTestMode());
        $ok = AP_Mail::send('user@example.test', 'Hello', "Line one\nLine two");
        $this->assertTrue($ok);

        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('user@example.test', $outbox[0]['to']);
        $this->assertSame('Hello', $outbox[0]['subject']);
        $this->assertStringContainsString("Line one\r\nLine two", $outbox[0]['message']);
        $this->assertStringContainsString('Content-Type:', $outbox[0]['headers']);
        $this->assertStringContainsString('X-Mailer: AgoraPress', $outbox[0]['headers']);
    }

    public function testRejectsInvalidRecipients(): void
    {
        $this->assertFalse(AP_Mail::send('', 'S', 'B'));
        $this->assertFalse(AP_Mail::send('not-an-email', 'S', 'B'));
        $this->assertFalse(AP_Mail::send(['bad', 'also-bad'], 'S', 'B'));
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testFailNextForTestsDoesNotCaptureOutbox(): void
    {
        AP_Mail::failNextForTests('SMTP down');
        $this->assertFalse(AP_Mail::send('user@example.test', 'Hello', 'Body'));
        $this->assertSame('SMTP down', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertSame('', AP_Mail::lastError());
        $this->assertCount(1, AP_Mail::getTestOutbox());
    }

    public function testAcceptsMultipleValidRecipients(): void
    {
        $ok = AP_Mail::send(
            ['a@example.test', ' bad@x ', 'b@example.test'],
            'Multi',
            'Body'
        );
        $this->assertTrue($ok);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        $this->assertSame('a@example.test, b@example.test', $outbox[0]['to']);
    }

    public function testHeaderInjectionNewlinesStrippedFromSubject(): void
    {
        $ok = AP_Mail::send(
            'user@example.test',
            "Subject\r\nBcc: evil@evil.test",
            'Body'
        );
        $this->assertTrue($ok);
        $outbox = AP_Mail::getTestOutbox();
        $this->assertCount(1, $outbox);
        // CR/LF removed so the payload cannot become a second header line.
        $this->assertStringNotContainsString("\r", $outbox[0]['subject']);
        $this->assertStringNotContainsString("\n", $outbox[0]['subject']);
        $this->assertStringContainsString('Subject', $outbox[0]['subject']);
    }

    public function testCustomHeadersAreSanitized(): void
    {
        $ok = AP_Mail::send('user@example.test', 'H', 'B', [
            "X-Custom\r\nInject" => "value\ninjected",
            'Reply-To' => 'reply@example.test',
            '' => 'skip-me',
        ]);
        $this->assertTrue($ok);
        $headers = AP_Mail::getTestOutbox()[0]['headers'];
        $this->assertStringContainsString('Reply-To: reply@example.test', $headers);
        // Newlines cannot appear inside header blocks (injection vector).
        $this->assertStringNotContainsString("\r\nInject", $headers);
        $this->assertDoesNotMatchRegularExpression('/value\s*\n\s*injected/', $headers);
        $this->assertStringContainsString('valueinjected', $headers);
    }

    public function testEmptySubjectFallsBackToDefault(): void
    {
        $ok = AP_Mail::send('user@example.test', '   ', 'Body');
        $this->assertTrue($ok);
        $this->assertSame('AgoraPress', AP_Mail::getTestOutbox()[0]['subject']);
    }

    public function testClearOutboxKeepsTestMode(): void
    {
        AP_Mail::send('user@example.test', 'One', 'B');
        $this->assertCount(1, AP_Mail::getTestOutbox());
        AP_Mail::clearTestOutbox();
        $this->assertTrue(AP_Mail::isTestMode());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testDisableTestModeClearsCapture(): void
    {
        AP_Mail::send('user@example.test', 'One', 'B');
        AP_Mail::disableTestMode();
        $this->assertFalse(AP_Mail::isTestMode());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testFromAddressFallbackWithoutOptions(): void
    {
        $addr = AP_Mail::fromAddress();
        $this->assertNotSame('', $addr);
        $this->assertStringContainsString('@', $addr);
        $this->assertStringStartsWith('noreply@', $addr);
        $this->assertSame('AgoraPress', AP_Mail::fromName());
    }

    public function testDefaultTransportIsPhp(): void
    {
        $this->assertSame(AP_Mail::TRANSPORT_PHP, AP_Mail::transport());
        $this->assertSame('php', AP_Mail::transport());
    }

    public function testUnknownTransportFallsBackToPhp(): void
    {
        AP_Mail::setConfigForTests(['transport' => 'sendmail']);
        $this->assertSame(AP_Mail::TRANSPORT_PHP, AP_Mail::transport());
    }

    public function testSmtpTransportCanBeSelected(): void
    {
        AP_Mail::setConfigForTests(['transport' => 'SMTP']);
        $this->assertSame(AP_Mail::TRANSPORT_SMTP, AP_Mail::transport());
    }

    public function testInvalidRecipientSetsLastError(): void
    {
        $this->assertFalse(AP_Mail::send('not-an-email', 'S', 'B'));
        $this->assertSame('No valid recipients.', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testTestModeCapturesEvenWhenSmtpIsSelected(): void
    {
        $writes = [];
        AP_Mail::setConfigForTests([
            'transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'none',
        ]);
        AP_SMTP::setIoForTests(
            static function (): string {
                return '220 unused';
            },
            static function (string $line) use (&$writes): bool {
                $writes[] = $line;

                return true;
            }
        );

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertCount(1, AP_Mail::getTestOutbox());
        $this->assertSame([], $writes);
        $this->assertSame('', AP_Mail::lastError());
    }

    public function testSmtpWithoutHostFailsWhenNotCapturing(): void
    {
        AP_Mail::disableTestMode();
        AP_Mail::setConfigForTests([
            'transport' => 'smtp',
            'smtp_host' => '',
        ]);

        $this->assertFalse(AP_Mail::send('user@example.test', 'Hi', 'Body'));
        $this->assertSame('SMTP host is not configured.', AP_Mail::lastError());
    }

    public function testLastErrorClearedOnSuccessfulSend(): void
    {
        $this->assertFalse(AP_Mail::send('not-an-email', 'S', 'B'));
        $this->assertSame('No valid recipients.', AP_Mail::lastError());
        $this->assertTrue(AP_Mail::send('user@example.test', 'S', 'B'));
        $this->assertSame('', AP_Mail::lastError());
    }

    public function testSmtpSettingsDefaultToTlsOnPort587(): void
    {
        $settings = AP_Mail::smtpSettings();
        $this->assertSame('', $settings['host']);
        $this->assertSame(AP_SMTP::DEFAULT_PORT_TLS, $settings['port']);
        $this->assertSame(587, $settings['port']);
        $this->assertSame(AP_SMTP::ENCRYPTION_TLS, $settings['encryption']);
        $this->assertSame('', $settings['username']);
        $this->assertSame('', $settings['password']);
    }

    public function testSmtpSettingsHonorConfigOverlay(): void
    {
        AP_Mail::setConfigForTests([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
        ]);
        $settings = AP_Mail::smtpSettings();
        $this->assertSame('smtp.example.com', $settings['host']);
        $this->assertSame(465, $settings['port']);
        $this->assertSame(AP_SMTP::ENCRYPTION_SSL, $settings['encryption']);
        $this->assertSame('user@example.com', $settings['username']);
        $this->assertSame('secret', $settings['password']);
    }

    public function testPhpTransportStillUsesMailFunction(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-mail.php');
        $this->assertMatchesRegularExpression('/@mail\s*\(/', $src);
        $this->assertStringContainsString('private static function sendViaSmtp', $src);
        $this->assertStringContainsString('AP_SMTP::send', $src);
        $this->assertStringContainsString("ap_apply_filters('ap_mail_send'", $src);
    }

    public function testFromEmailOverlayIsSeparateFromAdminEmail(): void
    {
        AP_Mail::setConfigForTests([
            'from_email' => 'noreply@example.com',
            'from_name' => 'Site Mailer',
        ]);
        $this->assertSame('noreply@example.com', AP_Mail::fromAddress());
        $this->assertSame('Site Mailer', AP_Mail::fromName());
        $this->assertSame('', AP_Mail::adminEmail());
    }

    public function testReplyToHeaderUsesConfiguredAddress(): void
    {
        AP_Mail::setConfigForTests([
            'from_name' => 'Site Mailer',
            'from_email' => 'noreply@example.com',
            'reply_to' => 'desk@example.com',
        ]);
        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $headers = AP_Mail::getTestOutbox()[0]['headers'];
        $this->assertStringContainsString('From: Site Mailer <noreply@example.com>', $headers);
        $this->assertStringContainsString('Reply-To: desk@example.com', $headers);
    }

    public function testSendTestToAdminFailsWithoutAdminEmail(): void
    {
        $this->assertFalse(AP_Mail::sendTestToAdmin());
        $this->assertSame('No admin email is configured.', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testHealthSnapshotDoesNotSendAndOmitsSecrets(): void
    {
        AP_Mail::setConfigForTests([
            'transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 465,
            'smtp_encryption' => 'ssl',
            'smtp_user' => 'mailer@example.com',
            'smtp_pass' => 'super-secret-pass',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Example Site',
        ]);
        $before = count(AP_Mail::getTestOutbox());
        $snap = AP_Mail::healthSnapshot();
        $this->assertCount($before, AP_Mail::getTestOutbox());
        $this->assertSame('smtp', $snap['transport']);
        $this->assertSame('smtp.example.com', $snap['smtp_host']);
        $this->assertSame(465, $snap['smtp_port']);
        $this->assertSame('ssl', $snap['smtp_encryption']);
        $this->assertTrue($snap['smtp_user_set']);
        $this->assertTrue($snap['smtp_pass_set']);
        $this->assertSame('noreply@example.com', $snap['from_email']);
        $this->assertSame('Example Site', $snap['from_name']);
        $this->assertSame('', $snap['last_error']);
        $this->assertArrayNotHasKey('smtp_pass', $snap);
        $this->assertArrayNotHasKey('smtp_user', $snap);
        $this->assertIsBool($snap['php_mail_available']);
        $encoded = (string) json_encode($snap);
        $this->assertStringNotContainsString('super-secret-pass', $encoded);
        $this->assertStringNotContainsString('mailer@example.com', $encoded);
    }

    public function testHealthSnapshotIncludesLastError(): void
    {
        $this->assertFalse(AP_Mail::send('', 'S', 'B'));
        $snap = AP_Mail::healthSnapshot();
        $this->assertSame('No valid recipients.', $snap['last_error']);
        $this->assertSame('php', $snap['transport']);
    }

    public function testInvalidFromEmailFallsBack(): void
    {
        AP_Mail::setConfigForTests(['from_email' => 'not-an-email']);
        $addr = AP_Mail::fromAddress();
        $this->assertStringStartsWith('noreply@', $addr);
    }

    public function testConfigConstantNameMap(): void
    {
        $this->assertSame('AP_MAIL_TRANSPORT', AP_Mail::configConstantName('transport'));
        $this->assertSame('AP_MAIL_FROM_NAME', AP_Mail::configConstantName('from_name'));
        $this->assertSame('AP_MAIL_FROM_EMAIL', AP_Mail::configConstantName('from_email'));
        $this->assertSame('AP_SMTP_HOST', AP_Mail::configConstantName('smtp_host'));
        $this->assertSame('AP_SMTP_PORT', AP_Mail::configConstantName('smtp_port'));
        $this->assertSame('AP_SMTP_ENCRYPTION', AP_Mail::configConstantName('smtp_encryption'));
        $this->assertSame('AP_SMTP_USER', AP_Mail::configConstantName('smtp_user'));
        $this->assertSame('AP_SMTP_PASS', AP_Mail::configConstantName('smtp_pass'));
        $this->assertNull(AP_Mail::configConstantName('reply_to'));
        $this->assertNull(AP_Mail::configConstantName('unknown'));
    }

    public function testUnitProcessDoesNotDefineMailConstants(): void
    {
        $this->assertSame([], AP_Mail::definedConfigConstants());
        foreach (
            [
                'AP_MAIL_FROM_NAME',
                'AP_MAIL_FROM_EMAIL',
                'AP_MAIL_TRANSPORT',
                'AP_SMTP_HOST',
                'AP_SMTP_PORT',
                'AP_SMTP_ENCRYPTION',
                'AP_SMTP_USER',
                'AP_SMTP_PASS',
            ] as $name
        ) {
            $this->assertFalse(defined($name), "{$name} must stay undefined in the PHPUnit process");
        }
    }

    public function testConfigConstantsOverrideOptions(): void
    {
        $result = $this->runIsolatedMailScript(<<<'PHP'
function ap_get_option(string $name, mixed $default = null): mixed
{
    $options = [
        'mail_transport' => 'php',
        'mail_from_name' => 'From Options',
        'mail_from_email' => 'options@example.com',
        'mail_reply_to' => 'reply-options@example.com',
        'smtp_host' => 'options.example.com',
        'smtp_port' => 25,
        'smtp_encryption' => 'none',
        'smtp_user' => 'options-user',
        'smtp_pass' => 'options-pass',
        'admin_email' => 'admin@example.com',
        'blogname' => 'Options Site',
    ];

    return $options[$name] ?? $default;
}

define('AP_MAIL_TRANSPORT', 'SMTP');
define('AP_MAIL_FROM_NAME', 'From Constant');
define('AP_MAIL_FROM_EMAIL', 'noreply@example.com');
define('AP_SMTP_HOST', 'smtp.example.com');
define('AP_SMTP_PORT', 465);
define('AP_SMTP_ENCRYPTION', 'ssl');
define('AP_SMTP_USER', 'smtp-user');
define('AP_SMTP_PASS', 'smtp-pass');

require $argv[1];

if (AP_Mail::transport() !== 'smtp') {
    fwrite(STDERR, "transport=" . AP_Mail::transport() . "\n");
    exit(2);
}
$s = AP_Mail::smtpSettings();
if ($s['host'] !== 'smtp.example.com' || $s['port'] !== 465 || $s['encryption'] !== 'ssl') {
    fwrite(STDERR, "smtp settings mismatch\n");
    exit(3);
}
if ($s['username'] !== 'smtp-user' || $s['password'] !== 'smtp-pass') {
    fwrite(STDERR, "smtp auth mismatch\n");
    exit(4);
}
if (AP_Mail::fromName() !== 'From Constant' || AP_Mail::fromAddress() !== 'noreply@example.com') {
    fwrite(STDERR, "from identity mismatch\n");
    exit(5);
}
if (AP_Mail::replyToAddress() !== 'reply-options@example.com') {
    fwrite(STDERR, "reply-to should stay on options (no constant)\n");
    exit(6);
}
if (!AP_Mail::hasSmtpPassword()) {
    fwrite(STDERR, "password constant should count as stored\n");
    exit(7);
}
$defined = AP_Mail::definedConfigConstants();
$need = [
    'AP_MAIL_FROM_NAME', 'AP_MAIL_FROM_EMAIL', 'AP_MAIL_TRANSPORT',
    'AP_SMTP_HOST', 'AP_SMTP_PORT', 'AP_SMTP_ENCRYPTION',
    'AP_SMTP_USER', 'AP_SMTP_PASS',
];
foreach ($need as $name) {
    if (!in_array($name, $defined, true)) {
        fwrite(STDERR, "missing defined constant {$name}\n");
        exit(8);
    }
}

AP_Mail::setConfigForTests([
    'transport' => 'php',
    'smtp_host' => 'overlay.example.com',
]);
if (AP_Mail::transport() !== 'php' || AP_Mail::smtpSettings()['host'] !== 'overlay.example.com') {
    fwrite(STDERR, "test overlay must still win over constants\n");
    exit(9);
}

echo "ok\n";
exit(0);
PHP);

        $this->assertSame(0, $result['exit'], $result['body']);
        $this->assertStringContainsString('ok', $result['body']);
    }

    public function testEmptyHostConstantOverridesOption(): void
    {
        $result = $this->runIsolatedMailScript(<<<'PHP'
function ap_get_option(string $name, mixed $default = null): mixed
{
    $options = [
        'mail_transport' => 'smtp',
        'smtp_host' => 'options.example.com',
        'smtp_port' => 587,
        'smtp_encryption' => 'tls',
        'smtp_user' => 'options-user',
        'smtp_pass' => 'options-pass',
    ];

    return $options[$name] ?? $default;
}

define('AP_SMTP_HOST', '');

require $argv[1];

$s = AP_Mail::smtpSettings();
if ($s['host'] !== '') {
    fwrite(STDERR, "empty AP_SMTP_HOST must win over option, got {$s['host']}\n");
    exit(2);
}
if ($s['username'] !== 'options-user' || $s['password'] !== 'options-pass' || $s['port'] !== 587) {
    fwrite(STDERR, "unset constants must still read options\n");
    exit(3);
}
if (AP_Mail::definedConfigConstants() !== ['AP_SMTP_HOST']) {
    fwrite(STDERR, "expected only AP_SMTP_HOST defined\n");
    exit(4);
}

echo "ok\n";
exit(0);
PHP);

        $this->assertSame(0, $result['exit'], $result['body']);
        $this->assertStringContainsString('ok', $result['body']);
    }

    public function testMailSendFilterTrueReplacesTransport(): void
    {
        $this->loadHooks();
        $seen = [];
        ap_add_filter(
            'ap_mail_send',
            static function (mixed $handled, array $atts) use (&$seen): bool {
                $seen = $atts;

                return true;
            },
            10,
            2
        );

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertSame('', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
        $this->assertSame(['user@example.test'], $seen['to']);
        $this->assertSame('user@example.test', $seen['to_header']);
        $this->assertSame('Hello', $seen['subject']);
        $this->assertStringContainsString('Body', $seen['message']);
        $this->assertSame('php', $seen['transport']);
        $this->assertArrayHasKey('From', $seen['headers']);
        $this->assertStringContainsString('X-Mailer: AgoraPress', $seen['header_string']);
    }

    public function testMailSendFilterFalseCancelsSend(): void
    {
        $this->loadHooks();
        ap_add_filter('ap_mail_send', static function (mixed $handled): bool {
            return false;
        });

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertFalse($ok);
        $this->assertSame('Mail send was cancelled.', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testMailSendFilterNullKeepsTestOutbox(): void
    {
        $this->loadHooks();
        $calls = 0;
        ap_add_filter(
            'ap_mail_send',
            static function (mixed $handled) use (&$calls): mixed {
                $calls++;

                return $handled;
            },
            10,
            1
        );

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertSame(1, $calls);
        $this->assertCount(1, AP_Mail::getTestOutbox());
        $this->assertSame('', AP_Mail::lastError());
    }

    public function testMailSendFilterNonBoolIsIgnored(): void
    {
        $this->loadHooks();
        ap_add_filter('ap_mail_send', static function (mixed $handled): string {
            return 'sent';
        });

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertCount(1, AP_Mail::getTestOutbox());
    }

    public function testMailSendFilterSkipsSmtpWhenNotCapturing(): void
    {
        $this->loadHooks();
        AP_Mail::disableTestMode();
        AP_Mail::setConfigForTests([
            'transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'none',
        ]);
        $writes = [];
        AP_SMTP::setIoForTests(
            static function (): string {
                return '220 unused';
            },
            static function (string $line) use (&$writes): bool {
                $writes[] = $line;

                return true;
            }
        );
        ap_add_filter(
            'ap_mail_send',
            static function (mixed $handled, array $atts): bool {
                return $atts['transport'] === 'smtp';
            },
            10,
            2
        );

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body');
        $this->assertTrue($ok);
        $this->assertSame([], $writes);
        $this->assertSame('', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testInvalidRecipientsDoNotFireMailSendFilter(): void
    {
        $this->loadHooks();
        $calls = 0;
        ap_add_filter('ap_mail_send', static function (mixed $handled) use (&$calls): bool {
            $calls++;

            return true;
        });

        $this->assertFalse(AP_Mail::send('not-an-email', 'S', 'B'));
        $this->assertSame(0, $calls);
        $this->assertSame('No valid recipients.', AP_Mail::lastError());
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testMailSendFilterSeesSanitizedHeaders(): void
    {
        $this->loadHooks();
        $seen = [];
        ap_add_filter(
            'ap_mail_send',
            static function (mixed $handled, array $atts) use (&$seen): bool {
                $seen = $atts;

                return true;
            },
            10,
            2
        );

        AP_Mail::send('user@example.test', "Hi\r\nBcc: evil@evil.test", 'Body', [
            "X-Custom\r\nInject" => "value\ninjected",
        ]);
        $this->assertStringNotContainsString("\r", $seen['subject']);
        $this->assertStringNotContainsString("\n", $seen['subject']);
        $this->assertArrayNotHasKey("X-Custom\r\nInject", $seen['headers']);
        $this->assertArrayHasKey('X-CustomInject', $seen['headers']);
        $this->assertSame('valueinjected', $seen['headers']['X-CustomInject']);
    }

    private function loadHooks(): void
    {
        require_once $this->root . '/ap-includes/hooks.php';
        ap_reset_hooks();
    }

    /**
     * @return array{exit: int, body: string}
     */
    private function runIsolatedMailScript(string $body): array
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $mailPath = $this->root . '/ap-includes/class-ap-mail.php';
        $script = "declare(strict_types=1);\n" . $body;

        $cmd = escapeshellarg($php)
            . ' -d display_errors=1 -d error_reporting=E_ALL -r '
            . escapeshellarg($script)
            . ' -- '
            . escapeshellarg($mailPath)
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        return [
            'exit' => $exit,
            'body' => implode("\n", $output),
        ];
    }
}
