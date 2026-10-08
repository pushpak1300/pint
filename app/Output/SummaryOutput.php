<?php

namespace App\Output;

use App\Output\Concerns\InteractsWithSymbols;
use App\Project;
use App\Repositories\ConfigurationJsonRepository;
use App\ValueObjects\ErrorsManager;
use App\ValueObjects\FileProcessed;
use App\ValueObjects\Issue;
use App\ValueObjects\ReportSummary;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Termwind\render;
use function Termwind\renderUsing;

class SummaryOutput
{
    use InteractsWithSymbols;

    /**
     * The list of presets, in a human-readable format.
     *
     * @var array<string, string>
     */
    protected $presets = [
        'per' => 'PER',
        'psr12' => 'PSR 12',
        'laravel' => 'Laravel',
        'symfony' => 'Symfony',
    ];

    /**
     * Creates a new Summary Output instance.
     *
     * @param  ConfigurationJsonRepository  $config
     * @param  ErrorsManager  $errors
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return void
     */
    public function __construct(
        protected $config,
        protected $errors,
        protected $input,
        protected $output,
    ) {
        // ..
    }

    /**
     * Handle the given report summary.
     *
     * @param  ReportSummary  $summary
     * @param  int  $totalFiles
     * @return void
     */
    public function handle($summary, $totalFiles)
    {
        renderUsing($this->output);

        $issues = $this->getIssues(Project::path(), $summary);

        render(view('summary', [
            'totalFiles' => $totalFiles,
            'issues' => $issues,
            'testing' => $summary->isDryRun(),
            'preset' => $this->presets[$this->config->preset()],
        ]));

        foreach ($issues as $issue) {
            render(view('issue.show', [
                'issue' => $issue,
                'isVerbose' => $this->output->isVerbose(),
                'testing' => $summary->isDryRun(),
            ]));

            if ($this->output->isVerbose() && $issue->code()) {
                $this->output->writeln($issue->code());
            }
        }

        $this->output->writeln('');
    }

    /**
     * Gets the list of issues from the given summary.
     *
     * @param  string  $path
     * @param  ReportSummary  $summary
     * @return Collection<int, Issue>
     */
    public function getIssues($path, $summary)
    {
        $issues = collect($summary->getChanged())->map(
            fn ($information, $file) => new Issue(
                $path,
                $file,
                $this->getSymbol(FileProcessed::STATUS_FIXED),
                $information,
            ),
        )->values();

        return $issues
            ->merge(collect(array_merge(
                $this->errors->getInvalidErrors(),
                $this->errors->getExceptionErrors(),
                $this->errors->getLintErrors(),
            ))->map(
                fn ($error) => new Issue(
                    $path,
                    $error->getFilePath(),
                    $this->getSymbolFromErrorType($error->getType()),
                    [
                        'source' => $error->getSource(),
                        'line' => $error->getSourceLine(),
                    ],
                ),
            ))
            ->sort(function ($issueA, $issueB) {
                return $issueA <=> $issueB;
            })
            ->values();
    }
}
