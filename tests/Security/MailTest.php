<?php

/**
 * Unit tests for AP_Mail (test outbox, validation, header sanitization).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Security;

use AP_Mail;
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

        AP_Mail::enableTestMode();
        AP_Mail::clearTestOutbox();
    }

    protected function tearDown(): void
    {
        AP_Mail::disableTestMode();
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
}
