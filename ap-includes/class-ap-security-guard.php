<?php

declare(strict_types=1);

/**
 * @package AgoraPress
 */

final class AP_Security_Guard
{
    public static function isSafeLocalPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        $forms = [$path];
        $current = $path;
        for ($i = 0; $i < 3; $i++) {
            $decoded = rawurldecode($current);
            if ($decoded === $current) {
                break;
            }
            $forms[] = $decoded;
            $current = $decoded;
        }

        foreach ($forms as $form) {
            $len = strlen($form);
            for ($j = 0; $j < $len; $j++) {
                $ord = ord($form[$j]);
                if ($ord < 32 || $ord === 127 || $form[$j] === '\\') {
                    return false;
                }
            }
            if ($form[0] !== '/' || ($len > 1 && $form[1] === '/')) {
                return false;
            }
            $parsed = parse_url($form);
            if (is_array($parsed) && (isset($parsed['host']) || isset($parsed['scheme']))) {
                return false;
            }
        }

        return true;
    }

    public static function headers(): array
    {
        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ];

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_security_headers', $defaults);
            if (is_array($filtered)) {
                $defaults = $filtered;
            }
        }

        $result = [];
        foreach ($defaults as $key => $value) {
            if (is_string($key) && is_string($value) && $value !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public static function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $existing = [];
        foreach (headers_list() as $h) {
            $parts = explode(':', $h, 2);
            if (isset($parts[0])) {
                $existing[strtolower(trim($parts[0]))] = true;
            }
        }

        $headers = self::headers();
        foreach ($headers as $name => $value) {
            $cleanName = str_replace(["\r", "\n"], '', $name);
            $cleanValue = str_replace(["\r", "\n"], '', $value);

            if (!isset($existing[strtolower($cleanName)])) {
                header("{$cleanName}: {$cleanValue}");
            }
        }
    }

    public static function canSeeHiddenForum(int $userId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !class_exists('AP_Roles')) {
            return false;
        }

        return AP_Roles::userCan($userId, 'manage_forums', null, $db)
            || AP_Roles::userCan($userId, 'moderate_forums', null, $db)
            || AP_Roles::userCan($userId, 'manage_options', null, $db);
    }
}
