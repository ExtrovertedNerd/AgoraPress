<?php

/**
 * PHPUnit wrapper for SPEC.md structure assertions.
 *
 * @package AgoraPress
 */

declare(strict_types=1);

namespace AgoraPress\Tests\Structure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RepositoryStructureTest extends TestCase
{
    public function testRepositoryStructureMatchesSpec(): void
    {
        $script = dirname(__DIR__, 2) . '/tests/Structure/assert-structure.php';
        $this->assertFileExists($script);

        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        $this->assertSame(
            0,
            $exit,
            "Structure check failed:\n" . implode("\n", $output)
        );
    }
}
