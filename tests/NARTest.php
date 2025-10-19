<?php

declare(strict_types=1);

use Loophp\PathHasher\NAR;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class NARTest extends TestCase
{
    public function testExample(): void
    {
        $hash = (new NAR())->hash(realpath(__DIR__.'/../composer.json'));

        self::assertSame('sha256-u1zL47tiE286m+1t7GzpgWCqgXa+hk/MRLVlPqdbzKo=', $hash);
    }
}
