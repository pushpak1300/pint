<?php

namespace App\Commands;

use App\Factories\ConfigurationFactory;
use App\Factories\ConfigurationResolverFactory;
use App\Repositories\ConfigurationJsonRepository;
use App\Support\Mago;
use App\Support\ProjectChecks;
use App\ValueObjects\ErrorsManager;
use LaravelZero\Framework\Exceptions\ConsoleException;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

class QualityCommand extends DefaultCommand
{
    protected $name = 'check';

    protected $aliases = ['fix', 'lint', 'analyze', 'guard', 'refactor'];

    protected $description = 'Check Laravel code; use fix to apply all enabled fixes (including behavior changes)';

    protected function configure()
    {
        parent::configure();
        $this->addOption('fix', null, InputOption::VALUE_NONE, 'Apply lint fixes, including enabled unsafe fixes');
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply refactors instead of previewing them');
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on advisory warnings as well as errors');
        $this->addOption(
            'baseline',
            null,
            InputOption::VALUE_REQUIRED,
            'Read known issues from this Pint JSON baseline',
        );
        $this->addOption(
            'generate-baseline',
            null,
            InputOption::VALUE_NONE,
            'Explicitly write a baseline; requires --baseline',
        );
    }

    public function handle($fixCode, $elaborateSummary, $ensurePrettierIsConfigured)
    {
        $format = $this->option('format') ?? 'text';
        $issues = [];
        $changed = [];
        $checks = null;
        $engines = [];
        $before = [];
        $root = (string) getcwd();

        try {
            if (! in_array($format, ['text', 'agent', 'json'], true)) {
                throw new RuntimeException(
                    'Quality checks support --format=text, agent, or json. Other reports remain available with pint format.',
                );
            }
            if (
                $this->hasStdinInput()
                || $this->option('repair')
                || $this->option('bail')
                || $this->option('output-to-file')
                || $this->option('output-format')
            ) {
                throw new RuntimeException(
                    'Use pint format for stdin, --repair, --bail, and file-based style reports.',
                );
            }

            $mode = $this->input->hasArgument('command') ? ($this->input->getArgument('command') ?: 'check') : 'check';
            if ($this->option('fix') && $mode !== 'lint' || $this->option('apply') && $mode !== 'refactor') {
                throw new RuntimeException('Use pint fix, pint lint --fix, or pint refactor --apply.');
            }
            $write = $mode === 'fix' || $this->option('fix') || $this->option('apply');
            if ($write && ($this->option('test') || $this->option('generate-baseline'))) {
                throw new RuntimeException(
                    'Write commands cannot be combined with --test or --generate-baseline.',
                );
            }
            $configuration = resolve(ConfigurationJsonRepository::class);
            $configuration->preset();
            $files = array_values(array_filter(ConfigurationResolverFactory::files($this->input), function ($path) use (
                $root,
            ) {
                if (! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException(
                        "Selected file [{$path}] is outside the project. Run Pint from the project root.",
                    );
                }

                return ! ConfigurationFactory::isPathExcluded($path);
            }));
            $stages = array_values(array_filter(
                in_array($mode, ['check', 'fix'], true) ? ['lint', 'analyze', 'guard', 'refactor', 'format'] : [$mode],
                $configuration->sectionEnabled(...),
            ));
            $native = array_values(array_intersect($stages, ['lint', 'analyze', 'guard']));
            $php = array_values(array_filter($files, fn ($path) => ! str_ends_with($path, '.blade.php')));
            $threads = $this->option('max-processes');
            if ($threads !== null && (filter_var($threads, FILTER_VALIDATE_INT) === false || (int) $threads < 1)) {
                throw new RuntimeException('--max-processes must be a positive integer.');
            }

            // Resolve versions and validate all native settings before allowing any writes.
            if ($native !== []) {
                $checks = new ProjectChecks(
                    $configuration,
                    $root,
                    $php,
                    (int) ($threads ?? ($this->option('parallel') ? 0 : 2)),
                );
            } elseif (in_array('refactor', $stages, true)) {
                $configuration->projectVersions($root);
            }
            $baseline = $this->option('baseline') ?? $configuration->baseline();
            if ($this->option('generate-baseline') && ! $baseline) {
                throw new RuntimeException('--generate-baseline requires --baseline=<file.json>.');
            }
            if (
                $baseline
                && (
                    in_array(realpath($baseline), [...$files, ...$configuration->localPaths()], true)
                    || str_ends_with($baseline, '.php')
                )
            ) {
                throw new RuntimeException('The baseline must not overwrite source or configuration files.');
            }
            $cache = $this->option('cache-file') ?? $configuration->cacheFile();
            if ($cache && in_array(realpath($cache), [...$files, ...$configuration->localPaths()], true)) {
                throw new RuntimeException('The cache must not overwrite source or configuration files.');
            }
            $counts = $baseline ? $this->readBaseline($baseline) : [];
            foreach (array_intersect(['refactor', 'format'], $stages) as $stage) {
                $engines[$stage] = new Mago($configuration, $stage === 'format' ? 'style' : 'refactor', true);
                $engines[$stage]->initialize();
            }
            if (in_array('format', $stages, true)) {
                $ensurePrettierIsConfigured->execute();
            }
            $before = $write ? array_combine($files, array_map(fn ($path) => hash_file('sha256', $path), $files)) : [];
            if ($write) {
                $checks?->fix($native);
            }

            $this->input->setOption('format', 'json');
            $this->input->setOption('dirty', false);
            $this->input->setOption('diff', null);
            $this->input->setOption('bail', false);

            // Verify the final source, after every write stage has finished.
            foreach ($write ? [false, true] : [true] as $dryRun) {
                foreach (array_intersect(['refactor', 'format'], $stages) as $stage) {
                    $this->input->setArgument('path', $stage === 'refactor' ? $php : $files);
                    $this->input->setOption('cache-file', $stage === 'refactor' ? '' : $cache);
                    $this->input->setOption('test', $dryRun);
                    app()->instance(Mago::class, $engines[$stage]);
                    [, $changes] = $fixCode->execute();
                    if (! $dryRun) {
                        continue;
                    }
                    foreach ($changes as $path => $change) {
                        $issues[] = [
                            'path' => substr($path, strlen($root) + 1),
                            'line' => 1,
                            'stage' => $stage,
                            'code' => $stage.'-difference',
                            'message' => $stage === 'format'
                                ? 'Formatting changes required.'
                                : 'Enabled refactors are available.',
                            'severity' => 'error',
                            'fixable' => true,
                            'diff' => $change['diff'],
                        ];
                    }
                }
            }

            foreach ($native as $stage) {
                array_push($issues, ...$checks->check($stage));
            }
            foreach (resolve(ErrorsManager::class)->getInvalidErrors() as $error) {
                $issues[] = [
                    'path' => substr($error->getFilePath(), strlen($root) + 1),
                    'line' => $error->getSourceLine() ?? 1,
                    'stage' => 'format',
                    'code' => 'invalid-source',
                    'message' => $error->getSource()?->getMessage(),
                    'severity' => 'error',
                    'fixable' => false,
                ];
            }
            if ($baseline) {
                $issues = $this->baseline($issues, $baseline, $counts);
            }
        } catch (Throwable $error) {
            if (! $error instanceof ConsoleException || $error->getExitCode() !== 0) {
                $issues[] = [
                    'path' => null,
                    'line' => 1,
                    'stage' => 'pint',
                    'code' => 'execution-error',
                    'message' => $error->getMessage(),
                    'severity' => 'error',
                    'fixable' => false,
                ];
            }
        } finally {
            $checks?->close();
            foreach ($engines as $engine) {
                $engine->close();
            }
            foreach ($before as $path => $hash) {
                if (hash_file('sha256', $path) !== $hash) {
                    $changed[] = substr($path, strlen($root) + 1);
                }
            }
        }

        usort(
            $issues,
            fn ($left, $right) => (
                [$left['path'], $left['line'], $left['stage'], $left['code']] <=> [
                    $right['path'],
                    $right['line'],
                    $right['stage'],
                    $right['code'],
                ]
            ),
        );
        $failed = (bool) array_filter(
            $issues,
            fn ($issue) => (
                $issue['severity'] === 'error'
                || $this->option('strict')
                && $issue['severity'] === 'warning'
            ),
        );
        if (in_array($format, ['json', 'agent'], true)) {
            $this->line(json_encode(
                ['result' => $failed ? 'fail' : 'pass', 'changed' => $changed, 'issues' => $issues],
                ($format === 'json' ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
        } else {
            foreach ($issues as $issue) {
                $this->line(sprintf(
                    '%s:%d [%s/%s] %s%s',
                    $issue['path'] ?? 'pint',
                    $issue['line'],
                    $issue['stage'],
                    $issue['code'],
                    $issue['message'],
                    $issue['fixable'] ? ' (fixable)' : '',
                ));
                if ($this->output->isVerbose() && ! empty($issue['diff'])) {
                    $this->output->write($issue['diff']);
                }
                if ($this->output->isVerbose() && ! empty($issue['help'])) {
                    $this->line('  '.$issue['help']);
                }
            }
            $this->line(sprintf(
                '%s: %d issue(s), %d file(s) changed.',
                $failed ? 'FAIL' : 'PASS',
                count($issues),
                count($changed),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Counts prevent one recorded issue from suppressing unlimited new occurrences.
     *
     * @param list<array<string, mixed>> $issues
     * @param array<string, int> $counts
     * @return list<array<string, mixed>>
     */
    private function baseline(array $issues, string $path, array $counts): array
    {
        $key = fn ($issue) => hash('sha256', json_encode([
            $issue['path'],
            $issue['stage'],
            $issue['code'],
            $issue['message'],
        ], JSON_THROW_ON_ERROR));
        if ($this->option('generate-baseline')) {
            $counts = [];
            foreach ($issues as $issue) {
                if ($issue['stage'] !== 'format') {
                    $id = $key($issue);
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
            new Filesystem()->dumpFile($path, json_encode([
                'version' => 1,
                'issues' => (object) $counts,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
                ."\n");
        }

        return array_values(array_filter($issues, function ($issue) use (&$counts, $key) {
            $id = $key($issue);
            if ($issue['stage'] !== 'format' && ($counts[$id] ?? 0) > 0) {
                $counts[$id]--;

                return false;
            }

            return true;
        }));
    }

    /** @return array<string, int> */
    private function readBaseline(string $path): array
    {
        if (! is_file($path)) {
            if ($this->option('generate-baseline')) {
                return [];
            }
            throw new RuntimeException("Baseline [{$path}] does not exist; generate it explicitly first.");
        }
        $baseline = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (($baseline['version'] ?? null) !== 1 || ! is_array($baseline['issues'] ?? null)) {
            throw new RuntimeException("Invalid Pint baseline [{$path}]; refusing to read or overwrite it.");
        }
        foreach ($baseline['issues'] as $count) {
            if (! is_int($count) || $count < 1) {
                throw new RuntimeException('Invalid Pint baseline issue count.');
            }
        }

        return $baseline['issues'];
    }
}
