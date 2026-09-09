<?php

/**
 * Native SMTP client for AP_Mail (no PHPMailer / Composer runtime deps).
 *
 * Speaks AUTH PLAIN and AUTH LOGIN over:
 * - SMTPS (`ssl`, typically port 465) via stream_socket_client + ssl://
 * - STARTTLS (`tls`, typically port 587)
 * - cleartext (`none`) for local relays and tests
 *
 * Test hooks inject a recorded transcript (read/write callables) or a custom
 * connector so the protocol can be exercised without a live mail server.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * SMTP transport used by {@see AP_Mail::send()} when transport is `smtp`.
 */
class AP_SMTP
{
    public const ENCRYPTION_NONE = 'none';

    public const ENCRYPTION_TLS = 'tls';

    public const ENCRYPTION_SSL = 'ssl';

    public const DEFAULT_TIMEOUT = 15.0;

    public const DEFAULT_PORT_TLS = 587;

    public const DEFAULT_PORT_SSL = 465;

    private static string $lastError = '';

    /** @var (callable(string, float, mixed): mixed)|null */
    private static $connector = null;

    /** @var callable(): string|null */
    private static $testRead = null;

    /** @var callable(string): bool|null */
    private static $testWrite = null;

    /** @var callable(): bool|null */
    private static $testCrypto = null;

    /**
     * Last SMTP / connection error (empty string when the previous send succeeded).
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * Replace stream_socket_client in tests. Callback receives (remote, timeout, context).
     *
     * @param (callable(string, float, mixed): mixed)|null $connector
     */
    public static function setConnectorForTests(?callable $connector): void
    {
        self::$connector = $connector;
    }

    /**
     * Drive the SMTP session from a recorded transcript instead of a socket.
     *
     * @param callable(): string|null     $read   One reply line per call (no live server).
     * @param callable(string): bool|null $write  Receives each client command (CRLF stripped in tests).
     * @param callable(): bool|null       $crypto STARTTLS stand-in (return true to succeed).
     */
    public static function setIoForTests(
        ?callable $read,
        ?callable $write = null,
        ?callable $crypto = null
    ): void {
        self::$testRead = $read;
        self::$testWrite = $write;
        self::$testCrypto = $crypto;
    }

    /**
     * Clear test doubles and the last error.
     */
    public static function resetForTests(): void
    {
        self::$connector = null;
        self::$testRead = null;
        self::$testWrite = null;
        self::$testCrypto = null;
        self::$lastError = '';
    }

    /**
     * Remote address passed to stream_socket_client (ssl:// for SMTPS, tcp:// otherwise).
     *
     * @param array<string, mixed> $config
     */
    public static function remoteAddress(array $config): string
    {
        $config = self::normalize($config);
        $host = $config['host'];
        $port = $config['port'];
        $scheme = $config['encryption'] === self::ENCRYPTION_SSL ? 'ssl' : 'tcp';

        if (
            !str_starts_with($host, '[')
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
        ) {
            $host = '[' . $host . ']';
        }

        return $scheme . '://' . $host . ':' . $port;
    }

    /**
     * Open a TCP/SMTPS stream with stream_socket_client.
     *
     * @param array<string, mixed> $config
     * @return resource|false
     */
    public static function open(array $config): mixed
    {
        $config = self::normalize($config);
        if ($config['host'] === '') {
            self::$lastError = 'SMTP host is not configured.';

            return false;
        }

        if (
            $config['encryption'] === self::ENCRYPTION_SSL
            && !extension_loaded('openssl')
            && self::$connector === null
        ) {
            self::$lastError = 'SMTPS requires the OpenSSL PHP extension.';

            return false;
        }

        $remote = self::remoteAddress($config);
        $timeout = (float) $config['timeout'];
        $context = self::streamContext($config);

        if (self::$connector !== null) {
            $stream = (self::$connector)($remote, $timeout, $context);
            if (!is_resource($stream)) {
                self::$lastError = 'SMTP connector did not return a stream.';

                return false;
            }

            return $stream;
        }

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            $detail = trim($errstr) !== '' ? $errstr : ('error ' . $errno);
            self::$lastError = 'Could not connect to SMTP server ' . $remote . ' (' . $detail . ').';

            return false;
        }

        stream_set_timeout($stream, (int) max(1.0, $timeout));
        stream_set_blocking($stream, true);

        return $stream;
    }

    /**
     * Send one message. $data is a complete RFC 5322 payload (headers + body).
     *
     * @param array<string, mixed> $config
     * @param list<string>         $recipients Envelope RCPT TO addresses.
     */
    public static function send(
        array $config,
        string $from,
        array $recipients,
        string $data
    ): bool {
        self::$lastError = '';
        $config = self::normalize($config);

        $from = self::mailbox($from);
        if ($from === '' || !self::isValidMailbox($from)) {
            self::$lastError = 'SMTP envelope From address is invalid.';

            return false;
        }

        $rcpt = [];
        foreach ($recipients as $addr) {
            $addr = self::mailbox((string) $addr);
            if ($addr !== '' && self::isValidMailbox($addr)) {
                $rcpt[] = $addr;
            }
        }
        if ($rcpt === []) {
            self::$lastError = 'No valid SMTP recipients.';

            return false;
        }

        if (self::$testRead !== null && self::$testWrite !== null) {
            $crypto = self::$testCrypto ?? static function (): bool {
                return true;
            };

            return self::session(
                self::$testRead,
                self::$testWrite,
                $crypto,
                $config,
                $from,
                $rcpt,
                $data
            );
        }

        $stream = self::open($config);
        if ($stream === false) {
            return false;
        }

        try {
            $read = static function () use ($stream): string {
                return self::streamReadLine($stream);
            };
            $write = static function (string $line) use ($stream): bool {
                return self::streamWrite($stream, $line);
            };
            $crypto = static function () use ($stream): bool {
                return self::streamCrypto($stream);
            };

            return self::session($read, $write, $crypto, $config, $from, $rcpt, $data);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     username: string,
     *     password: string,
     *     timeout: float,
     *     helo: string
     * }
     */
    public static function normalize(array $config): array
    {
        $encryption = self::normalizeEncryption((string) ($config['encryption'] ?? ''));
        $port = (int) ($config['port'] ?? 0);
        if ($port <= 0 || $port > 65535) {
            $port = $encryption === self::ENCRYPTION_SSL
                ? self::DEFAULT_PORT_SSL
                : self::DEFAULT_PORT_TLS;
        }

        $timeout = (float) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);
        if ($timeout <= 0) {
            $timeout = self::DEFAULT_TIMEOUT;
        }

        return [
            'host' => self::sanitizeHost((string) ($config['host'] ?? '')),
            'port' => $port,
            'encryption' => $encryption,
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
            'timeout' => $timeout,
            'helo' => self::heloName((string) ($config['helo'] ?? '')),
        ];
    }

    /**
     * @param callable(): string    $read
     * @param callable(string): bool $write
     * @param callable(): bool      $crypto
     * @param array<string, mixed>  $config
     * @param list<string>          $recipients
     */
    private static function session(
        callable $read,
        callable $write,
        callable $crypto,
        array $config,
        string $from,
        array $recipients,
        string $data
    ): bool {
        $greeting = self::readReply($read);
        if ($greeting === null || !self::isCode($greeting['code'], 2)) {
            self::$lastError = self::formatReply('SMTP greeting failed', $greeting);

            return false;
        }

        $ehlo = self::ehlo($read, $write, $config['helo']);
        if ($ehlo === null) {
            return false;
        }

        if ($config['encryption'] === self::ENCRYPTION_TLS) {
            if (
                !extension_loaded('openssl')
                && self::$testCrypto === null
                && self::$testRead === null
            ) {
                self::$lastError = 'STARTTLS requires the OpenSSL PHP extension.';

                return false;
            }
            $tls = self::command($read, $write, 'STARTTLS');
            if ($tls === null || !self::isCode($tls['code'], 2)) {
                self::$lastError = self::formatReply('STARTTLS failed', $tls);

                return false;
            }
            if ($crypto() !== true) {
                self::$lastError = 'Failed to enable TLS after STARTTLS.';

                return false;
            }
            $ehlo = self::ehlo($read, $write, $config['helo']);
            if ($ehlo === null) {
                return false;
            }
        }

        $user = $config['username'];
        $pass = $config['password'];
        if ($user !== '') {
            if (strpbrk($user, "\r\n\0") !== false || strpbrk($pass, "\r\n\0") !== false) {
                self::$lastError = 'SMTP credentials contain invalid characters.';

                return false;
            }
            $mechs = self::authMechs($ehlo['text']);
            if (!self::authenticate($read, $write, $user, $pass, $mechs)) {
                return false;
            }
        }

        $mailFrom = self::command($read, $write, 'MAIL FROM:<' . $from . '>');
        if ($mailFrom === null || !self::isCode($mailFrom['code'], 2)) {
            self::$lastError = self::formatReply('MAIL FROM failed', $mailFrom);

            return false;
        }

        foreach ($recipients as $addr) {
            $rcpt = self::command($read, $write, 'RCPT TO:<' . $addr . '>');
            if ($rcpt === null || !self::isCode($rcpt['code'], 2)) {
                self::$lastError = self::formatReply('RCPT TO failed', $rcpt);

                return false;
            }
        }

        $dataReply = self::command($read, $write, 'DATA');
        if ($dataReply === null || !self::isCode($dataReply['code'], 3)) {
            self::$lastError = self::formatReply('DATA failed', $dataReply);

            return false;
        }

        $stuffed = self::dotStuff($data);
        if ($write($stuffed . ".\r\n") !== true) {
            self::$lastError = 'Failed to write SMTP message body.';

            return false;
        }
        $done = self::readReply($read);
        if ($done === null || !self::isCode($done['code'], 2)) {
            self::$lastError = self::formatReply('Message rejected', $done);

            return false;
        }

        // QUIT is best-effort; the message is already accepted.
        $write('QUIT');
        $read();
        self::$lastError = '';

        return true;
    }

    /**
     * @param callable(): string     $read
     * @param callable(string): bool $write
     * @return array{code: int, text: string}|null
     */
    private static function ehlo(callable $read, callable $write, string $helo): ?array
    {
        $reply = self::command($read, $write, 'EHLO ' . $helo);
        if ($reply !== null && self::isCode($reply['code'], 2)) {
            return $reply;
        }
        $reply = self::command($read, $write, 'HELO ' . $helo);
        if ($reply !== null && self::isCode($reply['code'], 2)) {
            return $reply;
        }
        self::$lastError = self::formatReply('EHLO/HELO failed', $reply);

        return null;
    }

    /**
     * @param callable(): string     $read
     * @param callable(string): bool $write
     * @param list<string>           $mechs
     */
    private static function authenticate(
        callable $read,
        callable $write,
        string $user,
        string $pass,
        array $mechs
    ): bool {
        $preferLogin = in_array('LOGIN', $mechs, true) && !in_array('PLAIN', $mechs, true);

        if (!$preferLogin) {
            if (self::authPlain($read, $write, $user, $pass)) {
                return true;
            }
            if (in_array('LOGIN', $mechs, true) || $mechs === []) {
                return self::authLogin($read, $write, $user, $pass);
            }

            return false;
        }

        return self::authLogin($read, $write, $user, $pass);
    }

    /**
     * @param callable(): string     $read
     * @param callable(string): bool $write
     */
    private static function authPlain(
        callable $read,
        callable $write,
        string $user,
        string $pass
    ): bool {
        $payload = base64_encode("\0" . $user . "\0" . $pass);
        $reply = self::command($read, $write, 'AUTH PLAIN ' . $payload);
        if ($reply !== null && $reply['code'] === 334) {
            $reply = self::command($read, $write, $payload);
        }
        if ($reply !== null && self::isCode($reply['code'], 2)) {
            return true;
        }
        self::$lastError = self::formatReply('AUTH PLAIN failed', $reply);

        return false;
    }

    /**
     * @param callable(): string     $read
     * @param callable(string): bool $write
     */
    private static function authLogin(
        callable $read,
        callable $write,
        string $user,
        string $pass
    ): bool {
        $reply = self::command($read, $write, 'AUTH LOGIN');
        if ($reply !== null && self::isCode($reply['code'], 2)) {
            return true;
        }
        if ($reply === null || $reply['code'] !== 334) {
            self::$lastError = self::formatReply('AUTH LOGIN failed', $reply);

            return false;
        }
        $reply = self::command($read, $write, base64_encode($user));
        if ($reply === null || ($reply['code'] !== 334 && !self::isCode($reply['code'], 2))) {
            self::$lastError = self::formatReply('AUTH LOGIN username rejected', $reply);

            return false;
        }
        if (self::isCode($reply['code'], 2)) {
            return true;
        }
        $reply = self::command($read, $write, base64_encode($pass));
        if ($reply === null || !self::isCode($reply['code'], 2)) {
            self::$lastError = self::formatReply('AUTH LOGIN password rejected', $reply);

            return false;
        }

        return true;
    }

    /**
     * @param callable(): string     $read
     * @param callable(string): bool $write
     * @return array{code: int, text: string}|null
     */
    private static function command(callable $read, callable $write, string $line): ?array
    {
        if ($write($line) !== true) {
            self::$lastError = 'Failed to write SMTP command.';

            return null;
        }

        return self::readReply($read);
    }

    /**
     * @param callable(): string $read
     * @return array{code: int, text: string}|null
     */
    private static function readReply(callable $read): ?array
    {
        $lines = [];
        $code = 0;
        for ($i = 0; $i < 64; $i++) {
            $line = $read();
            $line = str_replace(["\r", "\n"], '', (string) $line);
            if ($line === '') {
                break;
            }
            $lines[] = $line;
            if (preg_match('/^(\d{3})([\s\-])/', $line, $m) !== 1) {
                self::$lastError = 'Malformed SMTP reply.';

                return null;
            }
            $code = (int) $m[1];
            if ($m[2] === ' ') {
                return ['code' => $code, 'text' => implode("\n", $lines)];
            }
        }

        if ($code === 0) {
            self::$lastError = 'SMTP server closed the connection.';

            return null;
        }

        return ['code' => $code, 'text' => implode("\n", $lines)];
    }

    /**
     * @return list<string>
     */
    private static function authMechs(string $ehloText): array
    {
        $found = [];
        foreach (preg_split("/\r\n|\n/", $ehloText) ?: [] as $line) {
            if (preg_match('/^250[\s\-]AUTH(?:\s|=)(.+)$/i', trim($line), $m) !== 1) {
                continue;
            }
            foreach (preg_split('/\s+/', strtoupper(trim($m[1]))) ?: [] as $mech) {
                $mech = preg_replace('/[^A-Z0-9\-]/', '', $mech) ?? '';
                if ($mech !== '') {
                    $found[$mech] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param array{code: int, text: string}|null $reply
     */
    private static function formatReply(string $prefix, ?array $reply): string
    {
        if ($reply === null) {
            return $prefix . (self::$lastError !== '' ? ': ' . self::$lastError : '.');
        }
        $text = trim(preg_replace('/^\d{3}[\s\-]/m', '', $reply['text']) ?? $reply['text']);
        $text = self::truncate($text, 180);

        return $prefix . ' (' . $reply['code'] . ($text !== '' ? ' ' . $text : '') . ').';
    }

    private static function isCode(int $code, int $class): bool
    {
        return intdiv($code, 100) === $class;
    }

    private static function mailbox(string $addr): string
    {
        $addr = trim($addr);
        if (preg_match('/<([^>]+)>/', $addr, $m) === 1) {
            $addr = trim($m[1]);
        }

        return trim($addr);
    }

    /**
     * Envelope mailbox check. PHP's FILTER_VALIDATE_EMAIL follows HTML5 and
     * rejects valid local forms such as user@localhost.
     */
    private static function isValidMailbox(string $addr): bool
    {
        if (filter_var($addr, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }
        if (strpbrk($addr, " \t\r\n<>") !== false || substr_count($addr, '@') !== 1) {
            return false;
        }
        [$local, $domain] = explode('@', $addr, 2);

        return $local !== ''
            && $domain !== ''
            && (bool) preg_match('/^[A-Za-z0-9.!#$%&\'*+\/=?^_`{|}~-]+$/', $local)
            && (bool) preg_match('/^[A-Za-z0-9.-]+$/', $domain);
    }

    private static function dotStuff(string $data): string
    {
        $data = str_replace(["\r\n", "\r"], "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data) ?? $data;
        if (!str_ends_with($data, "\r\n")) {
            $data .= "\r\n";
        }

        return $data;
    }

    private static function sanitizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '' || str_contains($host, '://') || strpbrk($host, "\r\n\0") !== false) {
            return '';
        }
        $host = preg_replace('/\s+/', '', $host) ?? '';

        return $host;
    }

    private static function normalizeEncryption(string $encryption): string
    {
        $encryption = strtolower(trim($encryption));
        if ($encryption === 'ssl' || $encryption === 'smtps') {
            return self::ENCRYPTION_SSL;
        }
        if ($encryption === 'tls' || $encryption === 'starttls') {
            return self::ENCRYPTION_TLS;
        }

        return self::ENCRYPTION_NONE;
    }

    private static function heloName(string $helo): string
    {
        $helo = preg_replace('/[^A-Za-z0-9.\-]/', '', trim($helo)) ?? '';
        if ($helo !== '') {
            return $helo;
        }
        $host = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            $detected = gethostname();
            $host = is_string($detected) ? $detected : '';
        }
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host) ?? '';

        return $host !== '' ? $host : 'localhost';
    }

    /**
     * @param array<string, mixed> $config
     * @return resource
     */
    private static function streamContext(array $config): mixed
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ];
        $host = (string) $config['host'];
        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            $ssl['peer_name'] = $host;
            $ssl['SNI_enabled'] = true;
        }

        return stream_context_create(['ssl' => $ssl]);
    }

    /**
     * @param resource $stream
     */
    private static function streamReadLine(mixed $stream): string
    {
        $line = @fgets($stream, 8192);
        if ($line === false) {
            return '';
        }

        return $line;
    }

    /**
     * @param resource $stream
     */
    private static function streamWrite(mixed $stream, string $line): bool
    {
        if (!str_ends_with($line, "\r\n")) {
            $line .= "\r\n";
        }
        $length = strlen($line);
        $offset = 0;
        while ($offset < $length) {
            $written = @fwrite($stream, substr($line, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    /**
     * @param resource $stream
     */
    private static function streamCrypto(mixed $stream): bool
    {
        if (self::$testCrypto !== null) {
            return (bool) (self::$testCrypto)();
        }
        if (!function_exists('stream_socket_enable_crypto')) {
            self::$lastError = 'STARTTLS requires the OpenSSL PHP extension.';

            return false;
        }
        $ok = @stream_socket_enable_crypto(
            $stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        return $ok === true;
    }

    private static function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max - 3) . '...';
    }
}
