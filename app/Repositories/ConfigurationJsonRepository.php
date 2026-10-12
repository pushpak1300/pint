<?php

namespace App\Repositories;

use App\Mago\Cleanup;
use App\Support\ProjectVersions;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
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

    /**
     * Get the native formatter options.
     *
     * @return array<string, mixed>
     */
    public function formatter(): array
    {
        $configuration = $this->get();
        $rules = $configuration['refactor']['rules'] ?? [];
        $options = [];

        if (array_key_exists('concat_space', $rules)) {
            $options['space-around-concatenation-binary-operator'] =
                ($rules['concat_space']['spacing'] ?? 'none') === 'one';
        }

        if (array_key_exists('blank_line_before_statement', $rules)) {
            $options['empty-line-before-return'] = $rules['blank_line_before_statement'] !== false;
        }

        return $this->mergeAliases($options, $this->nativeOptions($configuration['format'] ?? []), 'rules', 'format');
    }

    /**
     * Get the selected lint rules and their options.
     *
     * @return array<string, array<string, mixed>>
     */
    public function linter(): array
    {
        $configuration = $this->get();
        $rules = $configuration['refactor']['rules'] ?? [];
        $options = [];

        if (array_key_exists('array_syntax', $rules)) {
            $options['array-style'] = [
                'enabled' => $rules['array_syntax'] !== false,
                'style' => $rules['array_syntax']['syntax'] ?? 'short',
            ];
        }

        if (array_key_exists('no_unused_imports', $rules)) {
            $options['no-redundant-use'] = ['enabled' => $rules['no_unused_imports'] !== false];
        }

        return $this->mergeAliases($options, $configuration['lint']['rules'] ?? [], 'rules', 'lint.rules');
    }

    /**
     * Get explicitly selected Pint-owned compatibility rules.
     *
     * @return array<string, bool|array<string, mixed>>
     */
    public function cleanup(): array
    {
        return $this->get()['refactor']['rules'] ?? [];
    }

    /** @return array<string, mixed> */
    public function analyzer(): array
    {
        return array_replace(['strictness' => 'balanced'], $this->nativeOptions($this->get()['analyze'] ?? []));
    }

    /** @return array<string, mixed> */
    public function guard(): array
    {
        return $this->nativeOptions($this->get()['guard'] ?? []);
    }

    public function sectionEnabled(string $section): bool
    {
        if (! in_array($section, ['format', 'lint', 'analyze', 'guard', 'refactor'], true)) {
            throw new InvalidArgumentException("Unknown configuration section [{$section}].");
        }

        return $this->get()[$section]['enabled'] ?? true;
    }

    /** @return array{php: string, laravel: string} */
    public function projectVersions(string $root): array
    {
        $configuration = $this->get();

        return [
            'php' => ProjectVersions::php($root, $configuration['php-version'] ?? null),
            'laravel' => ProjectVersions::laravel($root, $configuration['laravel-version'] ?? null),
        ];
    }

    /**
     * Get the PHP version to target when formatting source files.
     */
    public function phpVersion(): string
    {
        $override = $this->get()['php-version'] ?? null;
        $root = getcwd();

        if ($override !== null) {
            return ProjectVersions::php($root, $override);
        }

        try {
            return ProjectVersions::php($root);
        } catch (RuntimeException) {
            // Formatting remains standalone; projectVersions requires a known floor for analysis.
            return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        }
    }

    /**
     * Get the allowed safety level for lint fixes.
     */
    public function fixSafety(): string
    {
        $safety = $this->get()['fix-safety'] ?? 'safe';

        if (! in_array($safety, ['safe', 'potentially-unsafe', 'unsafe'], true)) {
            abort(1, 'Configuration [fix-safety] must be safe, potentially-unsafe, or unsafe.');
        }

        return $safety;
    }

    /**
     * Determine whether Blade formatting is enabled.
     */
    public function blade(): bool
    {
        if (app()->bound(InputInterface::class)) {
            $input = resolve(InputInterface::class);

            if ($input->hasOption('blade') && $input->getOption('blade') === true) {
                return true;
            }
        }

        $configuration = $this->get();

        return $configuration['blade'] ?? $configuration['refactor']['rules']['Pint/laravel_blade'] ?? false;
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

    public function baseline(): ?string
    {
        $baseline = $this->get()['baseline'] ?? null;
        if ($baseline !== null && (! is_string($baseline) || $baseline === '')) {
            throw new RuntimeException('Configuration [baseline] must be a nonempty path string.');
        }

        return $baseline;
    }

    /**
     * Get the canonical paths of the effective local configuration files.
     *
     * @return list<string>
     */
    public function localPaths(): array
    {
        $path = $this->path === null ? false : realpath($this->path);

        if ($path === false) {
            return [];
        }

        $configuration = $this->get();
        $paths = [$path];

        if (isset($configuration['extend'])) {
            $paths[] = realpath(dirname($this->path).DIRECTORY_SEPARATOR.$configuration['extend']);
        }

        return array_values(array_filter($paths));
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

            $baseConfig = tap($baseConfig, function ($configuration) {
                if (array_key_exists('rules', $configuration)) {
                    if (
                        ! is_array($configuration['rules'])
                        || $configuration['rules'] !== []
                        && array_is_list($configuration['rules'])
                    ) {
                        abort(1, 'Configuration [rules] must be an object.');
                    }

                    Cleanup::validate($configuration['rules']);
                }

                foreach (['formatter', 'linter', 'format', 'lint', 'analyze', 'guard', 'refactor'] as $key) {
                    if (
                        array_key_exists($key, $configuration)
                        && (
                            ! is_array($configuration[$key])
                            || $configuration[$key] !== []
                            && array_is_list($configuration[$key])
                        )
                    ) {
                        abort(1, "Configuration [{$key}] must be an object.");
                    }

                    if (
                        array_key_exists('enabled', $configuration[$key] ?? [])
                        && ! is_bool($configuration[$key]['enabled'])
                    ) {
                        abort(1, "Configuration [{$key}.enabled] must be a boolean.");
                    }
                }

                if (array_key_exists('blade', $configuration) && ! is_bool($configuration['blade'])) {
                    abort(1, 'Configuration [blade] must be a boolean.');
                }

                if (array_diff(array_keys($configuration['linter'] ?? []), ['rules']) !== []) {
                    abort(1, 'Only [linter.rules] is supported; Pint controls file selection and reporting.');
                }

                foreach (['lint', 'linter', 'refactor'] as $section) {
                    if (array_diff(array_keys($configuration[$section] ?? []), ['enabled', 'rules']) !== []) {
                        abort(1, "Only [{$section}.rules] and [{$section}.enabled] are supported.");
                    }

                    if (
                        array_key_exists('rules', $configuration[$section] ?? [])
                        && (
                            ! is_array($configuration[$section]['rules'])
                            || $configuration[$section]['rules'] !== []
                            && array_is_list($configuration[$section]['rules'])
                        )
                    ) {
                        abort(1, "Configuration [{$section}.rules] must be an object.");
                    }
                }

                foreach (['lint', 'linter'] as $section) {
                    foreach ($configuration[$section]['rules'] ?? [] as $name => $options) {
                        if (! is_array($options) || $options !== [] && array_is_list($options)) {
                            abort(1, "Configuration [{$section}.rules.{$name}] must be an options object.");
                        }

                        if (array_key_exists('enabled', $options) && ! is_bool($options['enabled'])) {
                            abort(1, "Configuration [{$section}.rules.{$name}.enabled] must be a boolean.");
                        }
                    }
                }

                Cleanup::validate($configuration['refactor']['rules'] ?? []);

                if (
                    array_key_exists('strictness', $configuration['analyze'] ?? [])
                    && ! in_array($configuration['analyze']['strictness'], ['balanced', 'strict'], true)
                ) {
                    abort(1, 'Configuration [analyze.strictness] must be balanced or strict.');
                }

                foreach (['php-version', 'laravel-version'] as $key) {
                    if (array_key_exists($key, $configuration) && ! is_string($configuration[$key])) {
                        abort(1, "Configuration [{$key}] must be a version string.");
                    }
                }
            });

            $baseConfig['format'] = $this->mergeAliases(
                $baseConfig['formatter'] ?? [],
                $baseConfig['format'] ?? [],
                'formatter',
                'format',
            );
            $baseConfig['lint'] = $this->mergeAliases(
                $baseConfig['linter'] ?? [],
                $baseConfig['lint'] ?? [],
                'linter',
                'lint',
            );
            $baseConfig['refactor']['rules'] = $this->mergeAliases(
                $baseConfig['rules'] ?? [],
                $baseConfig['refactor']['rules'] ?? [],
                'rules',
                'refactor.rules',
            );

            if (
                isset($baseConfig['blade'], $baseConfig['refactor']['rules']['Pint/laravel_blade'])
                && $baseConfig['blade'] !== $baseConfig['refactor']['rules']['Pint/laravel_blade']
            ) {
                abort(
                    1,
                    'Conflicting configuration [blade] and [refactor.rules.Pint/laravel_blade] (legacy [rules.Pint/laravel_blade]).',
                );
            }

            return $baseConfig;
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function nativeOptions(array $options): array
    {
        unset($options['enabled']);

        return $options;
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @param  array<string, mixed>  $canonical
     * @return array<string, mixed>
     */
    private function mergeAliases(array $legacy, array $canonical, string $old, string $new): array
    {
        foreach ($legacy as $key => $value) {
            if (array_key_exists($key, $canonical)) {
                if (
                    is_array($value)
                    && is_array($canonical[$key])
                    && (
                        $value === []
                        || ! array_is_list($value)
                    )
                    && (
                        $canonical[$key] === []
                        || ! array_is_list($canonical[$key])
                    )
                ) {
                    $canonical[$key] = $this->mergeAliases($value, $canonical[$key], $old.'.'.$key, $new.'.'.$key);
                } elseif ($value !== $canonical[$key]) {
                    abort(1, "Conflicting configuration [{$old}.{$key}] and [{$new}.{$key}].");
                }
            } else {
                $canonical[$key] = $value;
            }
        }

        return array_replace_recursive($legacy, $canonical);
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
