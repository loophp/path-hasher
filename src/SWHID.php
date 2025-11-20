<?php

declare(strict_types=1);

namespace Loophp\PathHasher;

use Generator;
use loophp\iterators\MapIterableAggregate;
use loophp\iterators\SortIterableAggregate;

/**
 * Software Heritage Identifier (SWHID) implementation for filesystem paths.
 *
 * This class computes SWHIDs for files, directories, and symlinks on the local filesystem,
 * following the Software Heritage persistent identifier specification.
 *
 * SWHIDs are persistent, intrinsic identifiers for software artifacts, based on their content.
 * The supported object types are:
 *   - "cnt" (content): for files and symlinks
 *   - "dir" (directory): for directories
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
        $fsObject = new \SplFileInfo($path);

        $infos = $this->describeFilesystemObject($fsObject);

        yield \sprintf('swh:1:%s:%s', $infos['ctx'], bin2hex($this->computeBlobHash($infos['hashCallback']($fsObject))));
    }

    /**
     * Return an associative array.
     *
     * @return array{
     *   fsObject: \SplFileInfo,
     *   ctx: string,
     *   mode: int,
     *   sortKey: string,
     *   hash: \Generator<string>
     * }
     */
    private function describeFilesystemObject(\SplFileInfo $fsObject): array
    {
        return match (true) {
            $fsObject->isLink() => [
                'fsObject' => $fsObject,
                'ctx' => 'cnt',
                'mode' => 120000,
                'sortKey' => $fsObject->getFilename(),
                'hashCallback' => $this->streamBlobFromString(...),
            ],
            $fsObject->isDir() => [
                'fsObject' => $fsObject,
                'ctx' => 'dir',
                'mode' => 40000,
                'sortKey' => \sprintf('%s/', $fsObject->getFilename()),
                'hashCallback' => $this->streamBlobFromDir(...),
            ],
            default => [
                'fsObject' => $fsObject,
                'ctx' => 'cnt',
                'mode' => ($fsObject->isExecutable() ? 100755 : 100644),
                'sortKey' => $fsObject->getFilename(),
                'hashCallback' => $this->streamBlobFromFile(...),
            ],
        };
    }

    /**
     * Compute the SHA1 hash from a generator of content chunks.
     *
     * @param \Generator<string> $generator yields string chunks of the object serialization
     *
     * @return string Raw binary hash
     */
    private function computeBlobHash(\Generator $generator): string
    {
        $h = hash_init('sha1');

        foreach ($generator as $chunk) {
            hash_update($h, $chunk);
        }

        return hash_final($h, true);
    }

    /**
     * Stream a blob object from a file object.
     *
     * @return \Generator<string>
     */
    private function streamBlobFromFile(\SplFileInfo $file): \Generator
    {
        yield from $this->streamHeader('blob', $file->getSize());

        $fh = fopen($file->getPathname(), 'r');

        while (!feof($fh)) {
            $chunk = fread($fh, 8192);

            if (false === $chunk) {
                fclose($fh);

                throw new \RuntimeException("Error reading file: {$file->getPathname()}");
            }

            yield $chunk;
        }
    }

    /**
     * Stream a blob object from a string (used for symlink targets).
     *
     * @return \Generator<string>
     */
    private function streamBlobFromString(\SplFileInfo $fsObject): \Generator
    {
        $linkTarget = $fsObject->getLinkTarget();

        yield from $this->streamHeader('blob', \strlen($linkTarget));

        yield $linkTarget;
    }

    /**
     * Stream a tree object for a directory.
     *
     * @return \Generator<string>
     */
    private function streamBlobFromDir(\SplFileInfo $dir): \Generator
    {
        $sortCallback = static fn (array $a, array $b): int => $a['sortKey'] <=> $b['sortKey'];

        $treeIterable = new SortIterableAggregate(
            new MapIterableAggregate(
                new \FilesystemIterator(
                    $dir->getPathname(),
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                ),
                $this->describeFilesystemObject(...)
            ),
            $sortCallback
        );

        $treeHash = '';
        foreach ($treeIterable as $e) {
            $treeHash .= \sprintf("%s %s\0%s", $e['mode'], $e['fsObject']->getFilename(), $this->computeBlobHash($e['hashCallback']($e['fsObject'])));
        }

        yield from $this->streamHeader('tree', \strlen($treeHash));

        yield $treeHash;
    }

    /**
     * @return \Generator<string>
     */
    private function streamHeader(string $type, int $length): \Generator
    {
        yield $type;

        yield ' ';

        yield (string) $length;

        yield "\0";
    }
}
