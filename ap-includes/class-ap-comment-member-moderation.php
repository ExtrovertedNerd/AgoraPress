<?php

declare(strict_types=1);

/**
 * @package AgoraPress
 */

final class AP_Comment_Member_Moderation
{
    public const MODES = ['off', 'first_comment', 'all', 'links_heuristic'];
    public const DEFAULT_MODE = 'first_comment';
    public const OPTION = 'comment_moderation_members';
    public const DEFAULT_MAX_LINKS = 2;

    public static function mode(?AP_DB $db = null): string
    {
        $val = self::DEFAULT_MODE;
        if (class_exists('AP_Options')) {
            try {
                $val = AP_Options::get(self::OPTION, self::DEFAULT_MODE, $db);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $val = strtolower(trim((string) $val));
        return in_array($val, self::MODES, true) ? $val : self::DEFAULT_MODE;
    }

    public static function maxLinks(?AP_DB $db = null): int
    {
        $val = '2';
        if (class_exists('AP_Options')) {
            try {
                $val = AP_Options::get('comment_max_links', '2', $db);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $val = trim((string) $val);
        if (!is_numeric($val) || (int) $val < 1) {
            return self::DEFAULT_MAX_LINKS;
        }
        return (int) $val;
    }

    public static function linkCount(string $content): int
    {
        preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~i', $content, $matches);
        return count($matches[0]);
    }

    public static function hasModerationKeyword(string $content, ?AP_DB $db = null): bool
    {
        $keys = '';
        if (class_exists('AP_Options')) {
            try {
                $keys = AP_Options::get('moderation_keys', '', $db);
            } catch (\Throwable $e) {
                // ignore
            }
        }
        $keys = array_filter(array_map('trim', explode("\n", (string) $keys)));
        foreach ($keys as $key) {
            if ($key !== '' && mb_stripos($content, $key) !== false) {
                return true;
            }
        }
        return false;
    }

    public static function isTrusted(int $userId, ?AP_DB $db = null): bool
    {
        if (!class_exists('AP_Roles') || $userId <= 0) {
            return false;
        }
        return AP_Roles::userCan($userId, 'moderate_comments', null, $db)
            || AP_Roles::userCan($userId, 'edit_others_posts', null, $db);
    }

    public static function hasApprovedComment(int $userId, ?AP_DB $db = null): bool
    {
        if (function_exists('ap_db') && $db === null) {
            $db = ap_db();
        }
        if ($db === null) {
            return false;
        }
        $count = (int) $db->getVar(
            'SELECT COUNT(*) FROM ' . $db->table('comments') . ' WHERE user_id = ? AND comment_approved = ?',
            [$userId, '1']
        );
        return $count > 0;
    }

    public static function looksSpammy(string $content, ?AP_DB $db = null): bool
    {
        return self::linkCount($content) >= self::maxLinks($db)
            || self::hasModerationKeyword($content, $db);
    }

    public static function shouldHold(int $userId, string $content, ?AP_DB $db = null): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (self::isTrusted($userId, $db)) {
            return false;
        }

        $mode = self::mode($db);
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'all') {
            return true;
        }
        if ($mode === 'links_heuristic') {
            return self::looksSpammy($content, $db);
        }
        if ($mode === 'first_comment') {
            return !self::hasApprovedComment($userId, $db) || self::looksSpammy($content, $db);
        }

        return false;
    }
}
