<?php

namespace App\Support;

use App\Repositories\ConfigurationJsonRepository;
use CompileError;
use ParseError;
use PhpToken;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

final class Mago
{
    private string $directory;

    private string $binary;

    /** @var array<string, mixed> */
    private array $settings;

    /** @var array<string, false> */
    private array $environment;

    /** @var array<string, string> */
    private array $fragments = [];

    private string $fixSafety;

    public function __construct(ConfigurationJsonRepository $configuration)
    {
        $preset = require dirname(__DIR__, 2).'/resources/presets/'.$configuration->preset().'.php';
        $this->fixSafety = $configuration->fixSafety();
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
            $defaults = new Process(
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
            $defaults->mustRun();
            $rules = json_decode($defaults->getOutput(), true, flags: JSON_THROW_ON_ERROR)['rules'];
            $selected = array_replace_recursive($preset['linter'], $configuration->linter());
            foreach ($selected as $name => $options) {
                if (! array_key_exists($name, $rules) || ! is_array($options)) {
                    throw new RuntimeException(
                        "Unknown Mago rule or invalid options [{$name}]. Use a rule options object with an enabled boolean.",
                    );
                }
            }
            // Never inherit Mago's broad lint policy: Pint applies only selected fixes.
            $rules = array_fill_keys(array_keys($rules), ['enabled' => false]);
            $formatter = $configuration->formatter();
            if (isset($formatter['excludes'])) {
                throw new RuntimeException('Use Pint exclude/notPath/notName settings, not formatter.excludes.');
            }
            $this->settings = [
                'php-version' => $configuration->phpVersion(),
                'source' => ['paths' => [], 'includes' => [], 'excludes' => []],
                'formatter' => array_replace($preset['formatter'], $formatter),
                'linter' => ['rules' => array_replace($rules, $selected)],
            ];
            $this->writeConfiguration(false);
            $this->writeConfiguration(true);
            $this->run(['config'], false)->mustRun();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public function fingerprint(): string
    {
        return hash(
            'sha256',
            MagoBinary::VERSION.$this->fixSafety.json_encode($this->settings, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, string> $files
     *  @return array<string, array{code: string, error: ?string, line?: int}>
     */
    public function formatFiles(array $files, int $threads = 1, bool $fragment = false): array
    {
        if ($files === []) {
            return [];
        }
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
                ['fix', '--no-guard', '--no-analyze', '--ignore-baseline', ...$safety, $stage],
                $fragment,
                $threads,
            );
            if (! $process->isSuccessful()) {
                throw new RuntimeException($process->getErrorOutput().$process->getOutput());
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

    /** @return array<string, array{message: string, line: int}> */
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

    private function writeConfiguration(bool $fragment): void
    {
        $settings = $this->settings;
        if ($fragment) {
            $settings['formatter']['remove-trailing-close-tag'] = false;
            // A fragment cannot see references in adjacent Blade markup.
            foreach ($settings['linter']['rules'] as $name => &$rule) {
                if ($name !== 'array-style') {
                    $rule = ['enabled' => false];
                }
            }
            unset($rule);
        }
        $toml = '';
        foreach ($settings as $name => $value) {
            $toml .= self::toml($name).' = '.self::toml($value)."\n";
        }
        if (file_put_contents($this->directory.'/'.($fragment ? 'fragment' : 'file').'.toml', $toml) === false) {
            throw new RuntimeException('Cannot write Mago configuration.');
        }
    }

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

    /** @param list<string> $arguments */
    private function run(array $arguments, bool $fragment, int $threads = 1): Process
    {
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
                '--no-extensions',
                ...$arguments,
            ],
            $this->directory,
            $this->environment,
            timeout: 300,
        );
        $process->run();

        return $process;
    }

    public function close(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function __destruct()
    {
        $this->close();
    }
}
