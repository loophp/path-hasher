<?php

declare(strict_types=1);

namespace Loophp\PathHasher;

use loophp\iterators\MapIterableAggregate;
use loophp\iterators\ReduceIterableAggregate;
use loophp\iterators\SortIterableAggregate;

/**
 * Software Heritage Identifier (SWHID) implementation for filesystem paths.
 *
 * This class computes SWHIDs for files, directories, and symlinks on the local filesystem,
 * following the Software Heritage persistent identifier specification.
 *
 * SWHIDs are persistent, intrinsic identifiers for software artifacts, based on their content.
 * The supported object types are:
 *   - "cnt" (content): for files and symlinks (as Git blobs)
 *   - "dir" (directory): for directories (as Git trees)
 *
 * Higher-level SWHID types ("rel" for release, "rev" for revision, "snp" for snapshot)
 * are NOT supported by this implementation yet.
 *
 * Qualifiers are supported and appended to the SWHID as per the specification.
 *
 * References:
 *   - https://docs.softwareheritage.org/devel/swh-model/persistent-identifiers.html
 *   - https://docs.softwareheritage.org/devel/swh-model/data-model.html
 */
final class SWHID implements PathHasher
{
    /**
     * Compute the SWHID for a filesystem path.
     *
     * Supported types:
     *   - "cnt" for files and symlinks
     *   - "dir" for directories
     *
     * @param string                $path       filesystem path to hash
     * @param array<string, string> $qualifiers optional SWHID qualifiers
     *
     * @return string the SWHID string
     *
     * @throws \RuntimeException if the path is invalid or unsupported
     */
    public function hash(string $path, array $qualifiers = []): string
    {
        $core = '';

        foreach ($this->stream($path) as $chunk) {
            $core .= $chunk;
        }

        return array_reduce(
            array_keys($qualifiers),
            static fn (string $carry, string $key): string => \sprintf('%s;%s=%s', $carry, $key, rawurlencode($qualifiers[$key])),
            $core
        );
    }

    /**
     * Stream the SWHID core identifier for a filesystem path.
     *
     * Yields the SWHID prefix, type, and object id (hash) as separate chunks.
     *
     * @param string $path filesystem path to stream
     *
     * @return \Generator<string>
     *
     * @throws \RuntimeException if the path is invalid or unsupported
     */
    public function stream(string $path): \Generator
    {
        $infos = $this->describeFilesystemObject(new \SplFileInfo($path));

        yield 'swh:1:';

        yield $infos['ctx'];

        yield ':';

        yield $this->computeBlobHash($infos['hex']);
    }

    private function describeFilesystemObject(\SplFileInfo $fsObject): array
    {
        return match (true) {
            $fsObject->isLink() => [
                'fsObject' => $fsObject,
                'ctx' => 'cnt',
                'mode' => '120000',
                'hex' => $this->streamBlobFromString($fsObject->getLinkTarget()),
            ],
            $fsObject->isDir() => [
                'fsObject' => $fsObject,
                'ctx' => 'dir',
                'mode' => '40000',
                'hex' => $this->streamTree($fsObject->getPathname()),
            ],
            default => [
                'fsObject' => $fsObject,
                'ctx' => 'cnt',
                'mode' => ($fsObject->isExecutable() ? '100755' : '100644'),
                'hex' => $this->streamBlobFromFile($fsObject->getPathname()),
            ],
        };
    }

    /**
     * Compute the Git-compatible SHA1 hash from a generator of content chunks.
     *
     * @param \Generator $generator yields string chunks of the object serialization
     *
     * @return string 40-character lowercase hex SHA1
     */
    private function computeBlobHash(\Generator $generator): string
    {
        $h = hash_init('sha1');

        foreach ($generator as $chunk) {
            hash_update($h, $chunk);
        }

        return hash_final($h);
    }

    /**
     * Stream a Git blob object from a file.
     *
     * @param string $path path to the file
     *
     * @return \Generator<string>
     *
     * @throws \RuntimeException if the file cannot be read
     */
    private function streamBlobFromFile(string $path): \Generator
    {
        $size = filesize($path);

        if (false === $size) {
            throw new \RuntimeException("Cannot stat file: {$path}");
        }

        $fh = fopen($path, 'r');

        if (false === $fh) {
            throw new \RuntimeException("Cannot open file for reading: {$path}");
        }

        yield 'blob ';

        yield (string) $size;

        yield "\0";

        try {
            while (!feof($fh)) {
                $chunk = fread($fh, 8192);
                if (false === $chunk) {
                    throw new \RuntimeException("Error reading file: {$path}");
                }

                yield $chunk;
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * Stream a Git blob object from a string (used for symlink targets).
     *
     * @param string $bytes the string content
     *
     * @return \Generator<string>
     */
    private function streamBlobFromString(string $bytes): \Generator
    {
        yield 'blob ';

        yield (string) \strlen($bytes);

        yield "\0";

        yield $bytes;
    }

    /**
     * Stream a Git tree object for a directory.
     *
     * @param string $dir path to the directory
     *
     * @return \Generator<string>
     *
     * @throws \RuntimeException if the directory cannot be read
     */
    private function streamTree(string $dir): \Generator
    {
        $treeIterable
            = new ReduceIterableAggregate(
                new SortIterableAggregate(
                    new MapIterableAggregate(
                        new \FilesystemIterator(
                            $dir,
                            \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                        ),
                        $this->describeFilesystemObject(...)
                    ),
                    static fn (array $a, array $b): int => $a['fsObject']->getFilename() <=> $b['fsObject']->getFilename()
                ),
                fn (string $carry, array $e): string => \sprintf('%s%s %s%s%s', $carry, $e['mode'], $e['fsObject']->getFilename(), "\x00", hex2bin($this->computeBlobHash($e['hex']))),
                ''
            );

        $treeHash = iterator_to_array($treeIterable, false)[0];

        yield 'tree ';

        yield (string) \strlen($treeHash);

        yield "\0";

        yield $treeHash;
    }
}
