<?php

declare(strict_types=1);

use Loophp\PathHasher\NAR;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Loophp\PathHasher\NAR
 */
final class NARTest extends TestCase
{
    #[DataProvider('provideHashCases')]
    public function testHashStability(string $path, string $hash): void
    {
        self::assertSame((new NAR())->hash($path), $hash);
    }

    #[DataProvider('provideHashCases')]
    public function testHashComparisonWithNix(string $path): void
    {
        // Run the `nix hash path <path>` command to get the hash with Nix
        $nixHash = trim((string) shell_exec(sprintf('nix hash path %s', escapeshellarg($path))));

        self::assertSame((new NAR())->hash($path), $nixHash);
    }

    public static function provideHashCases(): iterable
    {
        yield [
            realpath(__DIR__.'/fixtures/fs/test.md'),
            'sha256-8Zli5QunHMIWw0Qr61FCdl2CLeLtBXUrC80Tw8PzaBY=',
        ];

        yield [
            realpath(__DIR__.'/fixtures/fs/dir1'),
            'sha256-WLBm1CL8lkhnV5HwP8oFwQjb00MU/VpCeuqkLzWTrO8=',
        ];

        yield [
            realpath(__DIR__.'/fixtures/fs/'),
            'sha256-t9dtFcmeezsjcQX6Gm+Q5sfGAvVcF/kxdWMrjf8jWFA=',
        ];
    }
}
