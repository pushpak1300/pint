<?php

namespace App\Output;

use App\ValueObjects\ReportSummary;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class Reporter
{
    private const ABOUT = 'Laravel Pint';

    public function __construct(
        private string $format,
    ) {}

    public function getFormat(): string
    {
        return $this->format;
    }

    public function generate(ReportSummary $summary): string
    {
        $result = match ($this->format) {
            'json' => $this->json($summary),
            'gitlab' => $this->gitlab($summary),
            'txt' => $this->text($summary),
            'xml', 'checkstyle', 'junit' => $this->xml($summary),
            default => throw new InvalidArgumentException('Unsupported report format: '.$this->format),
        };

        return $summary->isDecoratedOutput() && $this->format !== 'txt' ? OutputFormatter::escape($result) : $result;
    }

    private function json(ReportSummary $summary): string
    {
        $files = [];
        foreach ($summary->getChanged() as $path => $change) {
            $file = ['name' => $path];
            if ($summary->shouldAddAppliedFixers()) {
                $file['appliedFixers'] = $change['appliedFixers'];
            }
            if ($change['diff'] !== '') {
                $file['diff'] = $change['diff'];
            }
            $files[] = $file;
        }

        return json_encode([
            'about' => self::ABOUT,
            'files' => $files,
            'time' => ['total' => round($summary->getTime() / 1000, 3)],
            'memory' => round($summary->getMemory() / 1048576, 3),
        ], JSON_THROW_ON_ERROR);
    }

    private function gitlab(ReportSummary $summary): string
    {
        $report = [];
        foreach ($summary->getChanged() as $path => $change) {
            $lines = ['begin' => 0, 'end' => 0];
            if (preg_match(
                '/^@@ -(\d+)(?:,(\d+))? \+\d+(?:,\d+)? @@[^\n]*\n(.*?)(?=^@@|\z)/ms',
                $change['diff'],
                $match,
            )) {
                $offset = 0;
                foreach (explode("\n", $match[3]) as $line) {
                    if (str_starts_with($line, '+') || str_starts_with($line, '-')) {
                        break;
                    }
                    $offset++;
                }
                $lines = [
                    'begin' => (int) $match[1] + $offset,
                    'end' => (int) $match[1] + ($match[2] === '' ? 1 : (int) $match[2]),
                ];
            }
            foreach ($change['appliedFixers'] as $fixer) {
                $report[] = [
                    'check_name' => 'Pint.'.$fixer,
                    'description' => 'Found violation(s) of type: '.$fixer,
                    'content' => ['body' => self::ABOUT."\nCheck performed with a formatting rule."],
                    'categories' => ['Style'],
                    'fingerprint' => md5($path.$fixer),
                    'severity' => 'minor',
                    'location' => ['path' => $path, 'lines' => $lines],
                ];
            }
        }

        return json_encode($report, JSON_THROW_ON_ERROR);
    }

    private function text(ReportSummary $summary): string
    {
        $output = '';
        $index = 0;
        foreach ($summary->getChanged() as $path => $change) {
            $output .= sprintf('%4d) %s', ++$index, $path);
            if ($summary->shouldAddAppliedFixers()) {
                $output .= sprintf(
                    $summary->isDecoratedOutput() ? ' (<comment>%s</comment>)' : ' (%s)',
                    implode(', ', $change['appliedFixers']),
                );
            }
            if ($change['diff'] !== '') {
                $lines = [];
                foreach (preg_split('/\R/u', $change['diff']) as $line) {
                    if ($summary->isDecoratedOutput()) {
                        $line = OutputFormatter::escape($line);
                        $line = match ($line[0] ?? '') {
                            '+' => '<fg=green>'.$line.'</fg=green>',
                            '-' => '<fg=red>'.$line.'</fg=red>',
                            '@' => '<fg=cyan>'.$line.'</fg=cyan>',
                            default => $line,
                        };
                    }
                    $lines[] = $line;
                }
                $output .=
                    PHP_EOL
                    .(
                        $summary->isDecoratedOutput()
                            ? '<comment>      ---------- begin diff ----------</comment>'
                            : '      ---------- begin diff ----------'
                    )
                    .PHP_EOL
                    .implode(PHP_EOL, $lines)
                    .PHP_EOL
                    .(
                        $summary->isDecoratedOutput()
                            ? '<comment>      ----------- end diff -----------</comment>'
                            : '      ----------- end diff -----------'
                    )
                    .PHP_EOL;
            }
            $output .= PHP_EOL;
        }

        return $output
        .PHP_EOL
        .sprintf(
            '%s %d of %d %s in %.3f seconds, %.2f MB memory used'.PHP_EOL,
            $summary->isDryRun() ? 'Found' : 'Fixed',
            $index,
            $summary->getFilesCount(),
            $summary->isDryRun() ? 'files that can be fixed' : 'files',
            $summary->getTime() / 1000,
            $summary->getMemory() / 1048576,
        );
    }

    /** @param array<string, string|int|float> $attributes */
    private function element(DOMDocument $dom, DOMElement $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $dom->createElement($name);
        foreach ($attributes as $key => $value) {
            $element->setAttribute($key, (string) $value);
        }
        $parent->appendChild($element);

        return $element;
    }

    private function xml(ReportSummary $summary): string
    {
        if (! extension_loaded('dom')) {
            throw new RuntimeException('Cannot generate report! `ext-dom` is not available!');
        }
        $dom = new DOMDocument('1.0', 'UTF-8');
        $root = $dom->createElement(match ($this->format) {
            'checkstyle' => 'checkstyle',
            'junit' => 'testsuites',
            default => 'report',
        });
        $dom->appendChild($root);
        $files = $root;
        if ($this->format === 'checkstyle') {
            $root->setAttribute('version', self::ABOUT);
        } elseif ($this->format === 'junit') {
            $files = $this->element($dom, $root, 'testsuite', ['name' => 'Laravel Pint']);
            $properties = $this->element($dom, $files, 'properties');
            $this->element($dom, $properties, 'property', ['name' => 'about', 'value' => self::ABOUT]);
            $count = array_sum(array_map(fn ($change) => count($change['appliedFixers']), $summary->getChanged()));
            foreach ([
                'tests' => count($summary->getChanged()) ?: 1,
                'assertions' => $summary->getChanged() === [] ? 1 : $count,
                'failures' => $count,
                'errors' => 0,
            ] as $key => $value) {
                $files->setAttribute($key, (string) $value);
            }
            if ($summary->getTime() > 0) {
                $files->setAttribute('time', sprintf('%.3f', $summary->getTime() / 1000));
            }
            if ($summary->getChanged() === []) {
                $this->element($dom, $files, 'testcase', ['name' => 'All OK', 'assertions' => 1]);
            }
        } else {
            $this->element($dom, $root, 'about', ['value' => self::ABOUT]);
            $files = $this->element($dom, $root, 'files');
        }
        $id = 0;
        foreach ($summary->getChanged() as $path => $change) {
            if ($this->format === 'checkstyle') {
                $file = $this->element($dom, $files, 'file', ['name' => $path]);
                foreach ($change['appliedFixers'] as $fixer) {
                    $this->element($dom, $file, 'error', [
                        'severity' => 'warning',
                        'source' => 'Pint.'.$fixer,
                        'message' => 'Found violation(s) of type: '.$fixer,
                    ]);
                }
            } elseif ($this->format === 'junit') {
                $name = substr($path, 0, strlen($path) - strlen(pathinfo($path, PATHINFO_EXTENSION)) - 1);
                $file = $this->element($dom, $files, 'testcase', [
                    'name' => str_replace('.', '_DOT_', $name),
                    'file' => $path,
                    'assertions' => count($change['appliedFixers']),
                ]);
                $failure = $this->element($dom, $file, 'failure', ['type' => 'code_style']);
                $message = $summary->shouldAddAppliedFixers()
                    ? "applied fixers:\n---------------\n* ".implode("\n* ", $change['appliedFixers'])."\n"
                    : "Wrong code style\n";
                if ($change['diff'] !== '') {
                    $message .= "\nDiff:\n---------------\n\n".$change['diff'];
                }
                $failure->appendChild($dom->createCDATASection(trim($message)));
            } else {
                $file = $this->element($dom, $files, 'file', ['id' => ++$id, 'name' => $path]);
                if ($summary->shouldAddAppliedFixers()) {
                    $fixers = $this->element($dom, $file, 'applied_fixers');
                    foreach ($change['appliedFixers'] as $fixer) {
                        $this->element($dom, $fixers, 'applied_fixer', ['name' => $fixer]);
                    }
                }
                if ($change['diff'] !== '') {
                    $this->element($dom, $file, 'diff')->appendChild($dom->createCDATASection($change['diff']));
                }
            }
        }
        if ($this->format === 'xml') {
            if ($summary->getTime() !== 0) {
                $this->element(
                    $dom,
                    $this->element($dom, $root, 'time', ['unit' => 's']),
                    'total',
                    ['value' => round($summary->getTime() / 1000, 3)],
                );
            }
            if ($summary->getMemory() !== 0) {
                $this->element($dom, $root, 'memory', [
                    'value' => round($summary->getMemory() / 1048576, 3),
                    'unit' => 'MB',
                ]);
            }
        }
        $dom->formatOutput = true;

        return $dom->saveXML();
    }
}
