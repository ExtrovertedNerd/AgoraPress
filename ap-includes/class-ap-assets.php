<?php

/**
 * AgoraPress front-end style and script enqueue API.
 *
 * WordPress-inspired register → enqueue → print pipeline with dependency
 * resolution, media queries, footer scripts, and inline style/script appends.
 * Themes and plugins call ap_enqueue_* during `ap_enqueue_scripts`; templates
 * print via ap_head() / ap_footer().
 *
 * @package AgoraPress
 */

declare(strict_types=1);

/**
 * Register, enqueue, and print CSS/JS assets.
 */
class AP_Assets
{
    /** @var array<string, array{src: string, deps: list<string>, ver: string|false|null, media: string, args: array<string, mixed>}> */
    private static array $registeredStyles = [];

    /** @var array<string, array{src: string, deps: list<string>, ver: string|false|null, in_footer: bool, args: array<string, mixed>}> */
    private static array $registeredScripts = [];

    /** @var array<string, true> */
    private static array $queueStyles = [];

    /** @var array<string, true> */
    private static array $queueScripts = [];

    /** @var array<string, true> */
    private static array $doneStyles = [];

    /** @var array<string, true> */
    private static array $doneScripts = [];

    /** @var array<string, list<string>> */
    private static array $inlineStyles = [];

    /** @var array<string, list<array{data: string, position: string}>> */
    private static array $inlineScripts = [];

    private static bool $stylesPrinted = false;

    private static bool $headScriptsPrinted = false;

    private static bool $footerScriptsPrinted = false;

    // -------------------------------------------------------------------------
    // Styles
    // -------------------------------------------------------------------------

    /**
     * Register a stylesheet (does not print until enqueued).
     *
     * @param list<string> $deps
     * @param string|false|null $ver Version query arg; false = none; null = AP_VERSION when defined
     */
    public static function registerStyle(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        string $media = 'all'
    ): bool {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '') {
            return false;
        }

        self::$registeredStyles[$handle] = [
            'src' => $src,
            'deps' => self::sanitizeDeps($deps),
            'ver' => $ver,
            'media' => $media !== '' ? $media : 'all',
            'args' => [],
        ];

        return true;
    }

    /**
     * Enqueue a stylesheet (registers first when $src is provided).
     *
     * @param list<string> $deps
     * @param string|false|null $ver
     */
    public static function enqueueStyle(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        string $media = 'all'
    ): bool {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '') {
            return false;
        }

        if ($src !== '' || !isset(self::$registeredStyles[$handle])) {
            if ($src === '' && !isset(self::$registeredStyles[$handle])) {
                return false;
            }
            if ($src !== '') {
                self::registerStyle($handle, $src, $deps, $ver, $media);
            }
        }

        if (!isset(self::$registeredStyles[$handle])) {
            return false;
        }

        self::$queueStyles[$handle] = true;

        return true;
    }

    public static function dequeueStyle(string $handle): void
    {
        $handle = self::sanitizeHandle($handle);
        unset(self::$queueStyles[$handle]);
    }

    public static function deregisterStyle(string $handle): void
    {
        $handle = self::sanitizeHandle($handle);
        unset(self::$registeredStyles[$handle], self::$queueStyles[$handle], self::$inlineStyles[$handle]);
    }

    /**
     * Append CSS after an enqueued style's link tag.
     */
    public static function addInlineStyle(string $handle, string $data): bool
    {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '' || !isset(self::$registeredStyles[$handle])) {
            return false;
        }
        $data = trim($data);
        if ($data === '') {
            return false;
        }
        if (!isset(self::$inlineStyles[$handle])) {
            self::$inlineStyles[$handle] = [];
        }
        self::$inlineStyles[$handle][] = $data;

        return true;
    }

    /**
     * Print enqueued styles (and their dependencies) once.
     */
    public static function printStyles(): void
    {
        if (self::$stylesPrinted) {
            return;
        }
        self::$stylesPrinted = true;

        $toDo = self::resolveOrder(array_keys(self::$queueStyles), self::$registeredStyles);
        foreach ($toDo as $handle) {
            self::doItemStyle($handle);
        }
    }

    // -------------------------------------------------------------------------
    // Scripts
    // -------------------------------------------------------------------------

    /**
     * Register a script.
     *
     * @param list<string> $deps
     * @param string|false|null $ver
     */
    public static function registerScript(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        bool $inFooter = false
    ): bool {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '') {
            return false;
        }

        self::$registeredScripts[$handle] = [
            'src' => $src,
            'deps' => self::sanitizeDeps($deps),
            'ver' => $ver,
            'in_footer' => $inFooter,
            'args' => [],
        ];

        return true;
    }

    /**
     * Enqueue a script (registers first when $src is provided).
     *
     * @param list<string> $deps
     * @param string|false|null $ver
     */
    public static function enqueueScript(
        string $handle,
        string $src = '',
        array $deps = [],
        string|bool|null $ver = false,
        bool $inFooter = false
    ): bool {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '') {
            return false;
        }

        if ($src !== '' || !isset(self::$registeredScripts[$handle])) {
            if ($src === '' && !isset(self::$registeredScripts[$handle])) {
                return false;
            }
            if ($src !== '') {
                self::registerScript($handle, $src, $deps, $ver, $inFooter);
            }
        }

        if (!isset(self::$registeredScripts[$handle])) {
            return false;
        }

        self::$queueScripts[$handle] = true;

        return true;
    }

    public static function dequeueScript(string $handle): void
    {
        $handle = self::sanitizeHandle($handle);
        unset(self::$queueScripts[$handle]);
    }

    public static function deregisterScript(string $handle): void
    {
        $handle = self::sanitizeHandle($handle);
        unset(self::$registeredScripts[$handle], self::$queueScripts[$handle], self::$inlineScripts[$handle]);
    }

    /**
     * Append JS before/after an enqueued script tag.
     *
     * @param string $position 'before' or 'after'
     */
    public static function addInlineScript(string $handle, string $data, string $position = 'after'): bool
    {
        $handle = self::sanitizeHandle($handle);
        if ($handle === '' || !isset(self::$registeredScripts[$handle])) {
            return false;
        }
        $data = trim($data);
        if ($data === '') {
            return false;
        }
        $position = $position === 'before' ? 'before' : 'after';
        if (!isset(self::$inlineScripts[$handle])) {
            self::$inlineScripts[$handle] = [];
        }
        self::$inlineScripts[$handle][] = ['data' => $data, 'position' => $position];

        return true;
    }

    /**
     * Print enqueued scripts for head ($footer=false) or footer ($footer=true).
     */
    public static function printScripts(bool $footer = false): void
    {
        if ($footer) {
            if (self::$footerScriptsPrinted) {
                return;
            }
            self::$footerScriptsPrinted = true;
        } else {
            if (self::$headScriptsPrinted) {
                return;
            }
            self::$headScriptsPrinted = true;
        }

        $handles = [];
        foreach (array_keys(self::$queueScripts) as $handle) {
            if (!isset(self::$registeredScripts[$handle])) {
                continue;
            }
            $inFooter = (bool) self::$registeredScripts[$handle]['in_footer'];
            if ($inFooter === $footer) {
                $handles[] = $handle;
            }
        }

        $toDo = self::resolveOrder($handles, self::$registeredScripts);
        foreach ($toDo as $handle) {
            // Dependency may live in the other group; print it here if not done.
            self::doItemScript($handle, $footer);
        }
    }

    // -------------------------------------------------------------------------
    // Query / reset
    // -------------------------------------------------------------------------

    /**
     * Query registration / queue state for a style handle.
     *
     * @param string $list registered|enqueued|queue|done
     */
    public static function styleIs(string $handle, string $list = 'enqueued'): bool
    {
        $handle = self::sanitizeHandle($handle);

        return match ($list) {
            'registered' => isset(self::$registeredStyles[$handle]),
            'enqueued', 'queue' => isset(self::$queueStyles[$handle]),
            'done' => isset(self::$doneStyles[$handle]),
            default => false,
        };
    }

    /**
     * Query registration / queue state for a script handle.
     *
     * @param string $list registered|enqueued|queue|done
     */
    public static function scriptIs(string $handle, string $list = 'enqueued'): bool
    {
        $handle = self::sanitizeHandle($handle);

        return match ($list) {
            'registered' => isset(self::$registeredScripts[$handle]),
            'enqueued', 'queue' => isset(self::$queueScripts[$handle]),
            'done' => isset(self::$doneScripts[$handle]),
            default => false,
        };
    }

    /**
     * Reset all asset state (unit tests).
     */
    public static function reset(): void
    {
        self::$registeredStyles = [];
        self::$registeredScripts = [];
        self::$queueStyles = [];
        self::$queueScripts = [];
        self::$doneStyles = [];
        self::$doneScripts = [];
        self::$inlineStyles = [];
        self::$inlineScripts = [];
        self::$stylesPrinted = false;
        self::$headScriptsPrinted = false;
        self::$footerScriptsPrinted = false;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function doItemStyle(string $handle): void
    {
        if (isset(self::$doneStyles[$handle]) || !isset(self::$registeredStyles[$handle])) {
            return;
        }
        self::$doneStyles[$handle] = true;

        $item = self::$registeredStyles[$handle];
        $src = (string) $item['src'];
        if ($src !== '') {
            $href = self::withVersion($src, $item['ver']);
            $media = htmlspecialchars((string) $item['media'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $hrefEsc = self::escUrl($href);
            echo '<link rel="stylesheet" id="'
                . self::escAttr($handle . '-css')
                . '" href="' . $hrefEsc . '" media="' . $media . '">' . "\n";
        }

        if (!empty(self::$inlineStyles[$handle])) {
            $css = implode("\n", self::$inlineStyles[$handle]);
            echo '<style id="' . self::escAttr($handle . '-inline-css') . '">'
                . self::stripCloseStyle($css)
                . '</style>' . "\n";
        }
    }

    private static function doItemScript(string $handle, bool $footerGroup): void
    {
        if (isset(self::$doneScripts[$handle]) || !isset(self::$registeredScripts[$handle])) {
            return;
        }

        $item = self::$registeredScripts[$handle];
        // Ensure dependencies print first (even if they belong to the other group).
        foreach ($item['deps'] as $dep) {
            if (isset(self::$registeredScripts[$dep]) && !isset(self::$doneScripts[$dep])) {
                self::doItemScript($dep, $footerGroup);
            }
        }

        if (isset(self::$doneScripts[$handle])) {
            return;
        }
        self::$doneScripts[$handle] = true;

        $before = [];
        $after = [];
        foreach (self::$inlineScripts[$handle] ?? [] as $chunk) {
            if (($chunk['position'] ?? 'after') === 'before') {
                $before[] = $chunk['data'];
            } else {
                $after[] = $chunk['data'];
            }
        }

        foreach ($before as $js) {
            echo '<script id="' . self::escAttr($handle . '-js-before') . '">'
                . self::stripCloseScript($js)
                . '</script>' . "\n";
        }

        $src = (string) $item['src'];
        if ($src !== '') {
            $href = self::withVersion($src, $item['ver']);
            echo '<script id="' . self::escAttr($handle . '-js')
                . '" src="' . self::escUrl($href) . '"></script>' . "\n";
        }

        foreach ($after as $js) {
            echo '<script id="' . self::escAttr($handle . '-js-after') . '">'
                . self::stripCloseScript($js)
                . '</script>' . "\n";
        }
    }

    /**
     * Topological order of handles by dependency (registered graph).
     *
     * @param list<string> $handles
     * @param array<string, array{deps: list<string>}> $registry
     *
     * @return list<string>
     */
    private static function resolveOrder(array $handles, array $registry): array
    {
        $needed = [];
        $stack = $handles;
        while ($stack !== []) {
            $h = array_pop($stack);
            if ($h === '' || isset($needed[$h]) || !isset($registry[$h])) {
                continue;
            }
            $needed[$h] = true;
            foreach ($registry[$h]['deps'] as $dep) {
                if (!isset($needed[$dep]) && isset($registry[$dep])) {
                    $stack[] = $dep;
                }
            }
        }

        $sorted = [];
        $visiting = [];
        $visit = static function (string $h) use (&$visit, &$sorted, &$visiting, $needed, $registry): void {
            if (!isset($needed[$h]) || isset($sorted[$h])) {
                return;
            }
            if (isset($visiting[$h])) {
                // Cycle — break without re-entering.
                return;
            }
            $visiting[$h] = true;
            foreach ($registry[$h]['deps'] as $dep) {
                if (isset($needed[$dep])) {
                    $visit($dep);
                }
            }
            unset($visiting[$h]);
            $sorted[$h] = true;
        };

        foreach (array_keys($needed) as $h) {
            $visit($h);
        }

        return array_keys($sorted);
    }

    /**
     * @param list<mixed> $deps
     *
     * @return list<string>
     */
    private static function sanitizeDeps(array $deps): array
    {
        $out = [];
        foreach ($deps as $dep) {
            if (!is_string($dep) && !is_int($dep)) {
                continue;
            }
            $d = self::sanitizeHandle((string) $dep);
            if ($d !== '') {
                $out[] = $d;
            }
        }

        return array_values(array_unique($out));
    }

    private static function sanitizeHandle(string $handle): string
    {
        $handle = strtolower(trim($handle));
        $handle = preg_replace('/[^a-z0-9._\\-]+/', '', $handle) ?? '';

        return $handle;
    }

    private static function withVersion(string $src, string|bool|null $ver): string
    {
        if ($ver === false) {
            return $src;
        }
        if ($ver === null) {
            $ver = defined('AP_VERSION') ? (string) AP_VERSION : '';
        }
        $ver = (string) $ver;
        if ($ver === '') {
            return $src;
        }
        $sep = str_contains($src, '?') ? '&' : '?';

        return $src . $sep . 'ver=' . rawurlencode($ver);
    }

    private static function escUrl(string $url): string
    {
        if (function_exists('ap_esc_url')) {
            return ap_esc_url($url);
        }

        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function escAttr(string $text): string
    {
        if (function_exists('ap_esc_attr')) {
            return ap_esc_attr($text);
        }

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function stripCloseStyle(string $css): string
    {
        return str_ireplace('</style', '<\/style', $css);
    }

    private static function stripCloseScript(string $js): string
    {
        return str_ireplace('</script', '<\/script', $js);
    }
}
