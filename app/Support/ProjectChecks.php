<?php

namespace App\Support;

use App\Factories\ConfigurationFactory;
use App\Repositories\ConfigurationJsonRepository;
use Phar;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

final class ProjectChecks
{
    private ?string $directory = null;

    private string $binary;

    /** @var array<string, false> */
    private array $environment = [];

    /**
     * Analyze real project paths: staged formatter files cannot resolve project types.
     *
     * @param list<string> $files
     */
    public function __construct(
        ConfigurationJsonRepository $configuration,
        private string $root,
        private array $files,
        private int $threads = 2,
    ) {
        $versions = $configuration->projectVersions($root);
        $this->binary = MagoBinary::path();

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'MAGO_')) {
                $this->environment[$name] = false;
            }
        }

        $this->directory = sys_get_temp_dir().'/pint-checks-'.bin2hex(random_bytes(12));
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create project checks configuration directory.');
        }

        try {
            file_put_contents($this->directory.'/mago.json', '{}');
            $defaults = json_decode(
                $this->process(['config', '--default'])->getOutput(),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $rules = array_fill_keys(array_keys($defaults['linter']['rules']), ['enabled' => false]);

            // Curated Laravel policy, not every native rule. Finality stays opt-in.
            foreach ([
                'no-request-all' => 'Warning',
                'middleware-in-routes' => 'Warning',
                'prefer-anonymous-migration' => 'Warning',
                'prefer-array-validation-rules' => 'Error',
                'prefer-casts-method' => 'Note',
                'prefer-dedicated-status-assertion' => 'Note',
                'prefer-fake-helper' => 'Note',
                'prefer-view-array' => 'Help',
            ] as $name => $level) {
                $rules[$name] = ['enabled' => true, 'level' => $level];
            }

            foreach ($configuration->linter() as $name => $options) {
                if (! array_key_exists($name, $rules)) {
                    throw new RuntimeException("Unknown Mago lint rule [{$name}].");
                }

                $rules[$name] = array_replace(['level' => 'Error'], $options);
            }

            $analyzer = $configuration->analyzer();
            $strict = $analyzer['strictness'] === 'strict';
            unset($analyzer['strictness']);

            $includes = [];
            $selected = array_fill_keys($files, true);
            foreach (ConfigurationFactory::finder(false)->in($root) as $file) {
                $path = $file->getRealPath();
                if (! str_ends_with($path, '.blade.php') && ! isset($selected[$path])) {
                    $includes[] = $path;
                }
            }
            if (is_dir($root.'/vendor')) {
                $includes[] = $root.'/vendor';
            }

            $guard = $configuration->guard();
            $structural = [];
            foreach ([
                'Http\\Controllers' => 'Controller',
                'Http\\Requests' => 'Request',
                'Policies' => 'Policy',
            ] as $namespace => $suffix) {
                $structural[] = [
                    'on' => 'App\\'.$namespace.'\\**',
                    'target' => 'class',
                    'must-be-named' => '*'.$suffix,
                ];
            }

            $settings = [
                'php-version' => $versions['php'],
                'source' => ['paths' => $files, 'includes' => $includes],
                'linter' => ['integrations' => ['laravel'], 'rules' => $rules],
                'analyzer' => array_replace([
                    'find-unused-definitions' => false,
                    'find-unused-expressions' => false,
                    'check-missing-type-hints' => $strict,
                    'check-throws' => $strict,
                    'check-property-initialization' => $strict,
                ], $analyzer),
                'guard' => array_replace(['structural' => ['rules' => $structural]], $guard),
            ];

            $phar = Phar::running(false);
            $settings['extension-hosts']['pint-laravel'] = [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'if ($argv[2] !== "") { Phar::loadPhar($argv[2]); } require $argv[1]; App\\Mago\\Laravel::work();',
                    $phar !== '' ? 'phar://'.$phar.'/vendor/autoload.php' : dirname(__DIR__, 2).'/vendor/autoload.php',
                    $phar,
                ],
                'workers' => 1,
            ];

            // A single Pint baseline/reporting policy must not be overridden inside native sections.
            foreach (['analyzer', 'guard'] as $section) {
                foreach (['baseline', 'baseline-variant', 'minimum-fail-level', 'excludes'] as $key) {
                    if (array_key_exists($key, $settings[$section])) {
                        throw new RuntimeException(
                            "Use Pint file selection, --strict, and --baseline instead of [{$section}.{$key}].",
                        );
                    }
                }
            }

            file_put_contents($this->directory.'/mago.json', json_encode($settings, JSON_THROW_ON_ERROR));
            $this->process(['config']);

            // Scan unselected application models too, but keep fixes restricted to selected files.
            $settings['source'] = [
                'paths' => [
                    ...$files,
                    ...array_values(array_filter($includes, fn ($path) => $path !== $root.'/vendor')),
                ],
                'includes' => is_dir($root.'/vendor') ? [$root.'/vendor'] : [],
            ];
            file_put_contents($this->directory.'/analyze.json', json_encode($settings, JSON_THROW_ON_ERROR));
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /**
     * @param list<string> $stages
     */
    public function fix(array $stages): void
    {
        if ($this->files === [] || $stages === []) {
            return;
        }

        $arguments = ['fix', '--no-fmt', '--unsafe', '--ignore-baseline'];
        foreach (['lint', 'analyze', 'guard'] as $stage) {
            if (! in_array($stage, $stages, true)) {
                $arguments[] = '--no-'.$stage;
            }
        }
        $this->process($arguments);
    }

    /** @return list<array<string, mixed>> */
    public function check(string $stage): array
    {
        if ($this->files === []) {
            return [];
        }

        $process = $this->process([$stage, '--reporting-format', 'json', '--ignore-baseline'], true);
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($report['issues']) || ! is_array($report['issues'])) {
            throw new RuntimeException('Mago returned an invalid issue report.');
        }

        $issues = [];
        foreach ($report['issues'] as $issue) {
            $annotations = $issue['annotations'] ?? [];
            $primary =
                array_values(array_filter($annotations, fn ($annotation) => $annotation['kind'] === 'Primary'))[0]
                ?? $annotations[0]
                ?? [];
            $path = $primary['span']['file_id']['path'] ?? null;
            if ($path !== null) {
                $path = realpath($path) ?: $path;
            }
            if ($path !== null && ! in_array($path, $this->files, true)) {
                continue;
            }
            $issues[] = [
                'path' => $path === null ? null : substr($path, strlen($this->root) + 1),
                'line' => ($primary['span']['start']['line'] ?? 0) + 1,
                'stage' => $stage,
                'code' => $issue['code'] ?? $stage,
                'message' => $issue['message'],
                'severity' => strtolower($issue['level']),
                'fixable' => (bool) array_filter($issue['edits'] ?? [], fn ($edit) => $edit[1] !== []),
                'help' => $issue['help'] ?? null,
            ];
        }

        if ($process->getExitCode() === 1 && $report['issues'] === []) {
            throw new RuntimeException('Mago failed without reporting a diagnostic. '.$process->getErrorOutput());
        }

        return $issues;
    }

    /** @param list<string> $arguments */
    private function process(array $arguments, bool $diagnostics = false): Process
    {
        $process = new Process(
            [
                $this->binary,
                '--workspace',
                $this->root,
                '--config',
                $this->directory.($arguments[0] === 'analyze' ? '/analyze.json' : '/mago.json'),
                '--threads',
                (string) $this->threads,
                '--colors',
                'never',
                ...($arguments[0] === 'analyze' ? [] : ['--no-extensions']),
                ...$arguments,
            ],
            $this->root,
            $this->environment,
            timeout: 300,
        );
        $process->run();
        if (! in_array($process->getExitCode(), $diagnostics ? [0, 1] : [0], true)) {
            throw new RuntimeException(trim($process->getErrorOutput().$process->getOutput()));
        }

        return $process;
    }

    public function close(): void
    {
        if ($this->directory !== null) {
            new Filesystem()->remove($this->directory);
            $this->directory = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
