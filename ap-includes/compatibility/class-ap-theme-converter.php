<?php

/**
 * Classic WordPress theme conversion / compatibility analysis helper.
 *
 * Scans a theme directory (or installed slug), reports classic vs block,
 * style.css headers, screenshots, functions.php, hierarchy templates, and
 * common WP symbols that the compatibility layer covers or leaves unshimmmed.
 * Optional dry-run “conversion notes” for migrating themes toward native AP APIs.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Analyze and advise on classic WP themes for AgoraPress.
 */
class AP_Theme_Converter
{
    /** Template files commonly expected in classic themes. */
    private const COMMON_TEMPLATES = [
        'index.php',
        'style.css',
        'functions.php',
        'header.php',
        'footer.php',
        'sidebar.php',
        'single.php',
        'page.php',
        'archive.php',
        'search.php',
        '404.php',
        'home.php',
        'front-page.php',
        'comments.php',
        'screenshot.png',
        'screenshot.jpg',
    ];

    /**
     * WP symbols the compatibility layer provides (subset for reporting).
     *
     * @var list<string>
     */
    private const SHIMMED_SYMBOLS = [
        'add_action',
        'add_filter',
        'apply_filters',
        'do_action',
        'get_header',
        'get_footer',
        'get_sidebar',
        'get_template_part',
        'have_posts',
        'the_post',
        'the_title',
        'the_content',
        'the_excerpt',
        'the_permalink',
        'body_class',
        'post_class',
        'is_home',
        'is_single',
        'is_page',
        'is_archive',
        'is_search',
        'is_404',
        'wp_enqueue_style',
        'wp_enqueue_script',
        'wp_head',
        'wp_footer',
        'get_stylesheet_directory',
        'get_template_directory',
        'get_stylesheet_uri',
        'bloginfo',
        'home_url',
        'register_nav_menus',
        'register_sidebar',
        'add_theme_support',
        'esc_html',
        'esc_attr',
        'esc_url',
        'get_option',
        'language_attributes',
    ];

    /**
     * Symbols often used by classic themes that are not fully shimmed.
     *
     * @var list<string>
     */
    private const UNSHIMMED_HINTS = [
        'get_template_directory_uri', // shimmed; kept for completeness in scans
        'wp_get_theme',
        'get_theme_mod',
        'set_theme_mod',
        'add_image_size',
        'the_custom_logo',
        'has_custom_logo',
        'get_custom_logo',
        'wp_body_open',
        'block_template_part',
        'wp_is_block_theme',
        'register_block_style',
        'register_block_pattern',
        'gutenberg',
        'add_editor_style',
        'wp_link_pages',
        'comments_template',
        'paginate_links',
        'previous_posts_link',
        'next_posts_link',
        'the_posts_pagination',
        'get_post_meta',
        'update_post_meta',
        'WP_Query',
        'query_posts',
        'wp_reset_postdata',
        'get_categories',
        'wp_list_categories',
        'the_category',
        'the_tags',
        'get_avatar',
        'wp_mail',
    ];

    /**
     * Analyze a filesystem path to a theme directory.
     *
     * @return array{
     *   path: string,
     *   slug: string,
     *   exists: bool,
     *   classic: bool,
     *   block: bool,
     *   supported: bool,
     *   headers: array<string, string>,
     *   files: array<string, bool>,
     *   screenshot: string,
     *   functions_php: bool,
     *   php_files: list<string>,
     *   shimmed_used: list<string>,
     *   unshimmed_used: list<string>,
     *   score: int,
     *   notes: list<string>,
     *   limitations: list<string>
     * }
     */
    public static function analyzePath(string $path): array
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $slug = basename($path);
        $result = self::emptyReport($path, $slug);

        if ($path === '' || !is_dir($path)) {
            $result['notes'][] = 'Path does not exist or is not a directory.';

            return $result;
        }

        $result['exists'] = true;

        $styleCss = $path . '/style.css';
        if (is_readable($styleCss) && class_exists('AP_Theme', false)) {
            $result['headers'] = AP_Theme::parseStyleCss($styleCss);
        } elseif (is_readable($styleCss)) {
            $result['headers'] = self::parseStyleCssFallback($styleCss);
        }

        $result['block'] = self::detectBlockAtPath($path);
        $result['classic'] = !$result['block']
            && is_readable($styleCss)
            && trim((string) ($result['headers']['Theme Name'] ?? '')) !== '';

        foreach (self::COMMON_TEMPLATES as $file) {
            $result['files'][$file] = is_file($path . '/' . $file);
        }

        $result['functions_php'] = is_file($path . '/functions.php');
        foreach (['screenshot.png', 'screenshot.jpg', 'screenshot.jpeg', 'screenshot.webp', 'screenshot.gif'] as $shot) {
            if (is_readable($path . '/' . $shot)) {
                $result['screenshot'] = $shot;
                break;
            }
        }

        $result['php_files'] = self::listPhpFiles($path);
        $sources = self::readThemeSources($path, $result['php_files']);
        $result['shimmed_used'] = self::findSymbols($sources, self::SHIMMED_SYMBOLS);
        // Exclude symbols that are actually shimmed from the unshimmed list.
        $unshimmedCandidates = array_values(array_diff(self::UNSHIMMED_HINTS, self::SHIMMED_SYMBOLS));
        // get_template_directory_uri is shimmed — remove from unshimmed hints for accuracy.
        $result['unshimmed_used'] = self::findSymbols($sources, $unshimmedCandidates);

        $result['supported'] = $result['classic'] && !$result['block'];
        $result['score'] = self::score($result);
        $result['notes'] = self::buildNotes($result);
        $result['limitations'] = self::buildLimitations($result);

        return $result;
    }

    /**
     * Analyze an installed theme by slug (under themes root).
     *
     * @return array<string, mixed>
     */
    public static function analyzeSlug(string $slug): array
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '' || !class_exists('AP_Theme', false)) {
            return self::emptyReport('', $slug);
        }

        return self::analyzePath(AP_Theme::themesRoot() . '/' . $slug);
    }

    /**
     * Human-readable report from an analysis array.
     *
     * @param array<string, mixed> $report
     */
    public static function formatReport(array $report): string
    {
        $lines = [];
        $lines[] = 'AgoraPress — Classic WP Theme Compatibility Report';
        $lines[] = str_repeat('=', 52);
        $lines[] = 'Path: ' . (string) ($report['path'] ?? '');
        $lines[] = 'Slug: ' . (string) ($report['slug'] ?? '');
        $lines[] = 'Exists: ' . (!empty($report['exists']) ? 'yes' : 'no');
        $lines[] = 'Classic PHP theme: ' . (!empty($report['classic']) ? 'yes' : 'no');
        $lines[] = 'Block / FSE theme: ' . (!empty($report['block']) ? 'yes' : 'no');
        $lines[] = 'Supported by compat layer: ' . (!empty($report['supported']) ? 'yes' : 'no');
        $lines[] = 'Compatibility score: ' . (string) (int) ($report['score'] ?? 0) . '/100';
        $lines[] = '';

        $headers = is_array($report['headers'] ?? null) ? $report['headers'] : [];
        if ($headers !== []) {
            $lines[] = 'style.css headers:';
            foreach ($headers as $k => $v) {
                if (is_string($k) && is_string($v) && $v !== '') {
                    $lines[] = '  ' . $k . ': ' . $v;
                }
            }
            $lines[] = '';
        }

        $lines[] = 'Key files:';
        $files = is_array($report['files'] ?? null) ? $report['files'] : [];
        foreach ($files as $file => $present) {
            $lines[] = '  [' . ($present ? 'x' : ' ') . '] ' . $file;
        }
        $lines[] = '';

        $shimmed = is_array($report['shimmed_used'] ?? null) ? $report['shimmed_used'] : [];
        $lines[] = 'Shimmed WP symbols used (' . count($shimmed) . '):';
        $lines[] = $shimmed !== [] ? '  ' . implode(', ', $shimmed) : '  (none detected)';
        $lines[] = '';

        $unshimmed = is_array($report['unshimmed_used'] ?? null) ? $report['unshimmed_used'] : [];
        $lines[] = 'Potentially unshimmed symbols used (' . count($unshimmed) . '):';
        $lines[] = $unshimmed !== [] ? '  ' . implode(', ', $unshimmed) : '  (none detected)';
        $lines[] = '';

        $notes = is_array($report['notes'] ?? null) ? $report['notes'] : [];
        if ($notes !== []) {
            $lines[] = 'Notes:';
            foreach ($notes as $n) {
                $lines[] = '  - ' . $n;
            }
            $lines[] = '';
        }

        $limits = is_array($report['limitations'] ?? null) ? $report['limitations'] : [];
        if ($limits !== []) {
            $lines[] = 'Limitations:';
            foreach ($limits as $n) {
                $lines[] = '  - ' . $n;
            }
            $lines[] = '';
        }

        $lines[] = 'Conversion tips:';
        foreach (self::conversionTips($report) as $tip) {
            $lines[] = '  - ' . $tip;
        }
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Dry-run conversion suggestions (does not modify files).
     *
     * @param array<string, mixed>|null $report Precomputed analysis, or null to analyze $path.
     *
     * @return list<string>
     */
    public static function conversionTips(?array $report = null, string $path = ''): array
    {
        if ($report === null) {
            $report = self::analyzePath($path);
        }

        $tips = [];
        if (!empty($report['block'])) {
            $tips[] = 'This is a block/FSE theme. Full block themes are out of scope; use a classic PHP theme or rebuild with pure PHP templates.';

            return $tips;
        }

        if (empty($report['classic'])) {
            $tips[] = 'Add a style.css with a Theme Name header and an index.php for classic hierarchy support.';
        }

        $tips[] = 'Upload the theme into ap-content/themes/{slug}/. style.css headers and screenshots are read by AP_Theme.';
        $tips[] = 'Compatibility shims load automatically for classic non-Agora themes (mode=auto). Force with AP_Theme_Compat::setMode(slug, "on").';
        $tips[] = 'Prefer calling ap_* APIs over time (ap_add_action, ap_the_title, …); bare WP names work via the shim layer.';
        $tips[] = 'Hook names wp_enqueue_scripts / after_setup_theme / wp_head / wp_footer map to ap_* equivalents.';
        $tips[] = 'Ensure index.php exists (parent themes). Child themes need a Template: parent-slug header.';

        $unshimmed = is_array($report['unshimmed_used'] ?? null) ? $report['unshimmed_used'] : [];
        if ($unshimmed !== []) {
            $tips[] = 'Review unshimmed calls (' . implode(', ', array_slice($unshimmed, 0, 8))
                . (count($unshimmed) > 8 ? ', …' : '')
                . ') — replace with AgoraPress APIs or provide thin wrappers in functions.php.';
        }

        if (empty($report['functions_php'])) {
            $tips[] = 'No functions.php found — optional for static PHP themes; add one for menus, sidebars, and enqueue.';
        }

        $tips[] = 'Block templates (*.html under templates/), theme.json, and Gutenberg features will not run.';

        return $tips;
    }

    /**
     * CLI entry: parse argv and print report. Returns process exit code.
     *
     * @param list<string> $argv
     */
    public static function runCli(array $argv): int
    {
        $args = array_slice($argv, 1);
        $path = '';
        $json = false;
        $help = false;

        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $help = true;
            } elseif ($arg === '--json') {
                $json = true;
            } elseif (str_starts_with($arg, '--path=')) {
                $path = substr($arg, 7);
            } elseif ($arg !== '' && !str_starts_with($arg, '-')) {
                $path = $arg;
            }
        }

        if ($help || $path === '') {
            $out = <<<TXT
AgoraPress classic theme conversion helper

Usage:
  php ap-includes/compatibility/cli-convert.php <theme-path> [--json]
  php ap-includes/compatibility/cli-convert.php --path=/path/to/theme

Options:
  --json   Emit machine-readable JSON analysis
  --help   Show this help

Analyzes a classic WordPress theme directory for AgoraPress compatibility.
Does not modify files (report / dry-run only). Block/FSE themes are reported
as out of scope for the initial compatibility layer.

TXT;
            echo $out;

            return $help ? 0 : 1;
        }

        $report = self::analyzePath($path);
        if ($json) {
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo self::formatReport($report);
        }

        if (empty($report['exists'])) {
            return 2;
        }
        if (!empty($report['block'])) {
            return 3;
        }
        if (empty($report['supported'])) {
            return 4;
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private static function emptyReport(string $path, string $slug): array
    {
        return [
            'path' => $path,
            'slug' => $slug,
            'exists' => false,
            'classic' => false,
            'block' => false,
            'supported' => false,
            'headers' => [],
            'files' => [],
            'screenshot' => '',
            'functions_php' => false,
            'php_files' => [],
            'shimmed_used' => [],
            'unshimmed_used' => [],
            'score' => 0,
            'notes' => [],
            'limitations' => [],
        ];
    }

    private static function detectBlockAtPath(string $path): bool
    {
        if (is_readable($path . '/theme.json')) {
            return true;
        }
        $templatesDir = $path . '/templates';
        if (!is_dir($templatesDir)) {
            return false;
        }
        $entries = @scandir($templatesDir);
        if (!is_array($entries)) {
            return false;
        }
        foreach ($entries as $entry) {
            if (str_ends_with(strtolower($entry), '.html')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function parseStyleCssFallback(string $path): array
    {
        $chunk = (string) @file_get_contents($path, false, null, 0, 8192);
        $known = [
            'Theme Name',
            'Theme URI',
            'Description',
            'Author',
            'Author URI',
            'Version',
            'Template',
            'Text Domain',
            'Requires at least',
            'Requires PHP',
            'License',
        ];
        $headers = [];
        foreach ($known as $field) {
            $pattern = '/^[ \\t\\/*#@]*' . preg_quote($field, '/') . ':[ \\t]*(.*)$/mi';
            if (preg_match($pattern, $chunk, $m) === 1) {
                $headers[$field] = trim((string) $m[1]);
            }
        }

        return $headers;
    }

    /**
     * @return list<string>
     */
    private static function listPhpFiles(string $path): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        $max = 200;
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($path) + 1));
            if (str_contains($rel, '..')) {
                continue;
            }
            $out[] = $rel;
            if (count($out) >= $max) {
                break;
            }
        }
        sort($out);

        return $out;
    }

    /**
     * @param list<string> $phpFiles
     */
    private static function readThemeSources(string $path, array $phpFiles): string
    {
        $buf = '';
        $limit = 0;
        foreach ($phpFiles as $rel) {
            $full = $path . '/' . $rel;
            if (!is_readable($full)) {
                continue;
            }
            $chunk = (string) @file_get_contents($full);
            // Cap per-file read for large themes.
            if (strlen($chunk) > 200000) {
                $chunk = substr($chunk, 0, 200000);
            }
            $buf .= "\n" . $chunk;
            $limit += strlen($chunk);
            if ($limit > 1500000) {
                break;
            }
        }

        return $buf;
    }

    /**
     * @param list<string> $symbols
     *
     * @return list<string>
     */
    private static function findSymbols(string $source, array $symbols): array
    {
        if ($source === '') {
            return [];
        }
        $found = [];
        foreach ($symbols as $sym) {
            // Word-boundary style match for function-like usage.
            $pattern = '/\\b' . preg_quote($sym, '/') . '\\s*\\(/';
            if (preg_match($pattern, $source) === 1) {
                $found[] = $sym;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function score(array $report): int
    {
        if (empty($report['exists'])) {
            return 0;
        }
        if (!empty($report['block'])) {
            return 5;
        }

        $score = 20;
        $headers = is_array($report['headers'] ?? null) ? $report['headers'] : [];
        if (trim((string) ($headers['Theme Name'] ?? '')) !== '') {
            $score += 15;
        }
        $files = is_array($report['files'] ?? null) ? $report['files'] : [];
        if (!empty($files['index.php'])) {
            $score += 20;
        }
        if (!empty($files['style.css'])) {
            $score += 10;
        }
        if (!empty($report['functions_php'])) {
            $score += 5;
        }
        foreach (['header.php', 'footer.php', 'single.php', 'page.php'] as $f) {
            if (!empty($files[$f])) {
                $score += 3;
            }
        }
        if ((string) ($report['screenshot'] ?? '') !== '') {
            $score += 4;
        }

        $shimmed = is_array($report['shimmed_used'] ?? null) ? $report['shimmed_used'] : [];
        $unshimmed = is_array($report['unshimmed_used'] ?? null) ? $report['unshimmed_used'] : [];
        $score += min(15, count($shimmed));
        $score -= min(25, count($unshimmed) * 2);

        return max(0, min(100, $score));
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function buildNotes(array $report): array
    {
        $notes = [];
        if (!empty($report['block'])) {
            $notes[] = 'Block/FSE theme detected (theme.json and/or HTML templates). Out of scope for the initial layer.';
        }
        if (!empty($report['classic'])) {
            $notes[] = 'Classic PHP theme structure detected.';
        }
        if (!empty($report['functions_php'])) {
            $notes[] = 'functions.php will load via AP_Theme::setup with compatibility shims when mode allows.';
        }
        $headers = is_array($report['headers'] ?? null) ? $report['headers'] : [];
        $parent = trim((string) ($headers['Template'] ?? ''));
        if ($parent !== '') {
            $notes[] = 'Child theme of "' . $parent . '" — parent must be installed.';
        }

        return $notes;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private static function buildLimitations(array $report): array
    {
        $limits = [
            'Full block / FSE themes are not supported by this layer.',
            'Not every WordPress function is shimmed — only common classic theme APIs.',
            'Plugin-dependent theme features require equivalent AgoraPress plugins or custom code.',
            'Gutenberg / block patterns / theme.json settings are ignored.',
        ];
        $unshimmed = is_array($report['unshimmed_used'] ?? null) ? $report['unshimmed_used'] : [];
        if ($unshimmed !== []) {
            $limits[] = 'This theme calls symbols that may need manual porting: '
                . implode(', ', array_slice($unshimmed, 0, 12))
                . (count($unshimmed) > 12 ? ', …' : '');
        }

        return $limits;
    }
}
