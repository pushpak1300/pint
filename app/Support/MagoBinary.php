<?php

namespace App\Support;

use RuntimeException;

final class MagoBinary
{
    public const VERSION = '1.53.0';

    public static function path(): string
    {
        $platform = self::platform(PHP_OS_FAMILY, php_uname('m'));
        $root = dirname(__DIR__, 2).'/resources/mago';
        $manifest = is_file($root.'/manifest.json')
            ? json_decode(file_get_contents($root.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR)
            : null;
        $entry = $manifest['platforms'][$platform] ?? null;

        if (($manifest['version'] ?? null) !== self::VERSION || ! is_array($entry)) {
            throw new RuntimeException(
                'Bundled Mago is missing. In a source checkout run python3 scripts/stage-mago.py; otherwise reinstall Pint.',
            );
        }

        $relative = $platform.'/'.($platform === 'windows-x64' ? 'mago.exe' : 'mago');
        if (($entry['file'] ?? null) !== $relative || ! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) {
            throw new RuntimeException('Invalid bundled Mago manifest; reinstall Pint.');
        }

        $source = $root.'/'.$relative;
        self::verify($source, $entry['sha256']);

        if (! str_starts_with($source, 'phar://')) {
            if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($source)) {
                throw new RuntimeException('Bundled Mago is not executable; run python3 scripts/stage-mago.py.');
            }

            return $source;
        }

        $home = PHP_OS_FAMILY === 'Windows' ? getenv('LOCALAPPDATA') : getenv('HOME');
        if (! $home || ! is_dir($home)) {
            throw new RuntimeException(
                'Mago needs a writable private cache: set HOME (Unix) or LOCALAPPDATA (Windows).',
            );
        }

        $cache = $home.'/.pint-mago';
        if (! is_dir($cache) && ! @mkdir($cache, 0700) && ! is_dir($cache)) {
            throw new RuntimeException(
                'Cannot create private Mago cache at '.$cache.'. Check directory permissions.',
            );
        }
        clearstatcache(true, $cache);
        if (
            is_link($cache)
            || ! is_writable($cache)
            || PHP_OS_FAMILY !== 'Windows'
            && (
                (fileperms($cache) & 0077) !== 0
                || fileowner($cache) !== fileowner($home)
            )
        ) {
            throw new RuntimeException(
                'Unsafe Mago cache at '.$cache.'. Use an owned directory with mode 0700, not a symlink.',
            );
        }

        $target =
            $cache.'/'.self::VERSION.'-'.$platform.'-'.$entry['sha256'].($platform === 'windows-x64' ? '.exe' : '');
        $lockPath = $cache.'/extract.lock';
        if (is_link($lockPath) || ! ($lock = @fopen($lockPath, 'c')) || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Cannot lock Mago cache at '.$cache.'.');
        }

        $temporary = null;
        try {
            clearstatcache(true, $target);
            if (is_link($target)) {
                throw new RuntimeException('Unsafe symlink in Mago cache: '.$target);
            }
            if (! is_file($target) || hash_file('sha256', $target) !== $entry['sha256']) {
                $temporary = tempnam($cache, '.extract-');
                if ($temporary === false || dirname($temporary) !== realpath($cache) || ! copy($source, $temporary)) {
                    throw new RuntimeException('Cannot extract bundled Mago into '.$cache.'.');
                }
                self::verify($temporary, $entry['sha256']);
                if (! chmod($temporary, 0700) || ! @rename($temporary, $target)) {
                    throw new RuntimeException('Cannot install bundled Mago into '.$cache.'.');
                }
            }
            self::verify($target, $entry['sha256']);
            if (PHP_OS_FAMILY !== 'Windows' && ! is_executable($target) && ! chmod($target, 0700)) {
                throw new RuntimeException('Cannot make cached Mago executable: '.$target);
            }

            return $target;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function platform(string $os, string $architecture): string
    {
        $arch = match (strtolower($architecture)) {
            'x86_64', 'amd64' => 'x64',
            'aarch64', 'arm64' => 'arm64',
            default => null,
        };
        $family = match ($os) {
            'Linux' => 'linux',
            'Darwin' => 'darwin',
            'Windows' => 'windows',
            default => null,
        };

        if ($family === null || $arch === null || $family === 'windows' && $arch !== 'x64' || PHP_INT_SIZE !== 8) {
            throw new RuntimeException(
                "Mago does not support {$os}/{$architecture}. Use 64-bit PHP on Linux or macOS x64/ARM64, or Windows x64 (Windows ARM64 has no official binary).",
            );
        }

        return $family.'-'.$arch;
    }

    private static function verify(string $file, string $digest): void
    {
        if (! is_file($file) || hash_file('sha256', $file) !== $digest) {
            throw new RuntimeException(
                'Bundled Mago checksum mismatch: '.$file.'. Reinstall Pint or restage its binaries.',
            );
        }
    }
}
