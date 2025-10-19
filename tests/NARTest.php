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
    public function testHash(string $path, string $hash): void
    {
        self::assertSame((new NAR())->hash($path), $hash);
    }

    public static function provideHashCases(): iterable
    {
        yield [
            realpath(__DIR__.'/../composer.json'),
            'sha256-u1zL47tiE286m+1t7GzpgWCqgXa+hk/MRLVlPqdbzKo=',
        ];

        yield [
            realpath(__DIR__.'/../.github'),
            'sha256-PH3MnQGLoV94VwpvDBmZKwZzjpPE+r7t6vJ52xASNnM=',
        ];
    }
}
