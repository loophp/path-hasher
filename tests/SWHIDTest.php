<?php

declare(strict_types=1);

use Loophp\PathHasher\SWHID;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @covers \Loophp\PathHasher\SWHID
 */
final class SWHIDTest extends TestCase
{
    #[DataProvider('provideHashStabilityCases')]
    public function testHashStability(string $path, string $hash): void
    {
        self::assertSame((new SWHID())->hash($path), $hash);
    }

    public static function provideHashStabilityCases(): iterable
    {
        yield [
            realpath(__DIR__.'/fixtures/fs/test.md'),
            'swh:1:cnt:04a3ec6a7c509d68216c478f05fd4a1b2064fb18',
        ];

        yield [
            realpath(__DIR__.'/fixtures/fs/dir1'),
            'swh:1:dir:c1eb13ed58d250fd93c8d74579370156e73bc622',
        ];

        yield [
            realpath(__DIR__.'/fixtures/fs/'),
            'swh:1:dir:8432270eb28bc1ece9b21ea9cbdef7ae146746c4',
        ];
    }
}
