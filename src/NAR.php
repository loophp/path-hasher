<?php

declare(strict_types=1);

/**
 * Nix NAR Hasher.
 *
 * WHAT IT DOES
 * ------------
 * Computes the “NAR hash” of a filesystem path — that is, the SHA-256
 * of its serialisation in the NAR (Nix ARchive) format.
 * Returns results in three encodings:
 *   - hexadecimal (64 characters, standard SHA-256)
 *   - SRI (“sha256-<base64>” form)
 *   - Nix base32 (custom alphabet, least-significant-bit-first; 52 characters)
 *
 * The NAR format is a deterministic serialisation used by Nix to represent
 * directories, files and symlinks independently of the underlying system.
 * The “hash of a path” in Nix corresponds exactly to:
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
 *   • Regular files include an optional “executable” key if the exec bit is set,
 *     followed by “contents” with raw file data.
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
    public function dumpNar(string $path): \Generator
    {
        if (!file_exists($path) && !is_link($path)) {
            throw new \RuntimeException("Path not found: {$path}");
        }

        yield from $this->generateStringChunk('nix-archive-1');

        yield from $this->generateObjectChunk($path);
    }

    public function hash(string $path): string
    {
        $hashes = $this->hashPath($path);

        return $hashes['sri'];
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
    public function hashPath(string $path): array
    {
        $context = hash_init($this->hashAlgorithm);

        foreach ($this->dumpNar($path) as $chunk) {
            hash_update($context, $chunk);
        }

        $raw = hash_final($context, true);

        $hex = bin2hex($raw);
        $b64 = base64_encode($raw);
        $sri = \sprintf('%s-%s', $this->hashAlgorithm, $b64);
        $nix32 = $this->toNixBase32($raw);

        return ['hex' => $hex, 'sri' => $sri, 'nix32' => $nix32];
    }

    /**
     * Encodes a byte string into Nix base32 (custom alphabet, LSB-first, no padding).
     *
     * Implemented via successive integer division (base-256 → base-32),
     * producing least-significant-bit-first digits, then padded to the expected length.
     */
    public function toNixBase32(string $bytes): string
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
        yield from $this->generateStringChunk('(');

        yield from match (true) {
            is_link($p) => $this->handleSymlink($p),
            is_dir($p) => $this->handleDirectory($p),
            default => $this->handleRegularFile($p),
        };

        yield from $this->generateStringChunk(')');
    }

    /**
     * @return \Generator<string>
     */
    private function generateStringChunk(string $s): \Generator
    {
        // str(s) = uint64LE(length) + s + zero-padding to a multiple of 8 bytes
        $len = \strlen($s);

        yield $this->u64le($len);

        yield $s;
        $pad = (8 - ($len % 8)) % 8;

        if ($pad) {
            yield str_repeat("\x00", $pad);
        }
    }

    private function handleDirectory(string $p): \Generator
    {
        yield from $this->generateStringChunk('type');

        yield from $this->generateStringChunk('directory');

        $iterator = new SortIterableAggregate(
            new \RecursiveDirectoryIterator(
                $p,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
            ),
            static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp(
                $a->getPathname(),
                $b->getPathname()
            )
        );

        foreach ($iterator as $entry) {
            yield from $this->generateStringChunk('entry');

            yield from $this->generateStringChunk('(');

            yield from $this->generateStringChunk('name');

            yield from $this->generateStringChunk(substr($entry->getPathname(), \strlen($p) + 1));

            yield from $this->generateStringChunk('node');

            yield from $this->generateObjectChunk($entry->getPathname());

            yield from $this->generateStringChunk(')');
        }
    }

    /**
     * @return \Generator<string>
     */
    private function handleRegularFile(string $p): \Generator
    {
        yield from $this->generateStringChunk('type');

        yield from $this->generateStringChunk('regular');

        if (is_file($p) && is_executable($p)) {
            yield from $this->generateStringChunk('executable');

            yield from $this->generateStringChunk('');
        }

        yield from $this->generateStringChunk('contents');

        $size = filesize($p);

        if (false === $size) {
            throw new \RuntimeException("Cannot get size of file: {$p}");
        }

        yield $this->u64le($size);

        $fileHandle = fopen($p, 'r');

        if (false === $fileHandle) {
            throw new \RuntimeException("Cannot open file for reading: {$p}");
        }

        while (!feof($fileHandle)) {
            yield fread($fileHandle, 8192);
        }
        fclose($fileHandle);

        $pad = (8 - ($size % 8)) % 8;

        if ($pad) {
            yield str_repeat("\x00", $pad);
        }
    }

    private function handleSymlink(string $p): \Generator
    {
        yield from $this->generateStringChunk('type');

        yield from $this->generateStringChunk('symlink');

        yield from $this->generateStringChunk('target');

        yield from $this->generateStringChunk(readlink($p) ?: '');
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
}
