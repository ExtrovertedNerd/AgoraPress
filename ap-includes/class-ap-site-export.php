<?php

declare(strict_types=1);

/**
 * Full-site JSON export utilities.
 *
 * @package AgoraPress
 */

final class AP_Site_Export
{
    public const FORMAT = 'agorapress-export';
    public const FORMAT_VERSION = 1;

    /** @var string[] */
    public const TABLES = [
        'options', 'users', 'usermeta', 'posts', 'postmeta', 'terms', 'term_taxonomy',
        'term_relationships', 'comments', 'commentmeta', 'forums', 'topics', 'forum_posts',
        'forum_attachments', 'forum_permissions', 'groups', 'group_members', 'ranks',
    ];

    /** Option and meta names that look secret are dropped from the export. */
    public const SECRET_PATTERN = '/(pass|secret|token|salt|nonce|api_?key|private_?key|_keys?$|^auth_|session|2fa|totp|(^|_)otp)/i';

    /**
     * Core options that SECRET_PATTERN matches but that hold no secret
     * (word lists and numeric rate-limit settings). These stay in the export.
     */
    public const NON_SECRET_OPTIONS = [
        'moderation_keys',
        'forum_spam_blacklist',
        'rate_limit_current_password_lockout',
        'rate_limit_current_password_max',
        'rate_limit_current_password_window',
        'rate_limit_password_reset_lockout',
        'rate_limit_password_reset_max',
        'rate_limit_password_reset_window',
    ];

    /**
     * Whether an option name should be left out of the export.
     */
    public static function isSecretOption(string $name): bool
    {
        if (in_array($name, self::NON_SECRET_OPTIONS, true)) {
            return false;
        }
        return preg_match(self::SECRET_PATTERN, $name) === 1;
    }

    /**
     * @return bool
     */
    public static function canExport(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !class_exists(AP_Roles::class)) {
            return false;
        }

        return AP_Roles::userCan($userId, 'export', null, $db)
            && AP_Roles::userCan($userId, 'manage_options', null, $db);
    }

    /**
     * @return array{
     *   format: string,
     *   format_version: int,
     *   ap_version: string,
     *   generated_gmt: string,
     *   tables: array<string, array<int, array<string, mixed>>>,
     *   redacted: array<int, string>
     * }
     */
    public static function build(?AP_DB $db = null): array
    {
        if ($db === null && function_exists('ap_db')) {
            $db = ap_db();
        }

        if ($db === null) {
            throw new \RuntimeException('Database connection not available.');
        }

        $tablesData = [];
        $redactions = [];
        $dbTables = $db->tables();

        foreach (self::TABLES as $tableName) {
            if (!isset($dbTables[$tableName])) {
                continue;
            }

            try {
                $rows = $db->getResults(
                    'SELECT * FROM ' . $db->quoteIdentifier($db->table($tableName)),
                    [],
                    PDO::FETCH_ASSOC
                );
            } catch (\Throwable $e) {
                continue;
            }

            $filteredRows = [];
            foreach ($rows as $row) {
                if ($tableName === 'users') {
                    if (isset($row['user_pass'])) {
                        $row['user_pass'] = '';
                        $redactions[] = 'users.user_pass';
                    }
                    if (isset($row['user_activation_key'])) {
                        $row['user_activation_key'] = '';
                        $redactions[] = 'users.user_activation_key';
                    }
                    $filteredRows[] = $row;
                } elseif ($tableName === 'options') {
                    if (self::isSecretOption((string) ($row['option_name'] ?? ''))) {
                        $redactions[] = 'options:' . ($row['option_name'] ?? 'unknown');
                        continue;
                    }
                    $filteredRows[] = $row;
                } elseif (in_array($tableName, ['usermeta', 'postmeta', 'commentmeta'], true)) {
                    if (preg_match(self::SECRET_PATTERN, $row['meta_key'] ?? '')) {
                        $redactions[] = $tableName . ':' . ($row['meta_key'] ?? 'unknown');
                        continue;
                    }
                    $filteredRows[] = $row;
                } else {
                    $filteredRows[] = $row;
                }
            }

            $tablesData[$tableName] = $filteredRows;
        }

        $redactions = array_values(array_unique($redactions));
        sort($redactions);

        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'ap_version' => defined('AP_VERSION') ? AP_VERSION : '',
            'generated_gmt' => gmdate('Y-m-d\TH:i:s\Z'),
            'tables' => $tablesData,
            'redacted' => $redactions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function toJson(array $data): string
    {
        return json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    public static function filename(string $siteName, ?int $time = null): string
    {
        $slug = strtolower($siteName);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 40);
        if ($slug === '') {
            $slug = 'site';
        }

        return 'agorapress-export-' . $slug . '-' . gmdate('Ymd-His', $time ?? time()) . '.json';
    }
}
