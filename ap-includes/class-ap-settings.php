<?php

/**
 * AgoraPress Settings API.
 *
 * WordPress-inspired registration of option groups, sections, and fields so
 * core screens and (later) plugins can declare settings consistently.
 *
 * Typical flow:
 * 1. ap_register_setting( $group, $option, [ 'sanitize_callback' => … ] )
 * 2. ap_add_settings_section( … ) / ap_add_settings_field( … )
 * 3. Form: ap_settings_fields( $group ); ap_do_settings_sections( $page );
 * 4. On POST: AP_Settings::save( $group )
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Settings registry, form helpers, and sanitized option group saves.
 */
class AP_Settings
{
    /**
     * Registered options: group => name => args.
     *
     * @var array<string, array<string, array{
     *   type: string,
     *   default: mixed,
     *   sanitize_callback: ?callable,
     *   description: string
     * }>>
     */
    private static array $settings = [];

    /**
     * Sections: page => id => definition.
     *
     * @var array<string, array<string, array{
     *   id: string,
     *   title: string,
     *   callback: ?callable,
     *   page: string
     * }>>
     */
    private static array $sections = [];

    /**
     * Fields: page => section => id => definition.
     *
     * @var array<string, array<string, array<string, array{
     *   id: string,
     *   title: string,
     *   callback: ?callable,
     *   page: string,
     *   section: string,
     *   args: array<string, mixed>
     * }>>>
     */
    private static array $fields = [];

    /**
     * Validation / save messages.
     *
     * @var list<array{setting: string, code: string, message: string, type: string}>
     */
    private static array $errors = [];

    /** Whether core settings groups have been registered this request. */
    private static bool $coreRegistered = false;

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register an option with a settings group (for sanitize + save).
     *
     * @param array{
     *   type?: string,
     *   default?: mixed,
     *   sanitize_callback?: callable|null,
     *   description?: string
     * } $args
     */
    public static function registerSetting(string $optionGroup, string $optionName, array $args = []): void
    {
        $optionGroup = self::normalizeKey($optionGroup);
        $optionName = self::normalizeKey($optionName);
        if ($optionGroup === '' || $optionName === '') {
            return;
        }

        $sanitize = $args['sanitize_callback'] ?? null;
        if ($sanitize !== null && !is_callable($sanitize)) {
            $sanitize = null;
        }

        self::$settings[$optionGroup][$optionName] = [
            'type' => (string) ($args['type'] ?? 'string'),
            'default' => $args['default'] ?? false,
            'sanitize_callback' => $sanitize,
            'description' => (string) ($args['description'] ?? ''),
        ];
    }

    /**
     * Add a settings section to a page.
     *
     * @param callable|null $callback Optional intro HTML echoer (receives section array).
     */
    public static function addSection(
        string $id,
        string $title,
        ?callable $callback,
        string $page
    ): void {
        $id = self::normalizeKey($id);
        $page = self::normalizeKey($page);
        if ($id === '' || $page === '') {
            return;
        }

        if (!isset(self::$sections[$page])) {
            self::$sections[$page] = [];
        }

        self::$sections[$page][$id] = [
            'id' => $id,
            'title' => $title,
            'callback' => $callback,
            'page' => $page,
        ];
    }

    /**
     * Add a field under a section on a page.
     *
     * @param callable|null        $callback Echoes the control HTML.
     * @param array<string, mixed> $args     Passed to the callback as first argument.
     */
    public static function addField(
        string $id,
        string $title,
        ?callable $callback,
        string $page,
        string $section = 'default',
        array $args = []
    ): void {
        $id = self::normalizeKey($id);
        $page = self::normalizeKey($page);
        $section = self::normalizeKey($section) ?: 'default';
        if ($id === '' || $page === '') {
            return;
        }

        if (!isset(self::$fields[$page])) {
            self::$fields[$page] = [];
        }
        if (!isset(self::$fields[$page][$section])) {
            self::$fields[$page][$section] = [];
        }

        $args['label_for'] = $args['label_for'] ?? $id;
        $args['id'] = $id;

        self::$fields[$page][$section][$id] = [
            'id' => $id,
            'title' => $title,
            'callback' => $callback,
            'page' => $page,
            'section' => $section,
            'args' => $args,
        ];
    }

    // -------------------------------------------------------------------------
    // Form output
    // -------------------------------------------------------------------------

    /**
     * Hidden fields + nonce for a settings form (option_page + _ap_nonce).
     *
     * @return string HTML (also echoes when $echo is true).
     */
    public static function settingsFields(string $optionGroup, bool $echo = true): string
    {
        $optionGroup = self::normalizeKey($optionGroup);
        $action = 'ap_settings_' . $optionGroup;
        $html = '<input type="hidden" name="option_page" value="'
            . ap_esc_attr($optionGroup) . '">' . "\n";
        if (function_exists('ap_nonce_field')) {
            $html .= ap_nonce_field($action, '_ap_nonce', false);
        }
        if ($echo) {
            echo $html;
        }

        return $html;
    }

    /**
     * Render all sections (and their fields) for a settings page.
     */
    public static function doSections(string $page): void
    {
        $page = self::normalizeKey($page);
        $sections = self::$sections[$page] ?? [];
        if ($sections === []) {
            // Still allow orphan fields under "default".
            if (isset(self::$fields[$page]['default'])) {
                echo '<table class="ap-form-table" role="presentation">' . "\n";
                self::doFields($page, 'default');
                echo '</table>' . "\n";
            }

            return;
        }

        foreach ($sections as $section) {
            if ($section['title'] !== '') {
                echo '<h2 class="ap-settings-section-title">'
                    . ap_esc_html($section['title']) . '</h2>' . "\n";
            }
            if (is_callable($section['callback'])) {
                call_user_func($section['callback'], $section);
            }
            echo '<table class="ap-form-table" role="presentation">' . "\n";
            self::doFields($page, $section['id']);
            echo '</table>' . "\n";
        }
    }

    /**
     * Render fields for one section.
     */
    public static function doFields(string $page, string $section): void
    {
        $page = self::normalizeKey($page);
        $section = self::normalizeKey($section) ?: 'default';
        $fields = self::$fields[$page][$section] ?? [];
        foreach ($fields as $field) {
            $id = (string) ($field['args']['label_for'] ?? $field['id']);
            echo '<tr class="ap-settings-row">' . "\n";
            echo '<th scope="row">';
            if ($id !== '') {
                echo '<label for="' . ap_esc_attr($id) . '">'
                    . ap_esc_html($field['title']) . '</label>';
            } else {
                echo ap_esc_html($field['title']);
            }
            echo '</th>' . "\n";
            echo '<td>';
            if (is_callable($field['callback'])) {
                call_user_func($field['callback'], $field['args']);
            }
            echo '</td>' . "\n";
            echo '</tr>' . "\n";
        }
    }

    /**
     * Submit button for settings forms.
     */
    public static function submitButton(string $text = 'Save Changes', string $name = 'ap_settings_submit'): void
    {
        echo '<p class="ap-form-actions">'
            . '<button type="submit" name="' . ap_esc_attr($name) . '" value="1" class="button button-primary">'
            . ap_esc_html($text)
            . '</button></p>' . "\n";
    }

    // -------------------------------------------------------------------------
    // Save
    // -------------------------------------------------------------------------

    /**
     * Whether the current POST is a settings save for the given group.
     */
    public static function isSaveRequest(string $optionGroup): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return false;
        }
        $posted = (string) ($_POST['option_page'] ?? '');
        if ($posted !== self::normalizeKey($optionGroup)) {
            return false;
        }

        return isset($_POST['ap_settings_submit']) || isset($_POST['_ap_nonce']);
    }

    /**
     * Verify nonce for a settings group.
     */
    public static function verifyNonce(string $optionGroup, ?int $userId = null): bool
    {
        $optionGroup = self::normalizeKey($optionGroup);
        $nonce = (string) ($_POST['_ap_nonce'] ?? '');
        if ($nonce === '' || !function_exists('ap_check_nonce')) {
            return false;
        }
        if ($userId === null && function_exists('ap_get_current_user_id')) {
            $uid = ap_get_current_user_id();
            $userId = $uid > 0 ? $uid : null;
        }

        return ap_check_nonce($nonce, 'ap_settings_' . $optionGroup, $userId);
    }

    /**
     * Sanitize and persist all registered options for a group from input array.
     *
     * @param array<string, mixed>|null $input Defaults to $_POST.
     *
     * @return bool True when every registered option write succeeded (or none registered).
     */
    public static function save(string $optionGroup, ?array $input = null, ?AP_DB $db = null): bool
    {
        $optionGroup = self::normalizeKey($optionGroup);
        $registered = self::$settings[$optionGroup] ?? [];
        if ($registered === []) {
            return true;
        }

        $input = $input ?? $_POST;
        $ok = true;

        foreach ($registered as $name => $args) {
            // Unchecked checkboxes are absent from POST — use empty/false default.
            $raw = array_key_exists($name, $input) ? $input[$name] : null;
            $value = $raw;
            if (is_callable($args['sanitize_callback'])) {
                try {
                    $value = call_user_func($args['sanitize_callback'], $raw);
                } catch (Throwable $e) {
                    self::addError($name, 'sanitize_failed', $e->getMessage(), 'error');
                    $ok = false;
                    continue;
                }
            } else {
                $value = self::defaultSanitize($raw, $args['type']);
            }

            if (!AP_Options::update($name, $value, $db)) {
                self::addError($name, 'update_failed', 'Could not save setting: ' . $name, 'error');
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * Handle a full POST cycle: nonce check + save. Queues admin notices on failure.
     *
     * @return bool|null null = not a save request; true/false = save result
     */
    public static function maybeSave(
        string $optionGroup,
        ?int $userId = null,
        ?AP_DB $db = null
    ): ?bool {
        if (!self::isSaveRequest($optionGroup)) {
            return null;
        }
        if (!self::verifyNonce($optionGroup, $userId)) {
            self::addError($optionGroup, 'nonce', 'Security check failed. Please try again.', 'error');

            return false;
        }

        return self::save($optionGroup, null, $db);
    }

    // -------------------------------------------------------------------------
    // Errors / notices
    // -------------------------------------------------------------------------

    /**
     * Queue a settings error or success message.
     *
     * @param string $type error|success|warning|info
     */
    public static function addError(
        string $setting,
        string $code,
        string $message,
        string $type = 'error'
    ): void {
        $allowed = ['error', 'success', 'warning', 'info'];
        if (!in_array($type, $allowed, true)) {
            $type = 'error';
        }
        self::$errors[] = [
            'setting' => $setting,
            'code' => $code,
            'message' => $message,
            'type' => $type,
        ];
    }

    /**
     * @return list<array{setting: string, code: string, message: string, type: string}>
     */
    public static function getErrors(string $setting = ''): array
    {
        if ($setting === '') {
            return self::$errors;
        }

        return array_values(array_filter(
            self::$errors,
            static fn (array $e): bool => $e['setting'] === $setting
        ));
    }

    /**
     * Render queued settings errors as admin notices (and clear them).
     */
    public static function renderErrors(string $setting = ''): string
    {
        $list = self::getErrors($setting);
        if ($list === []) {
            return '';
        }

        // Clear rendered ones.
        if ($setting === '') {
            self::$errors = [];
        } else {
            self::$errors = array_values(array_filter(
                self::$errors,
                static fn (array $e): bool => $e['setting'] !== $setting
            ));
        }

        $html = '';
        foreach ($list as $err) {
            $type = ap_esc_attr($err['type']);
            $msg = ap_esc_html($err['message']);
            $html .= '<div class="ap-notice ap-notice--' . $type . '">' . $msg . '</div>' . "\n";
        }

        return $html;
    }

    /**
     * Push Settings API errors into AP_Admin notices (when available).
     */
    public static function flushErrorsToAdmin(): void
    {
        if (!class_exists('AP_Admin', false)) {
            return;
        }
        foreach (self::$errors as $err) {
            AP_Admin::addNotice($err['message'], $err['type']);
        }
        self::$errors = [];
    }

    // -------------------------------------------------------------------------
    // Introspection (tests / plugins)
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getRegisteredSettings(string $optionGroup = ''): array
    {
        if ($optionGroup === '') {
            return self::$settings;
        }

        return self::$settings[self::normalizeKey($optionGroup)] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSections(string $page): array
    {
        return self::$sections[self::normalizeKey($page)] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFields(string $page, string $section = ''): array
    {
        $page = self::normalizeKey($page);
        if ($section === '') {
            $out = [];
            foreach (self::$fields[$page] ?? [] as $secFields) {
                foreach ($secFields as $id => $field) {
                    $out[$id] = $field;
                }
            }

            return $out;
        }

        return self::$fields[$page][self::normalizeKey($section)] ?? [];
    }

    /**
     * Reset registry (unit tests).
     */
    public static function flush(): void
    {
        self::$settings = [];
        self::$sections = [];
        self::$fields = [];
        self::$errors = [];
        self::$coreRegistered = false;
    }

    /**
     * Register core option groups, sections, and fields (idempotent per request).
     */
    public static function registerCore(): void
    {
        if (self::$coreRegistered) {
            return;
        }
        self::$coreRegistered = true;

        // --- General ---
        self::registerSetting('general', 'blogname', [
            'type' => 'string',
            'default' => 'AgoraPress',
            'sanitize_callback' => static function (mixed $v): string {
                return function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field((string) ($v ?? ''))
                    : trim(strip_tags((string) ($v ?? '')));
            },
        ]);
        self::registerSetting('general', 'blogdescription', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                return function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field((string) ($v ?? ''))
                    : trim(strip_tags((string) ($v ?? '')));
            },
        ]);
        self::registerSetting('general', 'siteurl', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitizeUrlOption'],
        ]);
        self::registerSetting('general', 'home', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => [self::class, 'sanitizeUrlOption'],
        ]);
        self::registerSetting('general', 'admin_email', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                $email = trim((string) ($v ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return (string) AP_Options::get('admin_email', '');
                }

                return strtolower($email);
            },
        ]);
        self::registerSetting('general', 'users_can_register', [
            'type' => 'boolean',
            'default' => '0',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('general', 'require_email_verification', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('general', 'registration_captcha', [
            'type' => 'string',
            'default' => 'off',
            'sanitize_callback' => static function (mixed $v): string {
                $mode = strtolower(trim((string) ($v ?? 'off')));
                if ($mode === '' || $mode === '0' || $mode === 'false' || $mode === 'no' || $mode === 'disabled') {
                    return 'off';
                }
                if ($mode === '1' || $mode === 'true' || $mode === 'yes' || $mode === 'on') {
                    return 'math';
                }
                // Built-in modes only; plugins may still filter at verify time for custom strings.
                if (in_array($mode, ['off', 'math'], true)) {
                    return $mode;
                }

                return 'off';
            },
        ]);
        self::registerSetting('general', 'default_role', [
            'type' => 'string',
            'default' => 'subscriber',
            'sanitize_callback' => static function (mixed $v): string {
                $role = strtolower(trim((string) ($v ?? 'subscriber')));
                $role = preg_replace('/[^a-z0-9_\-]/', '', $role) ?? 'subscriber';
                if ($role === '' || $role === 'administrator') {
                    return 'subscriber';
                }
                if (class_exists('AP_Roles', false) && !AP_Roles::roleExists($role)) {
                    return 'subscriber';
                }

                return $role;
            },
        ]);
        self::registerSetting('general', 'timezone_string', [
            'type' => 'string',
            'default' => 'UTC',
            'sanitize_callback' => static function (mixed $v): string {
                $tz = trim((string) ($v ?? 'UTC'));
                if ($tz === '') {
                    return 'UTC';
                }
                try {
                    new DateTimeZone($tz);

                    return $tz;
                } catch (Throwable) {
                    return 'UTC';
                }
            },
        ]);
        self::registerSetting('general', 'WPLANG', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                $locale = trim((string) ($v ?? ''));
                if ($locale === '') {
                    return '';
                }
                if (class_exists('AP_L10n', false)) {
                    return AP_L10n::sanitizeLocale($locale);
                }
                $locale = str_replace('-', '_', $locale);
                if (preg_match('/^[a-zA-Z]{2,3}(?:_[a-zA-Z]{2}|_[0-9]{3})?$/', $locale) !== 1) {
                    return '';
                }

                return $locale;
            },
        ]);
        self::registerSetting('general', 'date_format', [
            'type' => 'string',
            'default' => 'Y-m-d',
            'sanitize_callback' => static function (mixed $v): string {
                $f = function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field((string) ($v ?? 'Y-m-d'))
                    : trim((string) ($v ?? 'Y-m-d'));

                return $f !== '' ? $f : 'Y-m-d';
            },
        ]);
        self::registerSetting('general', 'time_format', [
            'type' => 'string',
            'default' => 'H:i',
            'sanitize_callback' => static function (mixed $v): string {
                $f = function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field((string) ($v ?? 'H:i'))
                    : trim((string) ($v ?? 'H:i'));

                return $f !== '' ? $f : 'H:i';
            },
        ]);
        self::registerSetting('general', 'start_of_week', [
            'type' => 'integer',
            'default' => '1',
            'sanitize_callback' => static function (mixed $v): string {
                $n = (int) ($v ?? 1);

                return (string) max(0, min(6, $n));
            },
        ]);

        // --- Modules ---
        foreach (['ap_module_static_pages', 'ap_module_blog', 'ap_module_forum'] as $mod) {
            self::registerSetting('modules', $mod, [
                'type' => 'boolean',
                'default' => '1',
                'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
            ]);
        }

        // --- Writing ---
        self::registerSetting('writing', 'default_category', [
            'type' => 'integer',
            'default' => '0',
            'sanitize_callback' => static function (mixed $v): string {
                return (string) max(0, (int) ($v ?? 0));
            },
        ]);
        self::registerSetting('writing', 'use_smilies', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('writing', 'default_comment_status', [
            'type' => 'string',
            'default' => 'open',
            'sanitize_callback' => static function (mixed $v): string {
                return (string) ($v ?? '') === 'closed' ? 'closed' : 'open';
            },
        ]);

        // --- Reading (multi-field update handled by AP_Options::updateReadingSettings) ---
        foreach (
            [
                'show_on_front',
                'page_on_front',
                'page_for_posts',
                'posts_per_page',
                'posts_per_rss',
                'rss_use_excerpt',
            ] as $opt
        ) {
            self::registerSetting('reading', $opt, [
                'type' => 'string',
                'default' => '',
            ]);
        }

        // --- Discussion ---
        self::registerSetting('discussion', 'default_comment_status', [
            'type' => 'string',
            'default' => 'open',
            'sanitize_callback' => static function (mixed $v): string {
                return (string) ($v ?? '') === 'closed' ? 'closed' : 'open';
            },
        ]);
        self::registerSetting('discussion', 'require_name_email', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'comment_moderation', [
            'type' => 'boolean',
            'default' => '0',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'comment_registration', [
            'type' => 'boolean',
            'default' => '0',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'close_comments_for_old_posts', [
            'type' => 'boolean',
            'default' => '0',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'close_comments_days_old', [
            'type' => 'integer',
            'default' => '14',
            'sanitize_callback' => static function (mixed $v): string {
                return (string) max(0, min(3650, (int) ($v ?? 14)));
            },
        ]);
        self::registerSetting('discussion', 'thread_comments', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'thread_comments_depth', [
            'type' => 'integer',
            'default' => '5',
            'sanitize_callback' => static function (mixed $v): string {
                return (string) max(1, min(10, (int) ($v ?? 5)));
            },
        ]);
        self::registerSetting('discussion', 'show_avatars', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('discussion', 'avatar_default', [
            'type' => 'string',
            'default' => 'mystery',
            'sanitize_callback' => static function (mixed $v): string {
                $d = strtolower(trim((string) ($v ?? 'mystery')));
                $allowed = [
                    'mystery', 'mm', 'blank', 'identicon', 'mp', 'retro',
                    'robohash', 'wavatar', 'monsterid',
                ];

                return in_array($d, $allowed, true) ? $d : 'mystery';
            },
        ]);
        self::registerSetting('discussion', 'avatar_rating', [
            'type' => 'string',
            'default' => 'g',
            'sanitize_callback' => static function (mixed $v): string {
                $r = strtolower(trim((string) ($v ?? 'g')));

                return in_array($r, ['g', 'pg', 'r', 'x'], true) ? $r : 'g';
            },
        ]);

        // --- Media ---
        foreach (
            [
                'thumbnail_size_w' => 150,
                'thumbnail_size_h' => 150,
                'medium_size_w' => 300,
                'medium_size_h' => 300,
                'large_size_w' => 1024,
                'large_size_h' => 1024,
            ] as $opt => $default
        ) {
            self::registerSetting('media', $opt, [
                'type' => 'integer',
                'default' => (string) $default,
                'sanitize_callback' => static function (mixed $v) use ($default): string {
                    $n = (int) ($v ?? $default);

                    return (string) max(0, min(10000, $n));
                },
            ]);
        }
        self::registerSetting('media', 'thumbnail_crop', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);
        self::registerSetting('media', 'max_image_display_width', [
            'type' => 'integer',
            'default' => '1200',
            'sanitize_callback' => static function (mixed $v): string {
                $n = (int) ($v ?? 1200);

                return (string) max(0, min(10000, $n));
            },
        ]);
        self::registerSetting('media', 'uploads_use_yearmonth_folders', [
            'type' => 'boolean',
            'default' => '1',
            'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
        ]);

        // --- Permalinks ---
        self::registerSetting('permalink', 'permalink_structure', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                if (class_exists('AP_Rewrite', false)) {
                    return AP_Rewrite::normalizeStructure((string) ($v ?? ''));
                }

                return trim((string) ($v ?? ''));
            },
        ]);
        self::registerSetting('permalink', 'category_base', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                $base = trim((string) ($v ?? ''), '/');
                if ($base === '') {
                    return '';
                }
                if (class_exists('AP_Rewrite', false) && method_exists('AP_Rewrite', 'sanitizeSlug')) {
                    // sanitizeSlug may be private — fall back.
                }
                $base = strtolower($base);
                $base = preg_replace('/[^a-z0-9\/_\-]/', '', $base) ?? '';

                return trim($base, '/');
            },
        ]);
        self::registerSetting('permalink', 'tag_base', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static function (mixed $v): string {
                $base = trim((string) ($v ?? ''), '/');
                if ($base === '') {
                    return '';
                }
                $base = strtolower($base);
                $base = preg_replace('/[^a-z0-9\/_\-]/', '', $base) ?? '';

                return trim($base, '/');
            },
        ]);

        // --- Forums ---
        self::registerSetting('forums', 'forum_topics_per_page', [
            'type' => 'integer',
            'default' => 20,
            'sanitize_callback' => static fn (mixed $v): int => max(1, min(100, (int) $v)),
        ]);
        self::registerSetting('forums', 'forum_posts_per_page', [
            'type' => 'integer',
            'default' => 15,
            'sanitize_callback' => static fn (mixed $v): int => max(1, min(100, (int) $v)),
        ]);
        foreach (
            [
                'forum_allow_guest_viewing' => '1',
                'forum_allow_guest_posting' => '0',
                'forum_private_messaging_enabled' => '1',
                'forum_attachments_enabled' => '1',
                'forum_posts_require_approval' => '0',
                'forum_search_enabled' => '1',
                'forum_online_enabled' => '1',
                'forum_unread_tracking_enabled' => '1',
                'forum_signatures_enabled' => '1',
            ] as $opt => $default
        ) {
            self::registerSetting('forums', $opt, [
                'type' => 'boolean',
                'default' => $default,
                'sanitize_callback' => [self::class, 'sanitizeCheckbox'],
            ]);
        }
        self::registerSetting('forums', 'forum_attachment_max_size', [
            'type' => 'integer',
            'default' => 2097152,
            'sanitize_callback' => static fn (mixed $v): int => max(0, (int) $v),
        ]);
        self::registerSetting('forums', 'forum_attachment_allowed_types', [
            'type' => 'string',
            'default' => 'jpg,jpeg,png,gif,webp,pdf,txt,zip',
            'sanitize_callback' => static function (mixed $v): string {
                $raw = strtolower(trim((string) ($v ?? '')));
                $parts = preg_split('/[\s,]+/', $raw) ?: [];
                $clean = [];
                foreach ($parts as $p) {
                    $p = preg_replace('/[^a-z0-9]/', '', $p) ?? '';
                    if ($p !== '') {
                        $clean[] = $p;
                    }
                }

                return implode(',', array_unique($clean));
            },
        ]);
        self::registerSetting('forums', 'forum_flood_interval', [
            'type' => 'integer',
            'default' => 30,
            'sanitize_callback' => static fn (mixed $v): int => max(0, min(3600, (int) $v)),
        ]);
        self::registerSetting('forums', 'forum_spam_max_links', [
            'type' => 'integer',
            'default' => 5,
            'sanitize_callback' => static fn (mixed $v): int => max(0, min(100, (int) $v)),
        ]);
        self::registerSetting('forums', 'forum_spam_blacklist', [
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => static fn (mixed $v): string => str_replace("\0", '', (string) ($v ?? '')),
        ]);
    }

    // -------------------------------------------------------------------------
    // Shared sanitizers
    // -------------------------------------------------------------------------

    /**
     * Checkbox: present and truthy → "1", else "0".
     */
    public static function sanitizeCheckbox(mixed $value): string
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'on' || $value === 'yes') {
            return '1';
        }

        return '0';
    }

    /**
     * Absolute http(s) URL or empty; strips trailing slash consistency via rtrim.
     */
    public static function sanitizeUrlOption(mixed $value): string
    {
        $url = trim((string) ($value ?? ''));
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        $filtered = filter_var($url, FILTER_SANITIZE_URL);
        if (!is_string($filtered) || $filtered === '') {
            return '';
        }

        return rtrim($filtered, '/');
    }

    /**
     * @return mixed
     */
    private static function defaultSanitize(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => self::sanitizeCheckbox($value),
            'integer' => (string) (int) ($value ?? 0),
            'array' => is_array($value) ? $value : [],
            default => is_string($value)
                ? (function_exists('ap_sanitize_text_field')
                    ? ap_sanitize_text_field($value)
                    : trim(strip_tags($value)))
                : (string) ($value ?? ''),
        };
    }

    private static function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        // Allow option names with dots rarely; stick to WP-like [a-z0-9_\-].
        $key = preg_replace('/[^a-zA-Z0-9_\-]/', '', $key) ?? '';

        return $key;
    }
}
