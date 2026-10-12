<?php

namespace App\Support;

use Composer\Semver\Intervals;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

final class ProjectVersions
{
    public static function php(string $root, ?string $override = null): string
    {
        if ($override !== null) {
            return self::version($override, 'php-version');
        }

        $composer = self::read($root.'/composer.json');
        $constraint = $composer['require']['php'] ?? null;

        if (! is_string($constraint)) {
            throw new RuntimeException(
                'Cannot infer PHP version: composer.json must declare [require.php]; set [php-version].',
            );
        }

        try {
            $intervals = Intervals::get(new VersionParser()->parseConstraints($constraint));
        } catch (UnexpectedValueException $error) {
            throw new RuntimeException('Invalid Composer PHP constraint ['.$constraint.'].', previous: $error);
        }

        foreach ($intervals['numeric'] as $interval) {
            $start = $interval->getStart();

            if (str_starts_with($start->getVersion(), '0.')) {
                break;
            }

            $version = self::version($start->getVersion(), 'require.php');

            if ($start->getOperator() === '>' && ! str_contains($start->getVersion(), '-')) {
                $parts = array_pad(explode('.', $version), 3, '0');
                $version = $parts[0].'.'.$parts[1].'.'.((int) $parts[2] + 1);
            }

            if (Semver::satisfies($version, $constraint)) {
                return $version;
            }
        }

        throw new RuntimeException('Cannot infer a PHP floor from ['.$constraint.']; set [php-version].');
    }

    public static function laravel(string $root, ?string $override = null): string
    {
        $version = $override;

        if ($version === null) {
            $installed = self::read($root.'/vendor/composer/installed.json');
            $packages = $installed['packages'] ?? $installed;

            if (! is_array($packages)) {
                throw new RuntimeException('Invalid package list in [vendor/composer/installed.json].');
            }

            foreach ($packages as $package) {
                if (is_array($package) && ($package['name'] ?? null) === 'laravel/framework') {
                    $version = $package['version'] ?? $package['version_normalized'] ?? null;
                    break;
                }
            }
        }

        if (! is_string($version)) {
            throw new RuntimeException(
                'Cannot infer Laravel version: install [laravel/framework] or set [laravel-version].',
            );
        }

        $version = self::version($version, 'laravel-version');

        if (! in_array(explode('.', $version)[0], ['12', '13'], true)) {
            throw new RuntimeException('Unsupported Laravel version ['.$version.']; supported majors are 12 and 13.');
        }

        return $version;
    }

    private static function version(string $version, string $setting): string
    {
        if (! preg_match(
            '/^v?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.0)?(?:-(?:dev|alpha|beta|RC)[\w.-]*)?$/',
            $version,
            $matches,
        )) {
            throw new RuntimeException(
                'Cannot resolve ['.$setting.'] version ['.$version.']; specify a numeric version.',
            );
        }

        return (
            (int) $matches[1].'.'.(int) ($matches[2] ?? 0).((int) ($matches[3] ?? 0) === 0 ? '' : '.'.(int) $matches[3])
        );
    }

    /** @return array<string|int, mixed> */
    private static function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException(
                'Cannot infer project versions: missing ['
                .$path
                .']. Install Composer dependencies or configure explicit versions.',
            );
        }

        try {
            $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Invalid JSON in ['.$path.'].', previous: $error);
        }

        if (! is_array($data)) {
            throw new RuntimeException('Expected a JSON object or package list in ['.$path.'].');
        }

        return $data;
    }
}
