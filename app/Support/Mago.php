<?php

namespace App\Support;

use App\Mago\Cleanup;
use App\Mago\Layout;
use App\Repositories\ConfigurationJsonRepository;
use CompileError;
use ParseError;
use Phar;
use PhpToken;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

final class Mago
{
    /**
     * The isolated working directory, created only when Mago is needed.
     */
    private string $directory;

    /**
     * The verified native executable.
     */
    private string $binary;

    /**
     * The configured settings used to fingerprint the cache.
     *
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * The inherited Mago environment variables to remove from child processes.
     *
     * @var array<string, false>
     */
    private array $environment;

    /**
     * The formatted PHP fragments, keyed by their original source.
     *
     * @var array<string, string>
     */
    private array $fragments = [];

    /**
     * The selected Pint-owned rules, including disabled entries for the cache key.
     *
     * @var array<string, bool|array<string, mixed>>
     */
    private array $cleanup;

    /**
     * Laravel anonymous-class brace policy; null disables Laravel finishing.
     */
    private ?bool $layout;

    /**
     * The allowed safety level for lint fixes.
     */
    private string $fixSafety;

    /**
     * Create a new Mago instance without starting the native executable.
     */
    public function __construct(
        ConfigurationJsonRepository $configuration,
        private string $stage = 'format',
        bool $allFixes = false,
    ) {
        $preset = require dirname(__DIR__, 2).'/resources/presets/'.$configuration->preset().'.php';

        $this->fixSafety = $allFixes ? 'unsafe' : $configuration->fixSafety();
        $this->cleanup = $configuration->sectionEnabled('refactor') && $stage !== 'style'
            ? array_replace(
                $stage === 'refactor' ? ['no_superfluous_elseif' => true, 'no_useless_else' => true] : [],
                $configuration->cleanup(),
            )
            : [];

        $formatter = $configuration->formatter();
        $this->layout =
            $stage !== 'refactor' && $configuration->preset() === 'laravel'
                ? ! array_key_exists('closure-brace-style', $formatter)
                : null;

        if (isset($formatter['excludes'])) {
            throw new RuntimeException('Use Pint exclude/notPath/notName settings, not formatter.excludes.');
        }

        $this->settings = [
            'php-version' => $configuration->phpVersion(),
            'source' => ['paths' => [], 'includes' => [], 'excludes' => []],
            'formatter' => array_replace($preset['formatter'], $formatter),
            'linter' => [
                'rules' => $stage === 'refactor'
                    ? []
                    : array_replace_recursive(
                        $preset['linter'],
                        $stage === 'style'
                            ? array_intersect_key($configuration->linter(), $preset['linter'])
                            : $configuration->linter(),
                    ),
            ],
        ];

        if (
            array_key_exists('fully_qualified_strict_types', $configuration->cleanup())
            || array_key_exists('global_namespace_import', $configuration->cleanup())
        ) {
            // Native import shortening must not undo the explicitly selected import policy.
            $this->settings['linter']['rules']['no-fully-qualified-global-class-like'] = ['enabled' => false];
        }
    }

    /**
     * Prepare the isolated workspace and validate the native configuration once.
     */
    public function initialize(): void
    {
        if (isset($this->directory)) {
            return;
        }

        $this->binary = MagoBinary::path();
        $this->environment = [];

        foreach (array_keys(getenv()) as $name) {
            if (str_starts_with($name, 'MAGO_')) {
                $this->environment[$name] = false;
            }
        }

        $this->directory = sys_get_temp_dir().'/pint-mago-'.bin2hex(random_bytes(12));

        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Cannot create Mago working directory.');
        }

        try {
            $rules = $this->rules();

            if (Cleanup::active($this->cleanup, $this->fixSafety) !== []) {
                if (! mkdir($this->directory.'/cleanup-cache', 0700)) {
                    throw new RuntimeException('Cannot create Pint cleanup cache.');
                }

                if (file_put_contents($this->directory.'/cleanup.json', json_encode([
                    'rules' => $this->cleanup,
                    'php-version' => $this->settings['php-version'],
                    'fix-safety' => $this->fixSafety,
                ], JSON_THROW_ON_ERROR)) === false) {
                    throw new RuntimeException('Cannot write Pint cleanup configuration.');
                }
            }

            $this->writeConfiguration(false, $rules);
            $this->writeConfiguration(true, $rules);

            $this->run(['config'], false)->mustRun();
        } catch (Throwable $error) {
            $this->close();

            throw $error;
        }
    }

    /**
     * Get the cache fingerprint without initializing Mago.
     */
    public function fingerprint(): string
    {
        return hash(
            'sha256',
            MagoBinary::VERSION.Cleanup::VERSION.$this->fixSafety.$this->stage
                .json_encode([$this->settings, $this->cleanup, $this->layout], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Format staged copies of the files, preserving invalid source unchanged.
     *
     * @param  array<string, string>  $files
     * @return array<string, array{code: string, error: ?string, line?: int}>
     */
    public function formatFiles(array $files, int $threads = 1, bool $fragment = false): array
    {
        if ($files === []) {
            return [];
        }

        $this->initialize();

        $stage = $this->directory.'/files-'.bin2hex(random_bytes(6));

        mkdir($stage, 0700);

        $map = [];
        $results = [];

        try {
            foreach ($files as $path => $code) {
                $temporary = str_replace('\\', '/', $stage.'/'.count($map).'.php');

                if (file_put_contents($temporary, $code) === false) {
                    throw new RuntimeException('Cannot stage PHP source for Mago.');
                }

                $map[$temporary] = $path;
                $results[$path] = ['code' => $code, 'error' => null];
            }

            $errors = $this->syntaxErrors($stage, $fragment, $threads);

            foreach ($errors as $temporary => $error) {
                if (! isset($map[$temporary])) {
                    throw new RuntimeException($error['message']);
                }

                $results[$map[$temporary]]['error'] = $error['message'];
                $results[$map[$temporary]]['line'] = $error['line'];

                unlink($temporary);
                unset($map[$temporary]);
            }

            if ($map === []) {
                return $results;
            }

            $safety = ! $fragment && $this->fixSafety !== 'safe' ? ['--'.$this->fixSafety] : [];
            $process = $this->run(
                [
                    'fix',
                    '--no-guard',
                    '--no-analyze',
                    '--ignore-baseline',
                    ...($this->stage === 'refactor' ? ['--no-fmt'] : []),
                    ...$safety,
                    $stage,
                ],
                $fragment,
                $threads,
            );

            if (! $process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput().$process->getOutput());
            }

            if (! $fragment && $this->layout !== null) {
                foreach ($map as $temporary => $path) {
                    $code = file_get_contents($temporary);
                    $formatted = Layout::format($code, $this->layout);

                    if ($formatted !== $code && file_put_contents($temporary, $formatted) === false) {
                        throw new RuntimeException('Cannot stage Laravel layout for validation.');
                    }
                }
            }

            $errors = $this->syntaxErrors($stage, $fragment, $threads);

            foreach ($map as $temporary => $path) {
                if (isset($errors[$temporary])) {
                    $results[$path]['error'] = 'Mago produced invalid PHP: '.$errors[$temporary]['message'];
                } else {
                    $results[$path]['code'] = file_get_contents($temporary);
                }
            }

            return $results;
        } finally {
            new Filesystem()->remove($stage);
        }
    }

    /**
     * Format a complete PHP island without changing incomplete Blade fragments.
     */
    public function formatFragment(string $code): string
    {
        if (isset($this->fragments[$code])) {
            return $this->fragments[$code];
        }

        // Blade islands can be incomplete PHP documents. Do not rewrite those.
        try {
            PhpToken::tokenize($code, TOKEN_PARSE);
        } catch (ParseError|CompileError) {
            return $code;
        }

        $result = $this->formatFiles(['fragment.php' => $code], fragment: true)['fragment.php'];

        return $this->fragments[$code] = $result['error'] === null ? $result['code'] : $code;
    }

    /**
     * Get syntax and semantic errors for the staged files.
     *
     * @return array<string, array{message: string, line: int}>
     */
    private function syntaxErrors(string $stage, bool $fragment, int $threads): array
    {
        $process = $this->run(['lint', '--semantics', '--reporting-format', 'json', $stage], $fragment, $threads);

        if (! in_array($process->getExitCode(), [0, 1], true)) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        }

        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $errors = [];

        foreach ($report['issues'] as $issue) {
            if ($issue['level'] !== 'Error') {
                continue;
            }

            $annotation = $issue['annotations'][0] ?? [];
            $path = str_replace('\\', '/', $annotation['span']['file_id']['path'] ?? '');
            $line = ($annotation['span']['start']['line'] ?? 0) + 1;
            $errors[$path] = [
                'message' => $issue['message'].': '.($annotation['message'] ?? '').' (line '.$line.')',
                'line' => $line,
            ];
        }

        return $errors;
    }

    /**
     * Validate the selected rules and disable all other native lint rules.
     *
     * @return array<string, array<string, mixed>>
     */
    private function rules(): array
    {
        $process = new Process(
            [
                $this->binary,
                '--workspace',
                $this->directory,
                '--no-extensions',
                'config',
                '--default',
                '--show',
                'linter',
            ],
            $this->directory,
            $this->environment,
        );

        $process->mustRun();

        $rules = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['rules'];
        $selected = $this->settings['linter']['rules'];

        foreach ($selected as $name => $options) {
            if (! array_key_exists($name, $rules) || ! is_array($options)) {
                throw new RuntimeException(
                    "Unknown Mago rule or invalid options [{$name}]. Use a rule options object with an enabled boolean.",
                );
            }
        }

        // Never inherit Mago's broad lint policy: Pint applies only selected fixes.
        return array_replace(array_fill_keys(array_keys($rules), ['enabled' => false]), $selected);
    }

    /**
     * Write an isolated configuration for complete files or embedded fragments.
     *
     * @param  array<string, array<string, mixed>>  $rules
     */
    private function writeConfiguration(bool $fragment, array $rules): void
    {
        $settings = $this->settings;
        $settings['linter']['rules'] = $rules;

        if ($fragment) {
            $settings['formatter']['remove-trailing-close-tag'] = false;

            // A fragment cannot see references in adjacent Blade markup.
            foreach ($settings['linter']['rules'] as $name => &$rule) {
                if ($name !== 'array-style') {
                    $rule = ['enabled' => false];
                }
            }

            unset($rule);
        } elseif (Cleanup::active($this->cleanup, $this->fixSafety) !== []) {
            $phar = Phar::running(false);
            $autoload = $phar !== ''
                ? 'phar://'.$phar.'/vendor/autoload.php'
                : dirname(__DIR__, 2).'/vendor/autoload.php';

            // A fresh PHP process must register extensionless PHARs before reading them.
            $settings['extension-hosts']['pint'] = [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'if ($argv[3] !== "") { Phar::loadPhar($argv[3]); } require $argv[1]; App\\Mago\\Cleanup::work($argv[2]);',
                    $autoload,
                    $this->directory.'/cleanup.json',
                    $phar,
                ],
                'workers' => 1,
            ];
        }

        $toml = '';

        foreach ($settings as $name => $value) {
            $toml .= self::toml($name).' = '.self::toml($value)."\n";
        }

        if (file_put_contents($this->directory.'/'.($fragment ? 'fragment' : 'file').'.toml', $toml) === false) {
            throw new RuntimeException('Cannot write Mago configuration.');
        }
    }

    /**
     * Encode a configuration value as TOML.
     */
    private static function toml(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(', ', array_map(self::toml(...), $value)).']';
            }

            $pairs = [];

            foreach ($value as $key => $item) {
                $pairs[] = self::toml((string) $key).' = '.self::toml($item);
            }

            return '{ '.implode(', ', $pairs).' }';
        }

        if ($value === null || is_object($value)) {
            throw new RuntimeException(
                'Mago settings must contain strings, numbers, booleans, arrays, or objects without null values.',
            );
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Run Mago with the isolated workspace, configuration, and environment.
     *
     * @param  list<string>  $arguments
     */
    private function run(array $arguments, bool $fragment, int $threads = 1): Process
    {
        // Semantic checks and fragments never need to start a PHP extension worker.
        $extensions =
            $arguments[0] === 'fix' && ! $fragment && Cleanup::active($this->cleanup, $this->fixSafety) !== [];

        $process = new Process(
            [
                $this->binary,
                '--workspace',
                $this->directory,
                '--config',
                $this->directory.'/'.($fragment ? 'fragment' : 'file').'.toml',
                '--threads',
                (string) $threads,
                '--colors',
                'never',
                ...($extensions ? [] : ['--no-extensions']),
                ...$arguments,
            ],
            $this->directory,
            $this->environment,
            timeout: 300,
        );

        $process->run();

        return $process;
    }

    /**
     * Remove the working directory if Mago was initialized.
     */
    public function close(): void
    {
        if (isset($this->directory)) {
            new Filesystem()->remove($this->directory);

            unset($this->directory);
        }
    }

    /**
     * Clean up the isolated workspace when the instance is released.
     */
    public function __destruct()
    {
        $this->close();
    }
}
