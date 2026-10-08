<?php

namespace App\Actions;

use App\BladeFormatter;
use App\Factories\ConfigurationResolverFactory;
use App\Output\ProgressOutput;
use App\Repositories\ConfigurationJsonRepository;
use App\Support\Mago;
use App\ValueObjects\Error;
use App\ValueObjects\ErrorsManager;
use App\ValueObjects\FileProcessed;
use LaravelZero\Framework\Exceptions\ConsoleException;
use RuntimeException;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

class FixCode
{
    public function __construct(
        protected ErrorsManager $errors,
        protected EventDispatcher $events,
        protected InputInterface $input,
        protected OutputInterface $output,
        protected ProgressOutput $progress,
    ) {}

    /** @return array{int, array<string, array{appliedFixers: list<string>, diff: string}>} */
    public function execute(): array
    {
        try {
            $files = ConfigurationResolverFactory::files($this->input);
        } catch (ConsoleException $exception) {
            if ($exception->getExitCode() !== 0) {
                throw $exception;
            }

            return [0, []];
        }
        $mago = resolve(Mago::class);
        $configuration = resolve(ConfigurationJsonRepository::class);
        $max = $this->input->getOption('max-processes');
        if ($max !== null && (filter_var($max, FILTER_VALIDATE_INT) === false || (int) $max < 1)) {
            abort(1, '[--max-processes] must be a positive integer. It controls Mago worker threads in Pint 2.x.');
        }
        $threads = $this->input->getOption('parallel') ? (int) ($max ?? 0) : 1;
        $dryRun = $this->input->getOption('test') || $this->input->getOption('bail');
        $cachePath = $this->input->getOption('cache-file') ?? $configuration->cacheFile();
        if ($cachePath === null && app()->isProduction()) {
            $cachePath = sys_get_temp_dir().'/pint-'.hash('sha256', getcwd().'|'.get_current_user()).'.cache';
        }
        $signature = [
            'pint' => config('app.version'),
            'mago' => $mago->fingerprint(),
            'blade' => $configuration->blade(),
            'prettier' => resolve(EnsurePrettierIsConfigured::class)->cacheFingerprints(),
        ];
        $cache = $cachePath && is_file($cachePath) ? json_decode(file_get_contents($cachePath), true) : null;
        $hashes = ($cache['signature'] ?? null) === $signature ? $cache['hashes'] ?? [] : [];
        $changes = [];
        $differ = new Differ(new UnifiedDiffOutputBuilder("--- Original\n+++ New\n"));
        if ($this->input->getOption('format') === null && ! ConfigurationResolverFactory::runningInAgent()) {
            $this->progress->subscribe();
        }

        try {
            foreach (array_chunk($files, $this->input->getOption('bail') ? 1 : 128) as $chunk) {
                $originals = [];
                $php = [];
                $results = [];
                foreach ($chunk as $path) {
                    $code = file_get_contents($path);
                    if ($code === false) {
                        throw new RuntimeException("Cannot read [{$path}].");
                    }
                    if (($hashes[$path] ?? null) === hash('sha256', $code)) {
                        $this->events->dispatch(new FileProcessed(FileProcessed::STATUS_SKIPPED), FileProcessed::NAME);
                        continue;
                    }
                    $originals[$path] = $code;
                    unset($hashes[$path]);
                    if (str_ends_with($path, '.blade.php')) {
                        try {
                            $results[$path] = [
                                'code' => resolve(BladeFormatter::class)->format($path, $code),
                                'error' => null,
                            ];
                        } catch (Throwable $error) {
                            $results[$path] = ['code' => $code, 'error' => $error->getMessage()];
                        }
                    } else {
                        $php[$path] = $code;
                    }
                }
                $results += $mago->formatFiles($php, $threads);
                foreach ($originals as $path => $original) {
                    $result = $results[$path];
                    if ($result['error'] !== null) {
                        $this->errors->report(
                            new Error(
                                Error::TYPE_INVALID,
                                $path,
                                new RuntimeException($result['error']),
                                $result['line'] ?? null,
                            ),
                        );
                        $this->events->dispatch(new FileProcessed(FileProcessed::STATUS_INVALID), FileProcessed::NAME);
                        if ($this->input->getOption('bail')) {
                            break 2;
                        }
                        continue;
                    }
                    $code = $result['code'];
                    if ($code !== $original) {
                        if (! $dryRun) {
                            if (file_get_contents($path) !== $original) {
                                throw new RuntimeException(
                                    "File [{$path}] changed while Pint was formatting it; refusing to overwrite it.",
                                );
                            }
                            if (file_put_contents($path, $code) === false) {
                                throw new RuntimeException("Cannot write [{$path}].");
                            }
                        }
                        $changes[$path] = [
                            'appliedFixers' => [str_ends_with($path, '.blade.php') ? BladeFormatter::NAME : 'mago'],
                            'diff' => $this->output->isVerbose() ? $differ->diff($original, $code) : '',
                        ];
                    }
                    if (! $dryRun || $code === $original) {
                        $hashes[$path] = hash('sha256', $code);
                    }
                    $this->events->dispatch(
                        new FileProcessed(
                            $code === $original ? FileProcessed::STATUS_NO_CHANGES : FileProcessed::STATUS_FIXED,
                        ),
                        FileProcessed::NAME,
                    );
                    if ($this->input->getOption('bail') && $changes !== []) {
                        break 2;
                    }
                }
            }
            if ($cachePath) {
                new Filesystem()->dumpFile($cachePath, json_encode([
                    'signature' => $signature,
                    'hashes' => $hashes,
                ], JSON_THROW_ON_ERROR));
            }

            return [count($files), $changes];
        } finally {
            $this->progress->unsubscribe();
        }
    }
}
