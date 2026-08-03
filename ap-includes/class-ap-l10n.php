<?php

/**
 * AgoraPress internationalization (gettext) and RTL support.
 *
 * Pure-PHP MO catalog loading (no ext-gettext required for multi-domain
 * isolation). Procedural wrappers: {@see ap__()}, {@see __()}, etc.
 *
 * Language packs live under AP_LANG_DIR (ap-content/languages/):
 *   - default domain: {locale}.mo or agorapress-{locale}.mo
 *   - other domains:  {domain}-{locale}.mo
 *   - plugins/:       plugins/{domain}-{locale}.mo
 *   - themes/:        themes/{domain}-{locale}.mo
 *
 * Site locale is the WPLANG option (empty = en_US).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Gettext-style translations, locale, and text direction.
 */
class AP_L10n
{
    /** Default core text domain. */
    public const DOMAIN_DEFAULT = 'default';

    /** Alias accepted for core strings. */
    public const DOMAIN_AGORAPRESS = 'agorapress';

    /** Option name for site language (WordPress-compatible). */
    public const OPTION_WPLANG = 'WPLANG';

    /** Fallback locale when WPLANG is empty. */
    public const DEFAULT_LOCALE = 'en_US';

    /**
     * Loaded catalogs: domain => entry map.
     *
     * Each entry: string key => string (singular) or list<string> (plurals).
     *
     * @var array<string, array<string, string|list<string>>>
     */
    private static array $domains = [];

    /**
     * Plural-Forms expression per domain (default English dual form).
     *
     * @var array<string, array{nplurals: int, expression: string}>
     */
    private static array $plurals = [];

    /** Resolved locale for this request (null until determined). */
    private static ?string $locale = null;

    /** Whether default textdomain load was attempted. */
    private static bool $defaultLoaded = false;

    /** Override languages directory (tests). */
    private static ?string $langDirOverride = null;

    /**
     * Language codes that use right-to-left scripts.
     *
     * @var list<string>
     */
    private static array $rtlLanguages = [
        'ar', // Arabic
        'ary', // Moroccan Arabic
        'azb', // South Azerbaijani
        'ckb', // Central Kurdish
        'dv', // Divehi
        'fa', // Persian
        'he', // Hebrew
        'ps', // Pashto
        'skr', // Saraiki
        'ug', // Uyghur
        'ur', // Urdu
        'yi', // Yiddish
    ];

    // -------------------------------------------------------------------------
    // Locale
    // -------------------------------------------------------------------------

    /**
     * Determine the active locale (WPLANG option → default).
     *
     * Filter: `ap_locale` (string).
     */
    public static function determineLocale(?AP_DB $db = null): string
    {
        $locale = self::DEFAULT_LOCALE;

        if (class_exists('AP_Options', false)) {
            $stored = (string) AP_Options::get(self::OPTION_WPLANG, '', $db);
            $stored = self::sanitizeLocale($stored);
            if ($stored !== '') {
                $locale = $stored;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_locale', $locale);
            if (is_string($filtered) && $filtered !== '') {
                $sanitized = self::sanitizeLocale($filtered);
                if ($sanitized !== '') {
                    $locale = $sanitized;
                }
            }
        }

        return $locale;
    }

    /**
     * Get the current request locale (cached after first resolve).
     */
    public static function getLocale(?AP_DB $db = null): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        self::$locale = self::determineLocale($db);

        return self::$locale;
    }

    /**
     * Force locale for this request (tests / temporary switch).
     * Pass null to clear and re-resolve.
     */
    public static function setLocale(?string $locale): void
    {
        if ($locale === null) {
            self::$locale = null;

            return;
        }

        $sanitized = self::sanitizeLocale($locale);
        self::$locale = $sanitized !== '' ? $sanitized : self::DEFAULT_LOCALE;
    }

    /**
     * Normalize a locale string (e.g. en-us → en_US, ar → ar).
     */
    public static function sanitizeLocale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '') {
            return '';
        }

        // language or language_REGION (optional script: sr_RS@latin ignored → strip @…)
        if (str_contains($locale, '@')) {
            $locale = explode('@', $locale, 2)[0];
        }

        if (preg_match('/^([a-zA-Z]{2,3})(?:_([a-zA-Z]{2}|[0-9]{3}))?$/', $locale, $m) !== 1) {
            return '';
        }

        $lang = strtolower($m[1]);
        if (!isset($m[2]) || $m[2] === '') {
            return $lang;
        }

        $region = $m[2];
        if (ctype_digit($region)) {
            return $lang . '_' . $region;
        }

        return $lang . '_' . strtoupper($region);
    }

    /**
     * BCP 47 / HTML lang attribute form (en_US → en-US).
     */
    public static function localeToHtmlLang(string $locale = ''): string
    {
        if ($locale === '') {
            $locale = self::getLocale();
        }
        $locale = self::sanitizeLocale($locale);
        if ($locale === '') {
            $locale = self::DEFAULT_LOCALE;
        }

        return str_replace('_', '-', $locale);
    }

    /**
     * Open Graph og:locale form (en → en_US when possible).
     */
    public static function localeToOgLocale(string $locale = ''): string
    {
        if ($locale === '') {
            $locale = self::getLocale();
        }
        $locale = self::sanitizeLocale($locale);
        if ($locale === '') {
            return self::DEFAULT_LOCALE;
        }

        // Already language_REGION.
        if (str_contains($locale, '_')) {
            return $locale;
        }

        // Map bare language codes to common defaults.
        $map = [
            'en' => 'en_US',
            'ar' => 'ar_AR',
            'he' => 'he_IL',
            'fa' => 'fa_IR',
            'ur' => 'ur_PK',
            'de' => 'de_DE',
            'fr' => 'fr_FR',
            'es' => 'es_ES',
            'pt' => 'pt_BR',
            'it' => 'it_IT',
            'nl' => 'nl_NL',
            'pl' => 'pl_PL',
            'ru' => 'ru_RU',
            'ja' => 'ja_JP',
            'zh' => 'zh_CN',
            'ko' => 'ko_KR',
        ];

        return $map[$locale] ?? ($locale . '_' . strtoupper($locale));
    }

    /**
     * Primary language subtag (en_US → en).
     */
    public static function languageCode(string $locale = ''): string
    {
        if ($locale === '') {
            $locale = self::getLocale();
        }
        $locale = self::sanitizeLocale($locale);
        if ($locale === '') {
            return 'en';
        }
        $parts = explode('_', $locale, 2);

        return strtolower($parts[0]);
    }

    // -------------------------------------------------------------------------
    // RTL
    // -------------------------------------------------------------------------

    /**
     * Whether the given (or current) locale is right-to-left.
     *
     * Filter: `ap_is_rtl` (bool, locale).
     */
    public static function isRtl(string $locale = ''): bool
    {
        if ($locale === '') {
            $locale = self::getLocale();
        }
        $lang = self::languageCode($locale);
        $rtl = in_array($lang, self::$rtlLanguages, true);

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_is_rtl', $rtl, $locale);

            return (bool) $filtered;
        }

        return $rtl;
    }

    /**
     * Text direction: "rtl" or "ltr".
     */
    public static function textDirection(string $locale = ''): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    /**
     * Attributes for the root &lt;html&gt; element: lang + dir.
     *
     * Filter: `ap_language_attributes` (string attributes, doctype).
     *
     * @return string Space-separated attribute string without leading space.
     */
    public static function languageAttributes(string $doctype = 'html'): string
    {
        $lang = self::localeToHtmlLang();
        $dir = self::textDirection();
        $attrs = 'lang="' . self::escAttr($lang) . '" dir="' . self::escAttr($dir) . '"';

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_language_attributes', $attrs, $doctype);
            if (is_string($filtered) && $filtered !== '') {
                return $filtered;
            }
        }

        return $attrs;
    }

    // -------------------------------------------------------------------------
    // Paths
    // -------------------------------------------------------------------------

    /**
     * Absolute path to languages directory (no trailing slash).
     */
    public static function langDir(): string
    {
        if (self::$langDirOverride !== null && self::$langDirOverride !== '') {
            return rtrim(self::$langDirOverride, '/\\');
        }

        if (defined('AP_LANG_DIR')) {
            return rtrim((string) AP_LANG_DIR, '/\\');
        }

        if (defined('AP_CONTENT_DIR')) {
            return rtrim((string) AP_CONTENT_DIR, '/\\') . '/languages';
        }

        if (defined('AP_ABSPATH')) {
            return rtrim((string) AP_ABSPATH, '/\\') . '/ap-content/languages';
        }

        return dirname(__DIR__) . '/ap-content/languages';
    }

    /**
     * Override languages directory for tests. Pass null to clear.
     */
    public static function setLangDirOverride(?string $path): void
    {
        self::$langDirOverride = $path !== null && $path !== ''
            ? rtrim($path, '/\\')
            : null;
    }

    /**
     * Candidate MO paths for a domain + locale (first readable wins).
     *
     * @return list<string>
     */
    public static function moCandidates(string $domain, string $locale): array
    {
        $domain = self::normalizeDomain($domain);
        $locale = self::sanitizeLocale($locale);
        if ($locale === '') {
            return [];
        }

        $base = self::langDir();
        $candidates = [];

        if ($domain === self::DOMAIN_DEFAULT || $domain === self::DOMAIN_AGORAPRESS) {
            $candidates[] = $base . '/' . $locale . '.mo';
            $candidates[] = $base . '/agorapress-' . $locale . '.mo';
            $candidates[] = $base . '/default-' . $locale . '.mo';
            // Try language-only fallback (ar_SA → ar).
            $lang = self::languageCode($locale);
            if ($lang !== $locale) {
                $candidates[] = $base . '/' . $lang . '.mo';
                $candidates[] = $base . '/agorapress-' . $lang . '.mo';
            }
        } else {
            $candidates[] = $base . '/' . $domain . '-' . $locale . '.mo';
            $candidates[] = $base . '/plugins/' . $domain . '-' . $locale . '.mo';
            $candidates[] = $base . '/themes/' . $domain . '-' . $locale . '.mo';
            $lang = self::languageCode($locale);
            if ($lang !== $locale) {
                $candidates[] = $base . '/' . $domain . '-' . $lang . '.mo';
                $candidates[] = $base . '/plugins/' . $domain . '-' . $lang . '.mo';
                $candidates[] = $base . '/themes/' . $domain . '-' . $lang . '.mo';
            }
        }

        return $candidates;
    }

    // -------------------------------------------------------------------------
    // Load / unload
    // -------------------------------------------------------------------------

    /**
     * Load a .mo file into a domain. Merges with existing entries for the domain.
     *
     * @return bool True when the file was readable and parsed.
     */
    public static function loadTextdomain(string $domain, string $mofile): bool
    {
        $domain = self::normalizeDomain($domain);
        if ($domain === '' || $mofile === '' || !is_readable($mofile)) {
            return false;
        }

        $parsed = self::parseMoFile($mofile);
        if ($parsed === null) {
            return false;
        }

        if (!isset(self::$domains[$domain])) {
            self::$domains[$domain] = [];
        }

        foreach ($parsed['entries'] as $key => $value) {
            self::$domains[$domain][$key] = $value;
        }

        if ($parsed['plural'] !== null) {
            self::$plurals[$domain] = $parsed['plural'];
        } elseif (!isset(self::$plurals[$domain])) {
            self::$plurals[$domain] = [
                'nplurals' => 2,
                'expression' => 'n != 1',
            ];
        }

        if (function_exists('ap_do_action')) {
            ap_do_action('ap_load_textdomain', $domain, $mofile);
        }

        return true;
    }

    /**
     * Load domain for the current locale from the languages directory.
     */
    public static function loadDomainLocale(string $domain, string $locale = ''): bool
    {
        if ($locale === '') {
            $locale = self::getLocale();
        }

        foreach (self::moCandidates($domain, $locale) as $path) {
            if (self::loadTextdomain($domain, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load the default / agorapress textdomain for the active locale (once).
     */
    public static function loadDefaultTextdomain(): bool
    {
        if (self::$defaultLoaded) {
            return isset(self::$domains[self::DOMAIN_DEFAULT])
                || isset(self::$domains[self::DOMAIN_AGORAPRESS]);
        }
        self::$defaultLoaded = true;

        $locale = self::getLocale();
        if ($locale === self::DEFAULT_LOCALE || self::languageCode($locale) === 'en') {
            // English ships as source strings — no catalog required.
            return true;
        }

        $ok = self::loadDomainLocale(self::DOMAIN_DEFAULT, $locale);
        if (!$ok) {
            $ok = self::loadDomainLocale(self::DOMAIN_AGORAPRESS, $locale);
        }

        return $ok;
    }

    /**
     * Load a plugin text domain from languages dir and optional plugin path.
     */
    public static function loadPluginTextdomain(string $domain, string $pluginRelPath = ''): bool
    {
        $domain = self::normalizeDomain($domain);
        $locale = self::getLocale();
        if ($domain === '' || $locale === self::DEFAULT_LOCALE) {
            return false;
        }

        // Prefer site languages directory.
        if (self::loadDomainLocale($domain, $locale)) {
            return true;
        }

        if ($pluginRelPath === '') {
            return false;
        }

        $base = defined('AP_PLUGIN_DIR')
            ? rtrim((string) AP_PLUGIN_DIR, '/\\')
            : (defined('AP_CONTENT_DIR')
                ? rtrim((string) AP_CONTENT_DIR, '/\\') . '/plugins'
                : '');
        if ($base === '') {
            return false;
        }

        $dir = $base . '/' . ltrim(str_replace('\\', '/', $pluginRelPath), '/');
        $candidates = [
            $dir . '/' . $domain . '-' . $locale . '.mo',
            $dir . '/' . $locale . '.mo',
        ];
        $lang = self::languageCode($locale);
        if ($lang !== $locale) {
            $candidates[] = $dir . '/' . $domain . '-' . $lang . '.mo';
            $candidates[] = $dir . '/' . $lang . '.mo';
        }

        foreach ($candidates as $path) {
            if (self::loadTextdomain($domain, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Load a theme text domain.
     */
    public static function loadThemeTextdomain(string $domain, string $path = ''): bool
    {
        $domain = self::normalizeDomain($domain);
        $locale = self::getLocale();
        if ($domain === '' || $locale === self::DEFAULT_LOCALE) {
            return false;
        }

        if (self::loadDomainLocale($domain, $locale)) {
            return true;
        }

        if ($path === '') {
            return false;
        }

        $dir = rtrim(str_replace('\\', '/', $path), '/');
        $candidates = [
            $dir . '/' . $domain . '-' . $locale . '.mo',
            $dir . '/' . $locale . '.mo',
            $dir . '/languages/' . $domain . '-' . $locale . '.mo',
            $dir . '/languages/' . $locale . '.mo',
        ];
        $lang = self::languageCode($locale);
        if ($lang !== $locale) {
            $candidates[] = $dir . '/' . $domain . '-' . $lang . '.mo';
            $candidates[] = $dir . '/' . $lang . '.mo';
            $candidates[] = $dir . '/languages/' . $domain . '-' . $lang . '.mo';
            $candidates[] = $dir . '/languages/' . $lang . '.mo';
        }

        foreach ($candidates as $file) {
            if (self::loadTextdomain($domain, $file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unload a domain (or all domains when empty).
     */
    public static function unloadTextdomain(string $domain = ''): void
    {
        if ($domain === '') {
            self::$domains = [];
            self::$plurals = [];
            self::$defaultLoaded = false;

            return;
        }

        $domain = self::normalizeDomain($domain);
        unset(self::$domains[$domain], self::$plurals[$domain]);
        if ($domain === self::DOMAIN_DEFAULT || $domain === self::DOMAIN_AGORAPRESS) {
            self::$defaultLoaded = false;
        }
    }

    /**
     * Reset all l10n state (tests).
     */
    public static function reset(): void
    {
        self::$domains = [];
        self::$plurals = [];
        self::$locale = null;
        self::$defaultLoaded = false;
        self::$langDirOverride = null;
    }

    /**
     * Inject translations without a MO file (tests / dynamic packs).
     *
     * Keys are msgid (or "context\x04msgid"). Values are string or list of plurals.
     *
     * @param array<string, string|list<string>> $entries
     * @param array{nplurals?: int, expression?: string}|null $plural
     */
    public static function loadTranslations(
        string $domain,
        array $entries,
        ?array $plural = null
    ): void {
        $domain = self::normalizeDomain($domain);
        if (!isset(self::$domains[$domain])) {
            self::$domains[$domain] = [];
        }
        foreach ($entries as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value)) {
                self::$domains[$domain][$key] = $value;
            } elseif (is_array($value)) {
                $list = [];
                foreach ($value as $v) {
                    if (is_string($v)) {
                        $list[] = $v;
                    }
                }
                if ($list !== []) {
                    self::$domains[$domain][$key] = $list;
                }
            }
        }
        if ($plural !== null) {
            self::$plurals[$domain] = [
                'nplurals' => max(1, (int) ($plural['nplurals'] ?? 2)),
                'expression' => (string) ($plural['expression'] ?? 'n != 1'),
            ];
        } elseif (!isset(self::$plurals[$domain])) {
            self::$plurals[$domain] = [
                'nplurals' => 2,
                'expression' => 'n != 1',
            ];
        }
    }

    /**
     * Whether a domain has any loaded strings.
     */
    public static function isLoaded(string $domain): bool
    {
        $domain = self::normalizeDomain($domain);

        return isset(self::$domains[$domain]) && self::$domains[$domain] !== [];
    }

    // -------------------------------------------------------------------------
    // Translate
    // -------------------------------------------------------------------------

    /**
     * Translate a singular string.
     */
    public static function translate(string $text, string $domain = self::DOMAIN_DEFAULT): string
    {
        if ($text === '') {
            return '';
        }

        $domain = self::normalizeDomain($domain);
        $entry = self::lookup($text, $domain);
        if ($entry === null) {
            // Cross-domain fallback: default ↔ agorapress.
            if ($domain === self::DOMAIN_DEFAULT) {
                $entry = self::lookup($text, self::DOMAIN_AGORAPRESS);
            } elseif ($domain === self::DOMAIN_AGORAPRESS) {
                $entry = self::lookup($text, self::DOMAIN_DEFAULT);
            }
        }

        if ($entry === null) {
            $result = $text;
        } elseif (is_array($entry)) {
            $result = $entry[0] ?? $text;
        } else {
            $result = $entry;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_gettext', $result, $text, $domain);
            if (is_string($filtered)) {
                return $filtered;
            }
        }

        return $result;
    }

    /**
     * Translate with context (disambiguation).
     */
    public static function translateWithContext(
        string $text,
        string $context,
        string $domain = self::DOMAIN_DEFAULT
    ): string {
        if ($text === '') {
            return '';
        }

        $domain = self::normalizeDomain($domain);
        $key = $context . "\x04" . $text;
        $entry = self::lookup($key, $domain);
        if ($entry === null && $domain === self::DOMAIN_DEFAULT) {
            $entry = self::lookup($key, self::DOMAIN_AGORAPRESS);
        }

        if ($entry === null) {
            $result = $text;
        } elseif (is_array($entry)) {
            $result = $entry[0] ?? $text;
        } else {
            $result = $entry;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_gettext_with_context', $result, $text, $context, $domain);
            if (is_string($filtered)) {
                return $filtered;
            }
        }

        return $result;
    }

    /**
     * Translate a plural string.
     */
    public static function translatePlural(
        string $single,
        string $plural,
        int $number,
        string $domain = self::DOMAIN_DEFAULT
    ): string {
        $domain = self::normalizeDomain($domain);
        $key = $single . "\x00" . $plural;
        $entry = self::lookup($key, $domain);
        if ($entry === null) {
            $entry = self::lookup($single, $domain);
        }
        if ($entry === null && $domain === self::DOMAIN_DEFAULT) {
            $entry = self::lookup($key, self::DOMAIN_AGORAPRESS);
            if ($entry === null) {
                $entry = self::lookup($single, self::DOMAIN_AGORAPRESS);
            }
        }

        if (is_array($entry)) {
            $index = self::pluralIndex($number, $domain);
            $result = $entry[$index] ?? ($entry[0] ?? ($number === 1 ? $single : $plural));
        } elseif (is_string($entry)) {
            $result = $entry;
        } else {
            $result = $number === 1 ? $single : $plural;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_ngettext', $result, $single, $plural, $number, $domain);
            if (is_string($filtered)) {
                return $filtered;
            }
        }

        return $result;
    }

    /**
     * Translate plural with context.
     */
    public static function translatePluralWithContext(
        string $single,
        string $plural,
        int $number,
        string $context,
        string $domain = self::DOMAIN_DEFAULT
    ): string {
        $domain = self::normalizeDomain($domain);
        $key = $context . "\x04" . $single . "\x00" . $plural;
        $entry = self::lookup($key, $domain);
        if ($entry === null) {
            $entry = self::lookup($context . "\x04" . $single, $domain);
        }

        if (is_array($entry)) {
            $index = self::pluralIndex($number, $domain);
            $result = $entry[$index] ?? ($entry[0] ?? ($number === 1 ? $single : $plural));
        } elseif (is_string($entry)) {
            $result = $entry;
        } else {
            $result = $number === 1 ? $single : $plural;
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters(
                'ap_ngettext_with_context',
                $result,
                $single,
                $plural,
                $number,
                $context,
                $domain
            );
            if (is_string($filtered)) {
                return $filtered;
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Locale list (admin UI)
    // -------------------------------------------------------------------------

    /**
     * Installed language packs under the languages directory.
     *
     * @return array<string, string> locale => display label
     */
    public static function installedLanguages(): array
    {
        $found = ['' => 'English (United States) — default'];
        $dir = self::langDir();
        if (!is_dir($dir)) {
            return $found;
        }

        $files = @scandir($dir);
        if (!is_array($files)) {
            return $found;
        }

        foreach ($files as $file) {
            if (!is_string($file) || !str_ends_with($file, '.mo')) {
                continue;
            }
            $base = substr($file, 0, -3);
            // agorapress-ar_SA / default-he_IL / ar / en_GB
            if (str_starts_with($base, 'agorapress-')) {
                $locale = substr($base, strlen('agorapress-'));
            } elseif (str_starts_with($base, 'default-')) {
                $locale = substr($base, strlen('default-'));
            } elseif (preg_match('/^[a-z]{2,3}(?:_[A-Z]{2}|_[0-9]{3})?$/', $base) === 1) {
                $locale = $base;
            } else {
                continue;
            }

            $locale = self::sanitizeLocale($locale);
            if ($locale === '' || $locale === self::DEFAULT_LOCALE) {
                continue;
            }
            $found[$locale] = self::localeDisplayName($locale);
        }

        ksort($found);

        return $found;
    }

    /**
     * Common locales offered in the admin Site Language dropdown.
     *
     * @return array<string, string> locale => label
     */
    public static function availableLocales(): array
    {
        $list = [
            '' => 'English (United States) — default',
            'en_GB' => 'English (UK)',
            'ar' => 'العربية',
            'he_IL' => 'עברית',
            'fa_IR' => 'فارسی',
            'ur' => 'اردو',
            'de_DE' => 'Deutsch',
            'es_ES' => 'Español',
            'fr_FR' => 'Français',
            'it_IT' => 'Italiano',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'pt_BR' => 'Português (Brasil)',
            'pt_PT' => 'Português (Portugal)',
            'ru_RU' => 'Русский',
            'ja' => '日本語',
            'zh_CN' => '简体中文',
            'zh_TW' => '繁體中文',
            'ko_KR' => '한국어',
            'tr_TR' => 'Türkçe',
            'uk' => 'Українська',
            'sv_SE' => 'Svenska',
            'cs_CZ' => 'Čeština',
            'ro_RO' => 'Română',
            'hu_HU' => 'Magyar',
            'el' => 'Ελληνικά',
            'vi' => 'Tiếng Việt',
            'th' => 'ไทย',
            'id_ID' => 'Bahasa Indonesia',
            'hi_IN' => 'हिन्दी',
        ];

        // Merge any installed packs not in the curated list.
        foreach (self::installedLanguages() as $locale => $label) {
            if ($locale !== '' && !isset($list[$locale])) {
                $list[$locale] = $label;
            }
        }

        if (function_exists('ap_apply_filters')) {
            $filtered = ap_apply_filters('ap_available_locales', $list);
            if (is_array($filtered)) {
                $clean = [];
                foreach ($filtered as $k => $v) {
                    if (is_string($k) && is_string($v)) {
                        $clean[$k] = $v;
                    }
                }

                return $clean;
            }
        }

        return $list;
    }

    /**
     * Human-readable label for a locale code.
     */
    public static function localeDisplayName(string $locale): string
    {
        $all = [
            'en_US' => 'English (United States)',
            'en_GB' => 'English (UK)',
            'ar' => 'العربية',
            'ar_SA' => 'العربية (السعودية)',
            'he_IL' => 'עברית',
            'fa_IR' => 'فارسی',
            'ur' => 'اردو',
            'de_DE' => 'Deutsch',
            'es_ES' => 'Español',
            'fr_FR' => 'Français',
            'it_IT' => 'Italiano',
            'nl_NL' => 'Nederlands',
            'pl_PL' => 'Polski',
            'pt_BR' => 'Português (Brasil)',
            'pt_PT' => 'Português (Portugal)',
            'ru_RU' => 'Русский',
            'ja' => '日本語',
            'zh_CN' => '简体中文',
            'zh_TW' => '繁體中文',
            'ko_KR' => '한국어',
            'tr_TR' => 'Türkçe',
            'uk' => 'Українська',
            'sv_SE' => 'Svenska',
            'cs_CZ' => 'Čeština',
            'ro_RO' => 'Română',
            'hu_HU' => 'Magyar',
            'el' => 'Ελληνικά',
            'vi' => 'Tiếng Việt',
            'th' => 'ไทย',
            'id_ID' => 'Bahasa Indonesia',
            'hi_IN' => 'हिन्दी',
        ];

        $locale = self::sanitizeLocale($locale);

        return $all[$locale] ?? $locale;
    }

    /**
     * Write a minimal little-endian MO file (for tests / tooling).
     *
     * @param array<string, string|list<string>> $entries msgid => msgstr or plural list
     */
    public static function writeMoFile(string $path, array $entries, string $headers = ''): bool
    {
        // Header entry (empty msgid) carries metadata including Plural-Forms.
        if ($headers === '') {
            $headers = "Project-Id-Version: AgoraPress\n"
                . "MIME-Version: 1.0\n"
                . "Content-Type: text/plain; charset=UTF-8\n"
                . "Content-Transfer-Encoding: 8bit\n"
                . "Plural-Forms: nplurals=2; plural=(n != 1);\n";
        }

        /** @var list<array{0: string, 1: string}> $pairs */
        $pairs = [['', $headers]];
        foreach ($entries as $orig => $trans) {
            if (!is_string($orig)) {
                continue;
            }
            if (is_array($trans)) {
                $pairs[] = [$orig, implode("\x00", array_map('strval', $trans))];
            } elseif (is_string($trans)) {
                $pairs[] = [$orig, $trans];
            }
        }

        $n = count($pairs);
        // Header: 7 × uint32 = 28 bytes; then 2 tables of n × 8 bytes; then strings.
        $headerSize = 28;
        $tableSize = $n * 8;
        $origTableOffset = $headerSize;
        $transTableOffset = $headerSize + $tableSize;
        $stringsOffset = $headerSize + 2 * $tableSize;

        $origData = '';
        $transData = '';
        $origIndex = '';
        $transIndex = '';
        $oPos = $stringsOffset;
        $tPos = $stringsOffset; // recalculated after orig block

        $origBlobs = [];
        $transBlobs = [];
        foreach ($pairs as [$o, $t]) {
            $origBlobs[] = $o . "\x00";
            $transBlobs[] = $t . "\x00";
        }

        foreach ($origBlobs as $blob) {
            $len = strlen($blob) - 1; // exclude NUL for length field
            $origIndex .= pack('V2', $len, $oPos);
            $origData .= $blob;
            $oPos += strlen($blob);
        }

        $tPos = $oPos;
        foreach ($transBlobs as $blob) {
            $len = strlen($blob) - 1;
            $transIndex .= pack('V2', $len, $tPos);
            $transData .= $blob;
            $tPos += strlen($blob);
        }

        $header = pack(
            'V7',
            0x950412de, // magic LE
            0,          // revision
            $n,
            $origTableOffset,
            $transTableOffset,
            0,          // hash table size
            0           // hash table offset
        );

        $binary = $header . $origIndex . $transIndex . $origData . $transData;
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        return file_put_contents($path, $binary) !== false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return self::DOMAIN_DEFAULT;
        }

        return preg_replace('/[^a-z0-9_\-]/', '', $domain) ?? self::DOMAIN_DEFAULT;
    }

    /**
     * @return string|list<string>|null
     */
    private static function lookup(string $key, string $domain): string|array|null
    {
        if (!isset(self::$domains[$domain][$key])) {
            return null;
        }

        return self::$domains[$domain][$key];
    }

    private static function pluralIndex(int $number, string $domain): int
    {
        $n = abs($number);
        $spec = self::$plurals[$domain] ?? [
            'nplurals' => 2,
            'expression' => 'n != 1',
        ];
        $nplurals = max(1, (int) $spec['nplurals']);
        $expr = (string) $spec['expression'];
        $index = self::evalPlural($expr, $n);

        return max(0, min($nplurals - 1, $index));
    }

    /**
     * Evaluate a gettext plural expression for n (safe subset).
     */
    private static function evalPlural(string $expression, int $n): int
    {
        $expression = trim($expression);
        if ($expression === '' || $expression === '0') {
            return 0;
        }

        // Fast paths for common forms.
        if ($expression === 'n != 1' || $expression === '(n != 1)') {
            return $n !== 1 ? 1 : 0;
        }
        if ($expression === 'n > 1' || $expression === '(n > 1)') {
            return $n > 1 ? 1 : 0;
        }
        if ($expression === 'n != 0' || $expression === '(n != 0)') {
            return $n !== 0 ? 1 : 0;
        }

        // Allow only digits, n, spaces, and operators used in Plural-Forms.
        if (preg_match('/^[n0-9\s\?:\(\)<>=!&|%+\-]+$/', $expression) !== 1) {
            return $n === 1 ? 0 : 1;
        }

        // Replace bare n with integer (word boundary).
        $php = preg_replace('/\bn\b/', (string) $n, $expression);
        if (!is_string($php) || $php === '') {
            return $n === 1 ? 0 : 1;
        }

        try {
            // Expression is sanitized to a safe character set above.
            /** @var mixed $result */
            $result = eval('return (int) (' . $php . ');');
            if (is_int($result)) {
                return $result;
            }
            if (is_numeric($result)) {
                return (int) $result;
            }
        } catch (Throwable) {
            // Fall through.
        }

        return $n === 1 ? 0 : 1;
    }

    /**
     * Parse a GNU MO file.
     *
     * @return array{
     *   entries: array<string, string|list<string>>,
     *   plural: array{nplurals: int, expression: string}|null
     * }|null
     */
    private static function parseMoFile(string $path): ?array
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 28) {
            return null;
        }

        $magic = substr($data, 0, 4);
        if ($magic === "\xde\x12\x04\x95") {
            $unpack = 'V'; // little-endian
        } elseif ($magic === "\x95\x04\x12\xde") {
            $unpack = 'N'; // big-endian
        } else {
            return null;
        }

        $header = unpack($unpack . 'revision/' . $unpack . 'count/'
            . $unpack . 'origOffset/' . $unpack . 'transOffset', substr($data, 4, 16));
        if ($header === false) {
            return null;
        }

        $count = (int) $header['count'];
        $origOffset = (int) $header['origOffset'];
        $transOffset = (int) $header['transOffset'];
        if ($count < 0 || $count > 500000) {
            return null;
        }

        $entries = [];
        $plural = null;
        $len = strlen($data);

        for ($i = 0; $i < $count; $i++) {
            $oMeta = unpack(
                $unpack . 'length/' . $unpack . 'offset',
                substr($data, $origOffset + $i * 8, 8)
            );
            $tMeta = unpack(
                $unpack . 'length/' . $unpack . 'offset',
                substr($data, $transOffset + $i * 8, 8)
            );
            if ($oMeta === false || $tMeta === false) {
                continue;
            }

            $oLen = (int) $oMeta['length'];
            $oOff = (int) $oMeta['offset'];
            $tLen = (int) $tMeta['length'];
            $tOff = (int) $tMeta['offset'];

            if ($oOff < 0 || $tOff < 0 || $oOff + $oLen > $len || $tOff + $tLen > $len) {
                continue;
            }

            $original = $oLen > 0 ? substr($data, $oOff, $oLen) : '';
            $translation = $tLen > 0 ? substr($data, $tOff, $tLen) : '';

            // Header (empty msgid).
            if ($original === '') {
                $pluralPattern = '/Plural-Forms:\s*nplurals\s*=\s*(\d+)\s*;'
                    . '\s*plural\s*=\s*([^;]+);/i';
                if (preg_match($pluralPattern, $translation, $m) === 1) {
                    $plural = [
                        'nplurals' => max(1, (int) $m[1]),
                        'expression' => trim($m[2]),
                    ];
                }
                continue;
            }

            if (str_contains($translation, "\x00")) {
                $entries[$original] = explode("\x00", $translation);
            } else {
                $entries[$original] = $translation;
            }
        }

        return [
            'entries' => $entries,
            'plural' => $plural,
        ];
    }

    private static function escAttr(string $text): string
    {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
