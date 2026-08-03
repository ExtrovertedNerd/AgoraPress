<?php

/**
 * AgoraPress avatars — local upload + Gravatar fallback.
 *
 * Resolution order when avatars are enabled (`show_avatars`):
 * 1. Local attachment stored in usermeta `ap_avatar` (user ID only)
 * 2. Gravatar for the user's email (or comment/guest email)
 * 3. Configured default (mystery person SVG data URI, blank, or Gravatar d=)
 *
 * Options (Discussion settings will surface these later):
 * - show_avatars (1/0, default 1)
 * - avatar_default (mystery|blank|identicon|mp|retro|robohash|wavatar|monsterid)
 * - avatar_rating (g|pg|r|x, default g)
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Avatar URL / HTML helpers and local upload management.
 */
class AP_Avatar
{
    /** Usermeta key: attachment post ID for a custom avatar. */
    public const META_AVATAR = 'ap_avatar';

    /** Option: whether to show avatars site-wide. */
    public const OPTION_SHOW = 'show_avatars';

    /** Option: default style when no local avatar / Gravatar. */
    public const OPTION_DEFAULT = 'avatar_default';

    /** Option: Gravatar content rating. */
    public const OPTION_RATING = 'avatar_rating';

    /** Default pixel size (WordPress parity). */
    public const DEFAULT_SIZE = 96;

    /** Max allowed size for generated URLs / img width. */
    public const MAX_SIZE = 512;

    /** Max local avatar upload size (2 MiB). */
    public const MAX_UPLOAD_BYTES = 2097152;

    /**
     * Allowed image extensions for local avatar uploads.
     *
     * @var list<string>
     */
    private static array $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /**
     * Whether avatars are enabled site-wide.
     */
    public static function isEnabled(?AP_DB $db = null): bool
    {
        $raw = self::option(self::OPTION_SHOW, '1', $db);

        return !in_array(strtolower(trim((string) $raw)), ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Default avatar slug (mystery, blank, identicon, …).
     */
    public static function getDefault(?AP_DB $db = null): string
    {
        $d = strtolower(trim((string) self::option(self::OPTION_DEFAULT, 'mystery', $db)));
        $allowed = [
            'mystery',
            'mm',
            'mysteryman',
            'blank',
            'identicon',
            'mp',
            'retro',
            'robohash',
            'wavatar',
            'monsterid',
            'gravatar_default',
        ];
        if (!in_array($d, $allowed, true)) {
            return 'mystery';
        }
        if (in_array($d, ['mm', 'mysteryman'], true)) {
            return 'mystery';
        }

        return $d;
    }

    /**
     * Gravatar rating code (g|pg|r|x).
     */
    public static function getRating(?AP_DB $db = null): string
    {
        $r = strtolower(trim((string) self::option(self::OPTION_RATING, 'g', $db)));

        return in_array($r, ['g', 'pg', 'r', 'x'], true) ? $r : 'g';
    }

    /**
     * Local avatar attachment ID for a user, or 0.
     */
    public static function getLocalAttachmentId(int $userId, ?AP_DB $db = null): int
    {
        if ($userId < 1 || !class_exists('AP_User', false)) {
            return 0;
        }

        $raw = AP_User::getMeta($userId, self::META_AVATAR, $db);
        if ($raw === null || $raw === '') {
            return 0;
        }

        return max(0, (int) $raw);
    }

    /**
     * Store a local avatar attachment ID (does not delete previous file).
     */
    public static function setLocalAttachmentId(int $userId, int $attachmentId, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || $attachmentId < 1 || !class_exists('AP_User', false)) {
            return false;
        }

        return AP_User::updateMeta($userId, self::META_AVATAR, (string) $attachmentId, $db);
    }

    /**
     * Remove local avatar meta and optionally delete the attachment file.
     */
    public static function deleteLocal(int $userId, bool $deleteFile = true, ?AP_DB $db = null): bool
    {
        if ($userId < 1 || !class_exists('AP_User', false)) {
            return false;
        }

        $attachmentId = self::getLocalAttachmentId($userId, $db);
        AP_User::deleteMeta($userId, self::META_AVATAR, $db);

        if ($deleteFile && $attachmentId > 0 && class_exists('AP_Media', false)) {
            AP_Media::deleteAttachment($attachmentId, $db);
        }

        return true;
    }

    /**
     * Handle a local avatar upload for a user (replaces any previous local avatar).
     *
     * $file is a $_FILES-style array. Uses AP_Media::handleUpload with test_mode
     * when the file is not a real HTTP upload (unit tests).
     *
     * @param array<string, mixed> $file
     *
     * @return array{ok: bool, id: int, url: string, error: string}
     */
    public static function upload(int $userId, array $file, ?AP_DB $db = null): array
    {
        $fail = static fn (string $error): array => [
            'ok' => false,
            'id' => 0,
            'url' => '',
            'error' => $error,
        ];

        if ($userId < 1) {
            return $fail('Invalid user.');
        }
        if (!class_exists('AP_Media', false) || !class_exists('AP_User', false)) {
            return $fail('Media layer is not available.');
        }

        $user = AP_User::getById($userId, $db);
        if ($user === null) {
            return $fail('User not found.');
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return $fail('No file was uploaded.');
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            return $fail('Upload failed.');
        }

        $origName = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmpName === '' || $origName === '') {
            return $fail('No file was uploaded.');
        }

        if ($size < 1) {
            $detected = @filesize($tmpName);
            $size = is_int($detected) ? $detected : 0;
        }
        if ($size > self::MAX_UPLOAD_BYTES) {
            return $fail(
                'Avatar exceeds the maximum size of '
                . self::formatBytes(self::MAX_UPLOAD_BYTES) . '.'
            );
        }

        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allowedExt, true)) {
            return $fail('Avatar must be a JPG, PNG, GIF, or WebP image.');
        }

        // Reject non-image content even if the extension is allowed.
        if (class_exists('AP_Media', false)) {
            $check = AP_Media::checkFileType($origName, $tmpName);
            if (!$check['ok'] || !str_starts_with((string) ($check['type'] ?? ''), 'image/')) {
                return $fail(
                    $check['error'] !== ''
                        ? (string) $check['error']
                        : 'Avatar must be a valid image file.'
                );
            }
        }

        $testMode = empty($file['tmp_name']) || !is_uploaded_file($tmpName);
        // Real browser uploads are not test_mode; unit tests pass plain temp files.
        if (is_uploaded_file($tmpName)) {
            $testMode = false;
        } else {
            $testMode = true;
        }

        $result = AP_Media::handleUpload($file, [
            'post_author' => $userId,
            'post_title' => 'Avatar for ' . ($user->user_login !== '' ? $user->user_login : 'user ' . $userId),
            'alt_text' => $user->display_name !== '' ? $user->display_name : $user->user_login,
            'test_mode' => $testMode,
        ], $db);

        if (!$result['ok'] || $result['id'] < 1) {
            return $fail($result['error'] !== '' ? $result['error'] : 'Could not save avatar.');
        }

        // Replace previous local avatar (delete old attachment after new is stored).
        $previous = self::getLocalAttachmentId($userId, $db);
        self::setLocalAttachmentId($userId, $result['id'], $db);
        if ($previous > 0 && $previous !== $result['id'] && class_exists('AP_Media', false)) {
            AP_Media::deleteAttachment($previous, $db);
        }

        $url = (string) ($result['url'] ?? '');
        if ($url === '' && class_exists('AP_Media', false)) {
            $url = AP_Media::getAttachmentUrl($result['id'], $db);
        }

        return [
            'ok' => true,
            'id' => $result['id'],
            'url' => $url,
            'error' => '',
        ];
    }

    /**
     * Resolve avatar data for a user ID, email string, AP_User, or comment-like object.
     *
     * @param int|string|AP_User|object $idOrEmail
     * @param array{
     *   size?: int,
     *   default?: string,
     *   force_default?: bool,
     *   rating?: string,
     *   scheme?: string,
     *   alt?: string,
     *   class?: string|list<string>,
     *   extra_attr?: string,
     *   force_display?: bool
     * } $args
     *
     * @return array{
     *   found: bool,
     *   url: string,
     *   size: int,
     *   width: int,
     *   height: int,
     *   alt: string,
     *   class: string,
     *   extra_attr: string,
     *   source: string
     * }
     */
    public static function getData(
        int|string|object $idOrEmail,
        int $size = self::DEFAULT_SIZE,
        array $args = [],
        ?AP_DB $db = null
    ): array {
        $size = self::clampSize(isset($args['size']) ? (int) $args['size'] : $size);
        $forceDisplay = !empty($args['force_display']);
        $forceDefault = !empty($args['force_default']);

        $empty = [
            'found' => false,
            'url' => '',
            'size' => $size,
            'width' => $size,
            'height' => $size,
            'alt' => (string) ($args['alt'] ?? ''),
            'class' => self::normalizeClass($args['class'] ?? 'avatar'),
            'extra_attr' => (string) ($args['extra_attr'] ?? ''),
            'source' => 'none',
        ];

        if (!$forceDisplay && !self::isEnabled($db)) {
            return $empty;
        }

        $resolved = self::resolveIdentity($idOrEmail, $db);
        $userId = $resolved['user_id'];
        $email = $resolved['email'];
        $display = $resolved['display'];

        if (($args['alt'] ?? '') === '' && $display !== '') {
            $empty['alt'] = $display;
            $args['alt'] = $display;
        }

        $defaultSlug = isset($args['default']) && is_string($args['default']) && $args['default'] !== ''
            ? strtolower(trim($args['default']))
            : self::getDefault($db);
        if (in_array($defaultSlug, ['mm', 'mysteryman'], true)) {
            $defaultSlug = 'mystery';
        }

        // 1) Local custom avatar.
        if (!$forceDefault && $userId > 0) {
            $attachmentId = self::getLocalAttachmentId($userId, $db);
            if ($attachmentId > 0 && class_exists('AP_Media', false)) {
                $url = AP_Media::getAttachmentUrl($attachmentId, $db);
                if ($url !== '') {
                    return [
                        'found' => true,
                        'url' => $url,
                        'size' => $size,
                        'width' => $size,
                        'height' => $size,
                        'alt' => (string) ($args['alt'] ?? $display),
                        'class' => self::normalizeClass($args['class'] ?? 'avatar'),
                        'extra_attr' => (string) ($args['extra_attr'] ?? ''),
                        'source' => 'local',
                    ];
                }
            }
        }

        // 2) Gravatar (when we have an email).
        if (!$forceDefault && $email !== '' && $defaultSlug !== 'blank') {
            $url = self::gravatarUrl($email, $size, $defaultSlug, self::getRating($db), $args);
            return [
                'found' => true,
                'url' => $url,
                'size' => $size,
                'width' => $size,
                'height' => $size,
                'alt' => (string) ($args['alt'] ?? $display),
                'class' => self::normalizeClass($args['class'] ?? 'avatar'),
                'extra_attr' => (string) ($args['extra_attr'] ?? ''),
                'source' => 'gravatar',
            ];
        }

        // 3) Defaults without Gravatar request (mystery SVG or transparent pixel).
        if ($defaultSlug === 'blank') {
            $url = self::blankDataUri();
            $source = 'blank';
        } else {
            // Offline-friendly mystery person; still used when force_default or no email.
            $url = self::mysteryDataUri($size);
            $source = 'default';
            // When force_default with a Gravatar-style default and we have email, prefer Gravatar d=.
            if (
                $forceDefault
                && $email !== ''
                && in_array($defaultSlug, ['identicon', 'mp', 'retro', 'robohash', 'wavatar', 'monsterid', 'gravatar_default'], true)
            ) {
                $url = self::gravatarUrl($email, $size, $defaultSlug, self::getRating($db), $args);
                $source = 'gravatar';
            }
        }

        return [
            'found' => true,
            'url' => $url,
            'size' => $size,
            'width' => $size,
            'height' => $size,
            'alt' => (string) ($args['alt'] ?? $display),
            'class' => self::normalizeClass($args['class'] ?? 'avatar'),
            'extra_attr' => (string) ($args['extra_attr'] ?? ''),
            'source' => $source,
        ];
    }

    /**
     * Avatar image URL (empty when avatars disabled).
     *
     * @param int|string|object $idOrEmail
     * @param array<string, mixed> $args
     */
    public static function getUrl(
        int|string|object $idOrEmail,
        int $size = self::DEFAULT_SIZE,
        array $args = [],
        ?AP_DB $db = null
    ): string {
        $data = self::getData($idOrEmail, $size, $args, $db);

        return $data['found'] ? $data['url'] : '';
    }

    /**
     * Escaped &lt;img&gt; markup for an avatar, or empty string when disabled.
     *
     * @param int|string|object $idOrEmail
     * @param array<string, mixed> $args
     */
    public static function getHtml(
        int|string|object $idOrEmail,
        int $size = self::DEFAULT_SIZE,
        array $args = [],
        ?AP_DB $db = null
    ): string {
        $data = self::getData($idOrEmail, $size, $args, $db);
        if (!$data['found'] || $data['url'] === '') {
            return '';
        }

        $class = $data['class'];
        if ($class === '') {
            $class = 'avatar';
        }
        // Ensure size class for styling hooks.
        if (!str_contains($class, 'avatar-' . $data['size'])) {
            $class .= ' avatar-' . $data['size'];
        }
        if (!str_contains($class, 'photo')) {
            $class .= ' photo';
        }

        $alt = $data['alt'];
        $url = $data['url'];
        $w = (int) $data['width'];
        $h = (int) $data['height'];

        $escUrl = function_exists('ap_esc_url') ? ap_esc_url($url) : htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Data URIs are rejected by some URL escapers — keep them intact.
        if (str_starts_with($url, 'data:')) {
            $escUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $escAlt = function_exists('ap_esc_attr') ? ap_esc_attr($alt) : htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escClass = function_exists('ap_esc_attr') ? ap_esc_attr(trim($class)) : htmlspecialchars(trim($class), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = '<img src="' . $escUrl . '" alt="' . $escAlt . '" class="' . $escClass . '"'
            . ' height="' . $h . '" width="' . $w . '" loading="lazy" decoding="async"';

        $extra = trim($data['extra_attr']);
        if ($extra !== '') {
            // Callers must pass safe attribute strings (admin-controlled / trusted).
            $html .= ' ' . $extra;
        }

        $html .= ' />';

        return $html;
    }

    /**
     * Gravatar MD5 hash for an email (empty email → empty hash).
     */
    public static function gravatarHash(string $email): string
    {
        $email = strtolower(trim($email));
        if ($email === '' || !str_contains($email, '@')) {
            return '';
        }

        return md5($email);
    }

    /**
     * Build a Gravatar URL.
     *
     * @param array<string, mixed> $args scheme override via 'scheme'
     */
    public static function gravatarUrl(
        string $email,
        int $size = self::DEFAULT_SIZE,
        string $default = 'mystery',
        string $rating = 'g',
        array $args = []
    ): string {
        $size = self::clampSize($size);
        $hash = self::gravatarHash($email);
        if ($hash === '') {
            // Gravatar still accepts a zero hash for forced defaults.
            $hash = str_repeat('0', 32);
        }

        $d = self::mapDefaultForGravatar($default);
        $rating = in_array(strtolower($rating), ['g', 'pg', 'r', 'x'], true)
            ? strtolower($rating)
            : 'g';

        $scheme = isset($args['scheme']) && is_string($args['scheme']) && $args['scheme'] !== ''
            ? strtolower($args['scheme'])
            : 'https';
        if (!in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $query = http_build_query([
            's' => $size,
            'd' => $d,
            'r' => $rating,
        ], '', '&', PHP_QUERY_RFC3986);

        return $scheme . '://secure.gravatar.com/avatar/' . $hash . '?' . $query;
    }

    /**
     * Inline SVG mystery-person data URI (no external request).
     */
    public static function mysteryDataUri(int $size = self::DEFAULT_SIZE): string
    {
        // Simple neutral avatar silhouette; scales via width/height attributes.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" role="img" aria-hidden="true">'
            . '<rect width="96" height="96" fill="#c3c4c7"/>'
            . '<circle cx="48" cy="36" r="18" fill="#8c8f94"/>'
            . '<path d="M16 86c4-20 18-30 32-30s28 10 32 30" fill="#8c8f94"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * 1×1 transparent GIF data URI.
     */
    public static function blankDataUri(): string
    {
        // GIF89a 1x1 transparent.
        return 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
    }

    /**
     * Allowed avatar upload extensions.
     *
     * @return list<string>
     */
    public static function allowedExtensions(): array
    {
        return self::$allowedExt;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @param int|string|object $idOrEmail
     *
     * @return array{user_id: int, email: string, display: string}
     */
    private static function resolveIdentity(int|string|object $idOrEmail, ?AP_DB $db): array
    {
        $userId = 0;
        $email = '';
        $display = '';

        if ($idOrEmail instanceof AP_User) {
            $userId = $idOrEmail->ID;
            $email = $idOrEmail->user_email;
            $display = $idOrEmail->display_name !== ''
                ? $idOrEmail->display_name
                : $idOrEmail->user_login;

            return ['user_id' => $userId, 'email' => $email, 'display' => $display];
        }

        if (is_object($idOrEmail)) {
            // Comment-like: user_id + comment_author_email + comment_author.
            if (isset($idOrEmail->user_id) && (int) $idOrEmail->user_id > 0) {
                $userId = (int) $idOrEmail->user_id;
            } elseif (isset($idOrEmail->ID) && (int) $idOrEmail->ID > 0 && isset($idOrEmail->user_email)) {
                $userId = (int) $idOrEmail->ID;
            }
            if (isset($idOrEmail->comment_author_email)) {
                $email = (string) $idOrEmail->comment_author_email;
            } elseif (isset($idOrEmail->user_email)) {
                $email = (string) $idOrEmail->user_email;
            }
            if (isset($idOrEmail->comment_author)) {
                $display = (string) $idOrEmail->comment_author;
            } elseif (isset($idOrEmail->display_name)) {
                $display = (string) $idOrEmail->display_name;
            }
        } elseif (is_int($idOrEmail) || (is_string($idOrEmail) && ctype_digit($idOrEmail))) {
            $userId = (int) $idOrEmail;
        } elseif (is_string($idOrEmail) && str_contains($idOrEmail, '@')) {
            $email = trim($idOrEmail);
        }

        if ($userId > 0 && class_exists('AP_User', false)) {
            $user = AP_User::getById($userId, $db);
            if ($user !== null) {
                if ($email === '') {
                    $email = $user->user_email;
                }
                if ($display === '') {
                    $display = $user->display_name !== '' ? $user->display_name : $user->user_login;
                }
            }
        }

        return [
            'user_id' => $userId,
            'email' => strtolower(trim($email)),
            'display' => $display,
        ];
    }

    private static function mapDefaultForGravatar(string $default): string
    {
        return match (strtolower($default)) {
            'mystery', 'mm', 'mysteryman' => 'mp',
            'gravatar_default' => '',
            'blank' => 'blank',
            'identicon' => 'identicon',
            'mp' => 'mp',
            'retro' => 'retro',
            'robohash' => 'robohash',
            'wavatar' => 'wavatar',
            'monsterid' => 'monsterid',
            default => 'mp',
        };
    }

    private static function clampSize(int $size): int
    {
        if ($size < 1) {
            $size = self::DEFAULT_SIZE;
        }

        return min(self::MAX_SIZE, max(1, $size));
    }

    /**
     * @param string|list<string> $class
     */
    private static function normalizeClass(string|array $class): string
    {
        if (is_array($class)) {
            $parts = [];
            foreach ($class as $c) {
                if (is_string($c) && $c !== '') {
                    $parts[] = $c;
                }
            }
            $class = implode(' ', $parts);
        }

        return trim(preg_replace('/\s+/', ' ', $class) ?? '');
    }

    private static function option(string $name, mixed $default, ?AP_DB $db): mixed
    {
        if (function_exists('ap_get_option')) {
            return ap_get_option($name, $default, $db);
        }
        if (class_exists('AP_Options', false)) {
            return AP_Options::get($name, $default, $db);
        }

        return $default;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return (int) round($bytes / 1024) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
