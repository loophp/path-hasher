<?php

declare(strict_types=1);

/**
 * Nix NAR implementation.
 *
 * WHAT IT DOES
 * ------------
 * Computes the "NAR hash" of a filesystem path — that is, the SHA-256
 * of its serialisation in the NAR (Nix ARchive) format.
 * Returns results in three encodings:
 *   - hexadecimal (64 characters, standard SHA-256)
 *   - SRI ("sha256-<base64>" form)
 *   - Nix base32 (custom alphabet, least-significant-bit-first; 52 characters)
 *
 * The NAR format is a deterministic serialisation used by Nix to represent
 * directories, files and symlinks independently of the underlying system.
 * The "hash of a path" in Nix corresponds exactly to:
 *
 *     nix hash path <path>
 *
 * SPECIFICATION ORIGINS
 * ---------------------
 * - NAR (Nix Archive) format:
 *   • Strings are encoded as: uint64 little-endian (length), content, zero
 *     padding to the next multiple of 8 bytes.
 *   • Objects: "(" … ")" with ordered key/value pairs.
 *   • Supported types: "directory", "regular", "symlink".
 *   • Directory entries are sorted byte-wise by name for determinism.
 *   • Regular files include an optional "executable" key if the exec bit is set,
 *     followed by "contents" with raw file data.
 *
 * - Nix base32 encoding:
 *   • Alphabet: "0123456789abcdfghijklmnpqrsvwxyz" (no e, o, u, t).
 *   • Least-significant-bit-first order (differs from RFC 4648) and no '=' padding.
 *
 * REFERENCES
 * ----------
 * - The Purely Functional Software Deployment Model
 *   https://edolstra.github.io/pubs/phd-thesis.pdf
 * - Nix manual – format details and path hashing
 *   https://nixos.org/manual/nix/stable/
 * - Nix source code – canonical implementation of NAR and base32
 *   https://github.com/NixOS/nix
 *
 * LIMITATIONS
 * -----------
 * - Serialises regular files, directories and symlinks; ignores metadata not
 *   included in the NAR specification (uid/gid/mtime, etc.).
 * - Does not follow symlinks (targets are written as plain text).
 * - Relies on `is_executable()` correctly reflecting the executable bit.
 */

namespace Loophp\PathHasher;

use Generator;
use loophp\iterators\SortIterableAggregate;

final class NAR implements PathHasher
{
    /**
     * Nix-specific base32 alphabet (no e, o, u, t).
     * Encoded least-significant-bit-first, with no '=' padding.
     */
    private const NIX_BASE32 = '0123456789abcdfghijklmnpqrsvwxyz';

    /**
     * @var string Hash algorithm (e.g., 'sha256', 'sha512').
     */
    private string $hashAlgorithm;

    public function __construct(string $hashAlgorithm = 'sha256')
    {
        $this->hashAlgorithm = $hashAlgorithm;
    }

    /**
     * Serialises a path into NAR byte format, returned as a Generator.
     *
     * This method produces the NAR serialization in chunks, allowing for true
     * streaming processing without buffering the entire archive in memory or on disk.
     *
     * @return \Generator<string> a generator yielding NAR chunks
     *
     * @throws \RuntimeException if the path is missing or unreadable
     */
    public function stream(string $path): \Generator
    {
        if (!file_exists($path) && !is_link($path)) {
            throw new \RuntimeException("Path not found: {$path}");
        }

        yield from $this->generateStringChunk('nix-archive-1');

        yield from $this->generateObjectChunk($path);
    }

    public function hash(string $path): string
    {
        return $this->computeHashes($path)['sri'];
    }

    /**
     * Compute the SHA-256 of a path’s NAR dump, returned in multiple encodings.
     *
     * @param string $path file, directory or symlink
     *
     * @return array{hex:string,sri:string,nix32:string}
     *
     * @throws \RuntimeException if the path does not exist or cannot be read
     */
    public function computeHashes(string $path): array
    {
        $context = hash_init($this->hashAlgorithm);

        foreach ($this->stream($path) as $chunk) {
            hash_update($context, $chunk);
        }

        $raw = hash_final($context, true);

        return [
            'hex' => bin2hex($raw),
            'nix32' => $this->toNixBase32($raw),
            'sri' => \sprintf('%s-%s', $this->hashAlgorithm, base64_encode($raw)),
        ];
    }

    /**
     * Extract a .nar file into $destinationPath.
     *
     * @throws \RuntimeException on I/O or format errors
     */
    public function extract(string $sourcePath, string $destinationPath): void
    {
        $handle = fopen($sourcePath, 'r');

        if (false === $handle) {
            throw new \RuntimeException("Cannot open NAR archive for reading: {$sourcePath}");
        }

        try {
            $this->assertString($handle, 'nix-archive-1');
            $this->unpackObject($handle, $destinationPath);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Encodes a byte string into Nix base32 (custom alphabet, LSB-first, no padding).
     *
     * Implemented via successive integer division (base-256 → base-32),
     * producing least-significant-bit-first digits, then padded to the expected length.
     */
    private function toNixBase32(string $bytes): string
    {
        if ('' === $bytes) {
            return '';
        }

        $arr = array_values(unpack('C*', $bytes));
        $digits = '';

        while (\count($arr) > 0) {
            $quot = [];
            $carry = 0;

            foreach ($arr as $b) {
                $cur = ($carry << 8) + $b;
                $q = intdiv($cur, 32);
                $carry = $cur % 32;

                if (([] !== $quot) || 0 < $q) {
                    $quot[] = $q;
                }
            }
            $digits .= self::NIX_BASE32[$carry];
            $arr = $quot;
        }

        $expectedLen = (int) ceil((\strlen($bytes) * 8) / 5);

        if (\strlen($digits) < $expectedLen) {
            // Pad on the most-significant side (to the right for LSB-first).
            $digits = str_pad($digits, $expectedLen, '0', STR_PAD_RIGHT);
        }

        return $digits;
    }

    /**
     * @return \Generator<string>
     */
    private function generateObjectChunk(string $p): \Generator
    {
        yield from match (true) {
            is_link($p) => $this->handleSymlink($p),
            is_dir($p) => $this->handleDirectory($p),
            default => $this->handleRegularFile($p),
        };
    }

    /**
     * @return \Generator<string>
     */
    private function generateStringChunk(string ...$strings): \Generator
    {
        foreach ($strings as $s) {
            // str(s) = uint64LE(length) + s + zero-padding to a multiple of 8 bytes
            $len = \strlen($s);

            yield $this->u64le($len);

            yield $s;
            $pad = (8 - ($len % 8)) % 8;

            if ($pad) {
                yield str_repeat("\x00", $pad);
            }
        }
    }

    private function handleDirectory(string $p): \Generator
    {
        yield from $this->generateStringChunk('(', 'type', 'directory');

        $iterator = new SortIterableAggregate(
            new \FilesystemIterator(
                $p,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
            ),
            // As per Nix specification, directory entries must be sorted by name.
            static fn (\SplFileInfo $a, \SplFileInfo $b): int => $a->getFilename() <=> $b->getFilename()
        );

        foreach ($iterator as $entry) {
            yield from $this->generateStringChunk('entry', '(', 'name', $entry->getFilename(), 'node');

            yield from $this->generateObjectChunk($entry->getPathname());

            yield from $this->generateStringChunk(')');
        }

        yield from $this->generateStringChunk(')');
    }

    /**
     * @return \Generator<string>
     */
    private function handleRegularFile(string $p): \Generator
    {
        yield from $this->generateStringChunk('(', 'type', 'regular');

        if (is_file($p) && is_executable($p)) {
            yield from $this->generateStringChunk('executable', '');
        }

        $fileHandle = fopen($p, 'r');

        if (false === $fileHandle) {
            throw new \RuntimeException("Cannot open file for reading: {$p}");
        }

        // Use fstat -> size to avoid a separate filesize() call (race + extra syscall).
        $stat = fstat($fileHandle);
        $size = $stat['size'] ?? null;

        if (!\is_int($size)) {
            fclose($fileHandle);

            throw new \RuntimeException("Cannot get size of file: {$p}");
        }

        yield from $this->generateStringChunk('contents');

        yield $this->u64le($size);

        while (!feof($fileHandle)) {
            $chunk = fread($fileHandle, 8192);
            if (false === $chunk) {
                fclose($fileHandle);

                throw new \RuntimeException("Error reading file: {$p}");
            }

            yield $chunk;
        }
        fclose($fileHandle);

        $pad = (8 - ($size % 8)) % 8;

        if ($pad) {
            yield str_repeat("\x00", $pad);
        }

        yield from $this->generateStringChunk(')');
    }

    private function handleSymlink(string $p): \Generator
    {
        yield from $this->generateStringChunk('(', 'type', 'symlink', 'target', readlink($p) ?: '', ')');
    }

    /**
     * Encodes an unsigned 64-bit integer in little-endian (2 × uint32 LE).
     *
     * @return string eight bytes (LE)
     */
    private function u64le(int $n): string
    {
        $lo = $n & 0xFFFFFFFF;
        $hi = ($n >> 32) & 0xFFFFFFFF;

        return pack('V2', $lo, $hi);
    }

    /**
     * @param resource $handle
     */
    private function assertString($handle, string $expected): void
    {
        $s = $this->readString($handle);

        if ($s !== $expected) {
            throw new \RuntimeException("NAR format error: expected '{$expected}', got '{$s}'");
        }
    }

    /**
     * @param resource $handle
     */
    private function readBytes($handle, int $len): string
    {
        if (0 === $len) {
            return '';
        }
        $bytes = fread($handle, $len);

        if (false === $bytes || \strlen($bytes) !== $len) {
            throw new \RuntimeException('NAR read error: unexpected EOF');
        }

        return $bytes;
    }

    /**
     * @param resource $handle
     */
    private function readString($handle): string
    {
        $len = $this->readU64LE($handle);
        $str = $this->readBytes($handle, $len);
        $pad = (8 - ($len % 8)) % 8;

        if ($pad > 0) {
            $this->readBytes($handle, $pad);
        }

        return $str;
    }

    /**
     * @param resource $handle
     */
    private function readU64LE($handle): int
    {
        $bytes = $this->readBytes($handle, 8);
        $parts = unpack('V2', $bytes);

        if (false === $parts) {
            throw new \RuntimeException('NAR read error: could not unpack uint64_t');
        }

        return $parts[1] + ($parts[2] << 32);
    }

    /**
     * @param resource $handle
     */
    private function unpackObject($handle, string $path): void
    {
        $this->assertString($handle, '(');

        $type = '';
        $isExecutable = false;
        $target = '';

        while (true) {
            $key = $this->readString($handle);

            if (')' === $key) {
                break;
            }

            switch ($key) {
                case 'type':
                    $type = $this->readString($handle);

                    break;

                case 'executable':
                    $this->assertString($handle, '');
                    $isExecutable = true;

                    break;

                case 'target':
                    $target = $this->readString($handle);

                    break;

                case 'contents':
                    if ('regular' !== $type) {
                        throw new \RuntimeException("NAR format error: 'contents' outside of regular file.");
                    }
                    $len = $this->readU64LE($handle);

                    $dir = \dirname($path);

                    if (!file_exists($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                        throw new \RuntimeException("Cannot create directory: {$dir}");
                    }

                    $out = fopen($path, 'w');

                    if (false === $out) {
                        throw new \RuntimeException("Cannot open file for writing: {$path}");
                    }
                    $remaining = $len;

                    while ($remaining > 0) {
                        $chunkSize = min($remaining, 8192);
                        $chunk = $this->readBytes($handle, $chunkSize);
                        fwrite($out, $chunk);
                        $remaining -= $chunkSize;
                    }
                    fclose($out);
                    $pad = (8 - ($len % 8)) % 8;

                    if ($pad > 0) {
                        $this->readBytes($handle, $pad);
                    }

                    if ($isExecutable) {
                        chmod($path, fileperms($path) | 0o111);
                    }

                    break;

                case 'entry':
                    if ('directory' !== $type) {
                        throw new \RuntimeException("NAR format error: 'entry' outside of directory.");
                    }
                    $this->assertString($handle, '(');
                    $this->assertString($handle, 'name');
                    $name = $this->readString($handle);
                    $this->assertString($handle, 'node');
                    $this->unpackObject($handle, \sprintf('%s%s%s', $path, \DIRECTORY_SEPARATOR, $name));
                    $this->assertString($handle, ')');

                    break;

                default:
                    throw new \RuntimeException("NAR format error: unknown key '{$key}'");
            }
        }

        switch ($type) {
            case 'directory':
                if (!file_exists($path) && !mkdir($path, 0o755, true) && !is_dir($path)) {
                    throw new \RuntimeException("Cannot create directory: {$path}");
                }

                break;

            case 'symlink':
                // Ensure parent exists.
                $dir = \dirname($path);
                if (!file_exists($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                    throw new \RuntimeException("Cannot create directory: {$dir}");
                }

                // If something already exists at $path, remove it so symlink can be created.
                if (file_exists($path) || is_link($path)) {
                    if (!unlink($path)) {
                        throw new \RuntimeException("Cannot remove existing path to create symlink: {$path}");
                    }
                }

                if (false === symlink($target, $path)) {
                    throw new \RuntimeException("Failed to create symlink: {$path} -> {$target}");
                }

                break;

            case 'regular':
                // Handled by 'contents' case
                break;

            default:
                throw new \RuntimeException("NAR format error: unknown type '{$type}'");
        }
    }
}
