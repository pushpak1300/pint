<?php

namespace App\Repositories;

use LogicException;
use Symfony\Component\Console\Input\InputInterface;

class ConfigurationJsonRepository
{
    /**
     * Lists the finder options.
     *
     * @var array<int, string>
     */
    protected $finderOptions = [
        'exclude',
        'in',
        'notPath',
        'notName',
    ];

    /**
     * Create a new Configuration Json Repository instance.
     *
     * @param  string|null  $path
     * @param  string|null  $preset
     * @return void
     */
    public function __construct(
        protected $path,
        protected $preset,
    ) {
        //
    }

    /**
     * Get the finder options.
     *
     * @return array<string, array<int, string>|string>
     */
    public function finder()
    {
        return collect($this->get())->filter(fn ($value, $key) => in_array($key, $this->finderOptions))->toArray();
    }

    /** @return array<string, mixed> */
    public function formatter(): array
    {
        return $this->get()['formatter'] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    public function linter(): array
    {
        return $this->get()['linter']['rules'] ?? [];
    }

    public function phpVersion(): string
    {
        return $this->get()['php-version'] ?? PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
    }

    public function fixSafety(): string
    {
        $safety = $this->get()['fix-safety'] ?? 'safe';
        if (! in_array($safety, ['safe', 'potentially-unsafe', 'unsafe'], true)) {
            abort(1, 'Configuration [fix-safety] must be safe, potentially-unsafe, or unsafe.');
        }

        return $safety;
    }

    public function blade(): bool
    {
        if (app()->bound(InputInterface::class)) {
            $input = resolve(InputInterface::class);

            if ($input->hasOption('blade') && $input->getOption('blade') === true) {
                return true;
            }
        }

        return $this->get()['blade'] ?? false;
    }

    /**
     * Get the cache file location.
     *
     * @return string|null
     */
    public function cacheFile()
    {
        return $this->get()['cache-file'] ?? null;
    }

    /**
     * Determine if the configuration defines paths to inspect.
     *
     * @return bool
     */
    public function hasIncludedPaths()
    {
        return ! empty($this->finder()['in'] ?? null);
    }

    /**
     * Get the preset option.
     *
     * @return string
     */
    public function preset()
    {
        $preset = $this->preset ?: $this->get()['preset'] ?? 'laravel';

        if ($preset === 'empty') {
            abort(
                1,
                'The [empty] preset was removed in Pint 2.x. Select laravel, per, psr12, or symfony and configure formatter/linter settings.',
            );
        }

        if (! in_array($preset, ['laravel', 'per', 'psr12', 'symfony'], true)) {
            abort(1, 'Preset not found.');
        }

        return $preset;
    }

    /**
     * Get the configuration from the "pint.json" file.
     *
     * @return array<string, mixed>
     */
    protected function get()
    {
        if (! is_null($this->path) && $this->fileExists((string) $this->path)) {
            $baseConfig = json_decode(file_get_contents($this->path), true);

            if (! is_array($baseConfig)) {
                abort(1, sprintf('The configuration file [%s] is not valid JSON.', $this->path));
            }

            if (isset($baseConfig['extend'])) {
                $baseConfig = $this->resolveExtend($baseConfig);
            }

            return tap($baseConfig, function ($configuration) {
                if (array_key_exists('rules', $configuration)) {
                    abort(
                        1,
                        'PHP-CS-Fixer [rules] are not supported in Pint 2.x. Use [formatter], [linter.rules], and [blade] instead. See UPGRADE.md.',
                    );
                }
                foreach (['formatter', 'linter'] as $key) {
                    if (isset($configuration[$key]) && ! is_array($configuration[$key])) {
                        abort(1, "Configuration [{$key}] must be an object.");
                    }
                }
                if (isset($configuration['blade']) && ! is_bool($configuration['blade'])) {
                    abort(1, 'Configuration [blade] must be a boolean.');
                }
                if (array_diff(array_keys($configuration['linter'] ?? []), ['rules']) !== []) {
                    abort(1, 'Only [linter.rules] is supported; Pint controls file selection and reporting.');
                }
                if (isset($configuration['linter']['rules']) && ! is_array($configuration['linter']['rules'])) {
                    abort(1, 'Configuration [linter.rules] must be an object.');
                }
            });
        }

        return [];
    }

    /**
     * Determine if a local or remote file exists.
     *
     * @return bool
     */
    protected function fileExists(string $path)
    {
        return match (true) {
            str_starts_with($path, 'http://') => abort(
                1,
                'Loading the configuration over plaintext HTTP is not allowed. Use HTTPS.',
            ),
            str_starts_with($path, 'https://') => str_contains(get_headers($path)[0], '200 OK'),
            default => file_exists($path),
        };
    }

    /**
     * Resolve the file to extend.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function resolveExtend(array $configuration)
    {
        $path = realpath(dirname($this->path).DIRECTORY_SEPARATOR.$configuration['extend']);

        $extended = json_decode(file_get_contents($path), true);

        if (isset($extended['extend'])) {
            throw new LogicException('Pint configuration cannot extend from more than 1 file.');
        }

        return array_replace_recursive($extended, $configuration);
    }
}
