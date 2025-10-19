<?php

declare(strict_types=1);

namespace Loophp\PathHasher;

interface PathHasher
{
    public function hash(string $path): string;
}
