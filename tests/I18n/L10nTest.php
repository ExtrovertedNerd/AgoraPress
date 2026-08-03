<?php

/**
 * Tests for AP_L10n (gettext catalogs, locale, RTL).
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\I18n;

use AP_DB;
use AP_L10n;
use AP_Migrator;
use AP_Options;
use AP_Seo;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AP_L10n::class)]
final class L10nTest extends TestCase
{
    private string $root;

    private string $tempLangDir;

    private AP_DB $db;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        require_once $this->root . '/ap-includes/version.php';
        require_once $this->root . '/ap-includes/hooks.php';
        require_once $this->root . '/ap-includes/class-ap-db.php';
        require_once $this->root . '/ap-includes/class-ap-migrator.php';
        require_once $this->root . '/ap-includes/class-ap-options.php';
        require_once $this->root . '/ap-includes/class-ap-formatting.php';
        require_once $this->root . '/ap-includes/class-ap-l10n.php';
        require_once $this->root . '/ap-includes/class-ap-seo.php';
        require_once $this->root . '/ap-includes/functions.php';
        require_once $this->root . '/ap-includes/template-tags.php';

        AP_L10n::reset();
        AP_Options::flushCache();
        AP_Seo::reset();
        if (function_exists('ap_reset_hooks')) {
            ap_reset_hooks();
        }

        $this->tempLangDir = sys_get_temp_dir() . '/ap-l10n-' . bin2hex(random_bytes(4));
        mkdir($this->tempLangDir, 0755, true);
        AP_L10n::setLangDirOverride($this->tempLangDir);

        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->db = AP_DB::fromPdo($pdo, 'sqlite', 'ap_');
        $migrator = new AP_Migrator($this->db, AP_Migrator::defaultMigrationsPath());
        $migrator->migrate();
        $GLOBALS['apdb'] = $this->db;
    }

    protected function tearDown(): void
    {
        AP_L10n::reset();
        AP_Options::flushCache();
        unset($GLOBALS['apdb']);
        $this->removeDir($this->tempLangDir);
    }

    public function testTranslateIdentityWithoutCatalog(): void
    {
        $this->assertSame('Hello', ap__('Hello'));
        $this->assertSame('Hello', __('Hello'));
        $this->assertSame('Posts', _x('Posts', 'menu'));
    }

    public function testInMemoryTranslations(): void
    {
        AP_L10n::loadTranslations('default', [
            'Hello' => 'Hola',
            'Save' => 'Guardar',
            "menu\x04Posts" => 'Entradas',
            "One comment\x00%d comments" => ['Un comentario', '%d comentarios'],
        ]);

        $this->assertSame('Hola', ap__('Hello'));
        $this->assertSame('Guardar', __('Save'));
        $this->assertSame('Entradas', ap_x('Posts', 'menu'));
        $this->assertSame('Un comentario', ap_n('One comment', '%d comments', 1));
        $this->assertSame('%d comentarios', ap_n('One comment', '%d comments', 5));
    }

    public function testEscapedTranslations(): void
    {
        AP_L10n::loadTranslations('default', [
            'A & B' => 'A & B <ok>',
        ]);

        $this->assertSame('A &amp; B &lt;ok&gt;', ap_esc_html__('A & B'));
        $this->assertSame('A &amp; B &lt;ok&gt;', ap_esc_attr__('A & B'));
    }

    public function testMoRoundTrip(): void
    {
        $mo = $this->tempLangDir . '/agorapress-es_ES.mo';
        $ok = AP_L10n::writeMoFile($mo, [
            'Dashboard' => 'Escritorio',
            'Settings' => 'Ajustes',
            "One item\x00%d items" => ['Un elemento', '%d elementos'],
            "nav\x04Home" => 'Inicio',
        ]);
        $this->assertTrue($ok);
        $this->assertFileExists($mo);

        AP_L10n::unloadTextdomain();
        AP_L10n::setLocale('es_ES');
        $this->assertTrue(AP_L10n::loadDomainLocale('default', 'es_ES'));

        $this->assertSame('Escritorio', __('Dashboard'));
        $this->assertSame('Ajustes', ap__('Settings'));
        $this->assertSame('Inicio', _x('Home', 'nav'));
        $this->assertSame('Un elemento', _n('One item', '%d items', 1));
        $this->assertSame('%d elementos', _n('One item', '%d items', 3));
    }

    public function testLocaleFromWplangOption(): void
    {
        AP_Options::update('WPLANG', 'ar', $this->db);
        AP_L10n::setLocale(null);

        $this->assertSame('ar', ap_get_locale($this->db));
        $this->assertTrue(ap_is_rtl());
        $this->assertSame('rtl', ap_get_text_direction());
        $this->assertSame('ar', ap_get_html_lang());
        $this->assertStringContainsString('dir="rtl"', ap_get_language_attributes());
        $this->assertStringContainsString('lang="ar"', ap_get_language_attributes());
    }

    public function testLtrDefaultLocale(): void
    {
        AP_Options::update('WPLANG', '', $this->db);
        AP_L10n::setLocale(null);

        $this->assertSame('en_US', ap_get_locale($this->db));
        $this->assertFalse(ap_is_rtl());
        $this->assertSame('ltr', ap_get_text_direction());
        $this->assertSame('en-US', ap_get_html_lang());
        $this->assertStringContainsString('dir="ltr"', ap_get_language_attributes());
    }

    public function testRtlLocales(): void
    {
        foreach (['ar', 'ar_SA', 'he_IL', 'fa_IR', 'ur'] as $locale) {
            $this->assertTrue(AP_L10n::isRtl($locale), "Expected RTL for {$locale}");
        }
        foreach (['en_US', 'de_DE', 'fr_FR', 'ja'] as $locale) {
            $this->assertFalse(AP_L10n::isRtl($locale), "Expected LTR for {$locale}");
        }
    }

    public function testSanitizeLocale(): void
    {
        $this->assertSame('en_US', AP_L10n::sanitizeLocale('en-us'));
        $this->assertSame('ar', AP_L10n::sanitizeLocale('ar'));
        $this->assertSame('pt_BR', AP_L10n::sanitizeLocale('pt_br'));
        $this->assertSame('', AP_L10n::sanitizeLocale('../evil'));
        $this->assertSame('', AP_L10n::sanitizeLocale(''));
    }

    public function testBloginfoLanguageAndDirection(): void
    {
        AP_L10n::setLocale('he_IL');
        $this->assertSame('he-IL', ap_get_bloginfo('language'));
        $this->assertSame('rtl', ap_get_bloginfo('text_direction'));
    }

    public function testBodyClassIncludesDirection(): void
    {
        AP_L10n::setLocale('ar');
        $classes = ap_get_body_class();
        $this->assertContains('rtl', $classes);
        $this->assertNotContains('ltr', $classes);

        AP_L10n::setLocale('en_US');
        $classes = ap_get_body_class();
        $this->assertContains('ltr', $classes);
        $this->assertNotContains('rtl', $classes);
    }

    public function testOpenGraphLocaleFollowsSiteLanguage(): void
    {
        AP_Options::update('home', 'https://example.test', $this->db);
        AP_Options::update('siteurl', 'https://example.test', $this->db);
        AP_Options::update('blogname', 'Test', $this->db);
        AP_Options::update('open_graph_enabled', '1', $this->db);
        AP_L10n::setLocale('de_DE');

        $meta = AP_Seo::getOpenGraphMeta(null, $this->db);
        $this->assertSame('de_DE', $meta['og:locale'] ?? null);
    }

    public function testInstalledLanguagesDiscoversMoFiles(): void
    {
        AP_L10n::writeMoFile($this->tempLangDir . '/ar.mo', ['Hello' => 'مرحبا']);
        AP_L10n::writeMoFile($this->tempLangDir . '/agorapress-he_IL.mo', ['Hello' => 'שלום']);

        $installed = AP_L10n::installedLanguages();
        $this->assertArrayHasKey('', $installed);
        $this->assertArrayHasKey('ar', $installed);
        $this->assertArrayHasKey('he_IL', $installed);
    }

    public function testDomainIsolation(): void
    {
        AP_L10n::loadTranslations('default', ['Key' => 'Default Val']);
        AP_L10n::loadTranslations('myplugin', ['Key' => 'Plugin Val']);

        $this->assertSame('Default Val', __('Key'));
        $this->assertSame('Plugin Val', __('Key', 'myplugin'));
        $this->assertSame('Key', __('Key', 'missing'));
    }

    public function testGettextFilter(): void
    {
        AP_L10n::loadTranslations('default', ['Hello' => 'Hola']);
        ap_add_filter('ap_gettext', static function (string $translation, string $text, string $domain): string {
            if ($text === 'Hello') {
                return strtoupper($translation);
            }

            return $translation;
        }, 10, 3);

        $this->assertSame('HOLA', __('Hello'));
    }

    public function testLanguageAttributesFilter(): void
    {
        AP_L10n::setLocale('en_US');
        ap_add_filter('ap_language_attributes', static function (string $attrs): string {
            return $attrs . ' data-test="1"';
        });

        $this->assertStringContainsString('data-test="1"', ap_get_language_attributes());
    }

    /**
     * Recursively remove a directory tree.
     */
    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
