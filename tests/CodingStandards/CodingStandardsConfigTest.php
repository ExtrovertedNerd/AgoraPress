<?php

/**
 * Assert PSR-12 adapted coding standards config is present and coherent.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\CodingStandards;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CodingStandardsConfigTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testPhpcsXmlDistExists(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $this->assertFileIsReadable($path, 'phpcs.xml.dist must exist at project root');
    }

    public function testCodingStandardsDocExists(): void
    {
        $path = $this->root . '/CODING_STANDARDS.md';
        $this->assertFileIsReadable($path, 'CODING_STANDARDS.md must document adaptations');
    }

    public function testPhpcsRulesetIsValidXmlAndReferencesPsr12(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($raw);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($xml, 'phpcs.xml.dist must be well-formed XML');
        $this->assertSame([], $errors);

        $this->assertSame('AgoraPress', (string) $xml['name']);

        $ruleRefs = [];
        foreach ($xml->rule as $rule) {
            $ref = (string) $rule['ref'];
            if ($ref !== '') {
                $ruleRefs[] = $ref;
            }
        }

        $this->assertContains('PSR12', $ruleRefs, 'Ruleset must reference PSR12');
        $this->assertContains(
            'Generic.PHP.RequireStrictTypes',
            $ruleRefs,
            'Ruleset must require declare(strict_types=1) where practical (SPEC §6)'
        );
    }

    public function testPhpcsRulesetExcludesWpHybridConflicts(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        // Adapted for hybrid WP-style core (see CODING_STANDARDS.md).
        $requiredExcludes = [
            'PSR1.Classes.ClassDeclaration.MissingNamespace',
            'Squiz.Classes.ValidClassName',
            'PSR1.Files.SideEffects',
        ];

        foreach ($requiredExcludes as $sniff) {
            $this->assertStringContainsString(
                $sniff,
                $raw,
                "phpcs.xml.dist must exclude {$sniff} (WP-hybrid adaptation)"
            );
        }
    }

    public function testPhpcsRulesetScansCorePaths(): void
    {
        $path = $this->root . '/phpcs.xml.dist';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $xml = simplexml_load_string($raw);
        $this->assertNotFalse($xml);

        $files = [];
        foreach ($xml->file as $file) {
            $files[] = (string) $file;
        }

        $this->assertContains('ap-includes', $files);
        $this->assertContains('ap-admin', $files);
        $this->assertContains('tests', $files);
        $this->assertContains('index.php', $files);
        $this->assertContains('ap-config-sample.php', $files);
    }

    public function testCodingStandardsDocMentionsPsr12AndAdaptations(): void
    {
        $path = $this->root . '/CODING_STANDARDS.md';
        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);

        $this->assertStringContainsString('PSR-12', $raw);
        $this->assertStringContainsString('strict_types', $raw);
        $this->assertStringContainsString('AP_', $raw);
        $this->assertStringContainsString('ap_', $raw);
        $this->assertStringContainsString('composer cs', $raw);
    }

    public function testPhpcsRunsCleanOnScaffoldWhenAvailable(): void
    {
        $phpcs = $this->root . '/vendor/bin/phpcs';
        if (!is_executable($phpcs) && !is_file($phpcs)) {
            $this->markTestSkipped('vendor/bin/phpcs not installed (run composer install)');
        }

        $config = $this->root . '/phpcs.xml.dist';
        // -n: report errors only. Line-length is soft guidance (warnings) in phpcs.xml.dist.
        $cmd = escapeshellarg(PHP_BINARY !== '' ? PHP_BINARY : 'php')
            . ' ' . escapeshellarg($phpcs)
            . ' --standard=' . escapeshellarg($config)
            . ' -n -q'
            . ' 2>&1';

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        $this->assertSame(
            0,
            $exit,
            "phpcs reported errors:\n" . implode("\n", $output)
        );
    }
}
