<?php

use App\Output\Reporter;
use App\Output\SummaryOutput;
use App\ValueObjects\Error;
use App\ValueObjects\ErrorsManager;
use App\ValueObjects\ReportSummary;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

it('preserves report schemas and optional fixers', function () {
    $summary = new ReportSummary(
        ['a&b.php' => ['appliedFixers' => ['format'], 'diff' => "@@ -2,3 +2,3 @@\n context\n-old\n+new\n"]],
        10,
        1234,
        1048576,
        true,
        true,
        false,
    );
    $json = json_decode(new Reporter('json')->generate($summary), true);
    expect($json['files'][0]['name'])
        ->toBe('a&b.php')
        ->and($json['files'][0]['appliedFixers'])
        ->toBe(['format'])
        ->and($json['time']['total'])
        ->toBe(1.234)
        ->and($json['memory'])
        ->toBe(1);
    $gitlab = json_decode(new Reporter('gitlab')->generate($summary), true);
    expect($gitlab[0]['fingerprint'])
        ->toBe(md5('a&b.phpformat'))
        ->and($gitlab[0]['location']['lines'])
        ->toBe(['begin' => 3, 'end' => 5]);
    foreach (['xml' => 'report', 'checkstyle' => 'checkstyle', 'junit' => 'testsuites'] as $format => $root) {
        $xml = simplexml_load_string(new Reporter($format)->generate($summary));
        expect($xml->getName())->toBe($root);
    }
    $junit = simplexml_load_string(new Reporter('junit')->generate($summary));
    expect((string) $junit->testsuite['failures'])
        ->toBe('1')
        ->and((string) $junit->testsuite->testcase['file'])
        ->toBe('a&b.php');
    expect(new Reporter('txt')->generate($summary))
        ->toContain('Found 1 of 10 files that can be fixed', '   1) a&b.php (format)');
    $quiet = new ReportSummary(
        ['a.php' => ['appliedFixers' => ['format'], 'diff' => '']],
        1,
        0,
        0,
        false,
        false,
        false,
    );
    expect(json_decode(new Reporter('json')->generate($quiet), true)['files'][0])->toBe(['name' => 'a.php']);
});

it('emits empty reports and successful junit cases', function () {
    $summary = new ReportSummary([], 0, 0, 0, false, false, false);
    expect(new Reporter('gitlab')->generate($summary))->toBe('[]');
    $xml = simplexml_load_string(new Reporter('junit')->generate($summary));
    expect((string) $xml->testsuite['tests'])
        ->toBe('1')
        ->and((string) $xml->testsuite['failures'])
        ->toBe('0')
        ->and((string) $xml->testsuite->testcase['name'])
        ->toBe('All OK');
});

it('keeps every error category in the cli summary', function () {
    $errors = new ErrorsManager;
    foreach ([Error::TYPE_INVALID, Error::TYPE_EXCEPTION, Error::TYPE_LINT] as $type) {
        $errors->report(new Error($type, '/project/'.$type.'.php', new Exception('error '.$type)));
    }
    $input = new ArrayInput([], new InputDefinition([new InputOption('test'), new InputOption('bail')]));
    $output = new SummaryOutput(null, $errors, $input, new BufferedOutput);
    $issues = $output->getIssues('/project', new ReportSummary([], 3, 0, 0, false, true, false));
    expect($issues)
        ->toHaveCount(3)
        ->and($issues->map(fn ($issue) => $issue->symbol())->all())
        ->toBe(['!', '!', '!']);
});
