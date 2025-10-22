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
    private ?string $narFile = null;

    private ?string $destination = null;

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
            'sha256-o6L3G/JHuNm8sbq+/YBUjv/zBrlbj5854+1FkZoLGPY=',
        ];
    }

    public function testDumpAndUnpack(): void
    {
        $source = realpath(__DIR__.'/fixtures/fs');
        $this->narFile = tempnam(sys_get_temp_dir(), 'nar-test-');
        $this->destination = sys_get_temp_dir().'/nar-unpack-'.uniqid();

        $nar = new NAR();

        $nar->write($source, $this->narFile);
        $nar->extract($this->narFile, $this->destination);

        $sourceHash = $nar->computeHashes($source);
        $destinationHash = $nar->computeHashes($this->destination);

        self::assertSame($sourceHash, $destinationHash);
    }

    public function testDumpToStdoutAndUnpack(): void
    {
        $source = realpath(__DIR__.'/fixtures/fs');

        // Capture stdout output of dump()
        ob_start();
        (new NAR())->write($source, null);
        $narData = ob_get_clean();

        // Write captured NAR bytes to a temporary file and unpack from it
        $this->narFile = tempnam(sys_get_temp_dir(), 'nar-test-');
        if (false === $this->narFile) {
            self::fail('Failed to create temporary nar file');
        }
        file_put_contents($this->narFile, $narData);

        $this->destination = sys_get_temp_dir().'/nar-unpack-'.uniqid();

        $nar = new NAR();
        $nar->extract($this->narFile, $this->destination);

        $sourceHash = $nar->computeHashes($source);
        $destinationHash = $nar->computeHashes($this->destination);

        self::assertSame($sourceHash, $destinationHash);
    }

    protected function tearDown(): void
    {
        if ($this->narFile && file_exists($this->narFile)) {
            @unlink($this->narFile);
            $this->narFile = null;
        }

        if ($this->destination && file_exists($this->destination)) {
            $this->removeDirectory($this->destination);
            $this->destination = null;
        }

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $pathname = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($pathname);
            } else {
                @unlink($pathname);
            }
        }

        @rmdir($path);
    }
}
