<?php

/**
 * Unit tests for the native SMTP client (recorded transcripts, no live server).
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

#[CoversClass(AP_SMTP::class)]
#[CoversClass(AP_Mail::class)]
final class SmtpTest extends TestCase
{
    private string $root;

    private bool $lastOk = false;

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
    }

    protected function tearDown(): void
    {
        if (class_exists('AP_Rate_Limit', false)) {
            AP_Rate_Limit::enable();
        }
        AP_Mail::resetForTests();
        AP_SMTP::resetForTests();
    }

    public function testRemoteAddressUsesSslSchemeForSmtps(): void
    {
        $this->assertSame(
            'ssl://smtp.example.com:465',
            AP_SMTP::remoteAddress([
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
            ])
        );
    }

    public function testRemoteAddressUsesTcpForStarttls(): void
    {
        $this->assertSame(
            'tcp://smtp.example.com:587',
            AP_SMTP::remoteAddress([
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
            ])
        );
    }

    public function testRemoteAddressWrapsIpv6(): void
    {
        $this->assertSame(
            'ssl://[2001:db8::1]:465',
            AP_SMTP::remoteAddress([
                'host' => '2001:db8::1',
                'port' => 465,
                'encryption' => 'ssl',
            ])
        );
    }

    public function testNormalizeDefaultsSslPortTo465(): void
    {
        $cfg = AP_SMTP::normalize([
            'host' => 'smtp.example.com',
            'encryption' => 'ssl',
        ]);
        $this->assertSame(465, $cfg['port']);
        $this->assertSame(AP_SMTP::ENCRYPTION_SSL, $cfg['encryption']);
    }

    public function testNormalizeDefaultsTlsPortTo587(): void
    {
        $cfg = AP_SMTP::normalize([
            'host' => 'smtp.example.com',
            'encryption' => 'tls',
        ]);
        $this->assertSame(587, $cfg['port']);
        $this->assertSame(AP_SMTP::ENCRYPTION_TLS, $cfg['encryption']);
    }

    public function testRemoteAddressUsesTcpForCleartext(): void
    {
        $this->assertSame(
            'tcp://smtp.example.com:587',
            AP_SMTP::remoteAddress([
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'none',
            ])
        );
    }

    public function testNormalizeEncryptionAliases(): void
    {
        $this->assertSame(
            AP_SMTP::ENCRYPTION_SSL,
            AP_SMTP::normalize(['host' => 'smtp.example.com', 'encryption' => 'smtps'])['encryption']
        );
        $this->assertSame(
            AP_SMTP::ENCRYPTION_TLS,
            AP_SMTP::normalize(['host' => 'smtp.example.com', 'encryption' => 'STARTTLS'])['encryption']
        );
        $this->assertSame(
            AP_SMTP::ENCRYPTION_NONE,
            AP_SMTP::normalize(['host' => 'smtp.example.com', 'encryption' => 'none'])['encryption']
        );
    }

    public function testNormalizeRejectsHostWithSchemeOrControlChars(): void
    {
        $this->assertSame('', AP_SMTP::normalize(['host' => 'ssl://smtp.example.com'])['host']);
        $this->assertSame('', AP_SMTP::normalize(['host' => "smtp.example.com\r\nEVIL"])['host']);
    }

    public function testOpenWithoutHostSetsErrorAndDoesNotConnect(): void
    {
        $this->assertFalse(AP_SMTP::open(['host' => '']));
        $this->assertSame('SMTP host is not configured.', AP_SMTP::lastError());
    }

    public function testConnectorReceivesSmtpsRemoteAndAvoidsLiveConnect(): void
    {
        $seen = '';
        AP_SMTP::setConnectorForTests(
            static function (string $remote) use (&$seen): mixed {
                $seen = $remote;

                return false;
            }
        );

        $this->assertFalse(AP_SMTP::open([
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
        ]));
        $this->assertSame('ssl://smtp.example.com:465', $seen);
        $this->assertSame('SMTP connector did not return a stream.', AP_SMTP::lastError());
    }

    public function testAuthPlainTranscript(): void
    {
        $user = 'user@example.com';
        $pass = 'secret';
        $payload = base64_encode("\0" . $user . "\0" . $pass);
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250-AUTH PLAIN LOGIN\r\n250 OK",
                '235 2.7.0 Authentication successful',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'username' => $user,
                'password' => $pass,
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertTrue($this->lastOk);
        $this->assertSame('', AP_SMTP::lastError());
        $blob = $this->blob($writes);
        $this->assertStringContainsString('EHLO agorapress.test', $blob);
        $this->assertStringContainsString('AUTH PLAIN ' . $payload, $blob);
        $this->assertStringContainsString('MAIL FROM:<noreply@example.com>', $blob);
        $this->assertStringContainsString('RCPT TO:<user@example.test>', $blob);
        $this->assertStringContainsString('DATA', $blob);
        $this->assertStringContainsString('QUIT', $blob);
        $this->assertStringNotContainsString('STARTTLS', $blob);
        $this->assertStringNotContainsString('AUTH LOGIN', $blob);
    }

    public function testAuthLoginWhenPlainIsNotAdvertised(): void
    {
        $user = 'user@example.com';
        $pass = 'secret';
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250-AUTH LOGIN\r\n250 OK",
                '334 VXNlcm5hbWU6',
                '334 UGFzc3dvcmQ6',
                '235 2.7.0 Authentication successful',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'username' => $user,
                'password' => $pass,
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertTrue($this->lastOk);
        $blob = $this->blob($writes);
        $this->assertStringContainsString("AUTH LOGIN\n", $blob . "\n");
        $this->assertStringContainsString(base64_encode($user), $blob);
        $this->assertStringContainsString(base64_encode($pass), $blob);
        $this->assertStringNotContainsString('AUTH PLAIN', $blob);
    }

    public function testStarttlsThenAuthPlain(): void
    {
        $cryptoCalls = 0;
        $user = 'user@example.com';
        $pass = 'secret';
        $payload = base64_encode("\0" . $user . "\0" . $pass);
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250-STARTTLS\r\n250 AUTH PLAIN",
                '220 2.0.0 Ready to start TLS',
                "250-smtp.example.com\r\n250-AUTH PLAIN\r\n250 OK",
                '235 2.7.0 Authentication successful',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => $user,
                'password' => $pass,
                'helo' => 'agorapress.test',
            ],
            static function () use (&$cryptoCalls): bool {
                $cryptoCalls++;

                return true;
            }
        );

        $this->assertTrue($this->lastOk);
        $this->assertSame(1, $cryptoCalls);
        $blob = $this->blob($writes);
        $this->assertStringContainsString('STARTTLS', $blob);
        $this->assertSame(2, substr_count($blob, 'EHLO agorapress.test'));
        $this->assertStringContainsString('AUTH PLAIN ' . $payload, $blob);
    }

    public function testSmtpsDoesNotIssueStarttls(): void
    {
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250 AUTH PLAIN",
                '235 2.7.0 Authentication successful',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'user@example.com',
                'password' => 'secret',
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertTrue($this->lastOk);
        $this->assertStringNotContainsString('STARTTLS', $this->blob($writes));
    }

    public function testDotStuffingAndHeloFallback(): void
    {
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                '502 5.5.1 EHLO not supported',
                '250 smtp.example.com',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'helo' => 'agorapress.test',
            ],
            null,
            "Line one\n.hidden\nLine two"
        );

        $this->assertTrue($this->lastOk);
        $blob = $this->blob($writes);
        $this->assertStringContainsString('EHLO agorapress.test', $blob);
        $this->assertStringContainsString('HELO agorapress.test', $blob);
        $this->assertStringContainsString("..hidden", $blob);
    }

    public function testFailedAuthSetsLastError(): void
    {
        $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250 AUTH PLAIN",
                '535 5.7.8 Authentication failed',
            ],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'username' => 'user@example.com',
                'password' => 'wrong',
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertFalse($this->lastOk);
        $this->assertStringContainsString('AUTH PLAIN failed', AP_SMTP::lastError());
    }

    public function testAuthPlainContinuationChallenge(): void
    {
        $user = 'user@example.com';
        $pass = 'secret';
        $payload = base64_encode("\0" . $user . "\0" . $pass);
        $writes = $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250 AUTH PLAIN",
                '334 ',
                '235 2.7.0 Authentication successful',
                '250 2.1.0 OK',
                '250 2.1.5 OK',
                '354 Start mail input',
                '250 2.0.0 Queued',
                '221 Bye',
            ],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'username' => $user,
                'password' => $pass,
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertTrue($this->lastOk);
        $blob = $this->blob($writes);
        $this->assertStringContainsString('AUTH PLAIN ' . $payload, $blob);
        $this->assertSame(2, substr_count($blob, $payload));
    }

    public function testStarttlsCryptoFailureSetsLastError(): void
    {
        $this->play(
            [
                '220 smtp.example.com ESMTP',
                "250-smtp.example.com\r\n250-STARTTLS\r\n250 AUTH PLAIN",
                '220 2.0.0 Ready to start TLS',
            ],
            [
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'helo' => 'agorapress.test',
            ],
            static function (): bool {
                return false;
            }
        );

        $this->assertFalse($this->lastOk);
        $this->assertSame('Failed to enable TLS after STARTTLS.', AP_SMTP::lastError());
    }

    public function testInvalidEnvelopeFromDoesNotConnect(): void
    {
        $connected = false;
        AP_SMTP::setConnectorForTests(
            static function (string $remote, float $timeout, mixed $context) use (&$connected): mixed {
                $connected = true;

                return false;
            }
        );

        $this->assertFalse(AP_SMTP::send(
            ['host' => 'smtp.example.com', 'encryption' => 'none'],
            'not-an-email',
            ['user@example.test'],
            "Subject: Hi\r\n\r\nBody"
        ));
        $this->assertFalse($connected);
        $this->assertSame('SMTP envelope From address is invalid.', AP_SMTP::lastError());
    }

    public function testFailedGreetingSetsLastError(): void
    {
        $this->play(
            ['421 4.3.2 Service not available'],
            [
                'host' => 'smtp.example.com',
                'encryption' => 'none',
                'helo' => 'agorapress.test',
            ]
        );

        $this->assertFalse($this->lastOk);
        $this->assertStringContainsString('SMTP greeting failed', AP_SMTP::lastError());
    }

    public function testApMailSendUsesSmtpTransportWithTranscript(): void
    {
        $replies = [
            '220 smtp.example.com ESMTP',
            "250-smtp.example.com\r\n250-AUTH PLAIN\r\n250 OK",
            '235 2.7.0 Authentication successful',
            '250 2.1.0 OK',
            '250 2.1.5 OK',
            '354 Start mail input',
            '250 2.0.0 Queued',
            '221 Bye',
        ];
        $writes = [];
        AP_SMTP::setIoForTests(
            static function () use (&$replies): string {
                return self::nextReply($replies);
            },
            static function (string $line) use (&$writes): bool {
                $writes[] = $line;

                return true;
            }
        );
        AP_Mail::setConfigForTests([
            'transport' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'none',
            'smtp_user' => 'user@example.com',
            'smtp_pass' => 'secret',
        ]);

        $ok = AP_Mail::send('user@example.test', 'Hello', 'Body text');
        $this->assertTrue($ok);
        $this->assertSame('', AP_Mail::lastError());
        $blob = $this->blob($writes);
        $this->assertStringContainsString('AUTH PLAIN', $blob);
        $this->assertStringContainsString('RCPT TO:<user@example.test>', $blob);
        $this->assertStringContainsString('Subject: Hello', $blob);
        $this->assertStringContainsString('Body text', $blob);
        $this->assertSame([], AP_Mail::getTestOutbox());
    }

    public function testSourceUsesStreamSocketClientAndNotPhpmailer(): void
    {
        $src = (string) file_get_contents($this->root . '/ap-includes/class-ap-smtp.php');
        $this->assertStringContainsString('stream_socket_client', $src);
        $this->assertStringContainsString('AUTH PLAIN', $src);
        $this->assertStringContainsString('AUTH LOGIN', $src);
        $this->assertStringNotContainsString('new PHPMailer', $src);
        $this->assertStringNotContainsString('use PHPMailer', $src);
    }

    /**
     * @param list<string>         $replies
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function play(
        array $replies,
        array $config,
        ?callable $crypto = null,
        string $body = "Subject: Hello\r\n\r\nBody"
    ): array {
        $writes = [];
        $queue = $replies;
        AP_SMTP::setIoForTests(
            static function () use (&$queue): string {
                return self::nextReply($queue);
            },
            static function (string $line) use (&$writes): bool {
                $writes[] = $line;

                return true;
            },
            $crypto
        );

        $this->lastOk = AP_SMTP::send(
            $config,
            'noreply@example.com',
            ['user@example.test'],
            $body
        );

        return $writes;
    }

    /**
     * @param list<string> $queue
     */
    private static function nextReply(array &$queue): string
    {
        if ($queue === []) {
            return '';
        }
        $line = array_shift($queue);
        // readReply() consumes one fgets-style line per call; expand bundled 250- blocks.
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            $parts = preg_split("/\r\n|\n/", $line) ?: [];
            $first = array_shift($parts);
            foreach (array_reverse($parts) as $part) {
                if ($part !== '') {
                    array_unshift($queue, $part);
                }
            }

            return (string) $first;
        }

        return $line;
    }

    /**
     * @param list<string> $writes
     */
    private function blob(array $writes): string
    {
        $lines = [];
        foreach ($writes as $chunk) {
            $chunk = str_replace("\r\n", "\n", $chunk);
            foreach (explode("\n", $chunk) as $line) {
                $line = rtrim($line, "\r");
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        return implode("\n", $lines);
    }
}
