<?php

use App\Mago\Cleanup;
use PhpParser\NodeDumper;
use PhpParser\ParserFactory;
use Symfony\Component\Process\Process;

// Expected source was recorded from Pint 1.32.1 (PHP-CS-Fixer 3.95.25),
// with the empty preset and only the named rule enabled. Compare syntax and
// comments, not whitespace: layout remains the responsibility of Mago.
// Exception: backticks must call the global shell_exec, not a shadowing function.
it('matches recorded legacy cleanup results', function (array $rules, string $input, string $expected) {
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $dumper = new NodeDumper(['dumpComments' => true]);
    $cleanup = new Cleanup($rules, '8.4', 'unsafe');
    $actual = $cleanup->format($input);

    expect($dumper->dump($parser->parse($actual)))->toBe($dumper->dump($parser->parse($expected)));
    expect($cleanup->format($actual))->toBe($actual);
})->with(json_decode(file_get_contents(__DIR__.'/../../Fixtures/mago/legacy-cleanup.json'), true));

it('keeps reused parsing and printing independent across files and parse errors', function () {
    $cleanup = new Cleanup(['strict_comparison' => true], '8.4', 'unsafe');

    expect($cleanup->format('<?php /* first */ return $left == $right;'))
        ->toBe('<?php /* first */ return $left === $right;');
    expect(fn () => $cleanup->format('<?php function broken( {'))->toThrow(PhpParser\Error::class);
    expect($cleanup->format('<?php namespace Other; function check($a, $b) { return $a != $b; } // second'))
        ->toBe('<?php namespace Other; function check($a, $b) { return $a !== $b; } // second');
    expect($cleanup->format('<?php return "keep == and != in strings";'))
        ->toBe('<?php return "keep == and != in strings";');
});

it('accepts the complete Pinkary rule selection', function () {
    $configuration = json_decode(file_get_contents(__DIR__.'/../../Fixtures/mago/pinkary.json'), true);

    expect(fn () => Cleanup::validate($configuration['rules']))->not->toThrow(Throwable::class);
});

it('rejects unsupported settings rather than ignoring them', function (array $rules) {
    expect(fn () => Cleanup::validate($rules))->toThrow(RuntimeException::class);
})->with([
    [['missing_rule' => true]],
    [['strict_comparison' => 'yes']],
    [['ordered_class_elements' => ['order' => 'alpha']]],
    [['ordered_class_elements' => ['sort_algorithm' => 'random']]],
    [['global_namespace_import' => ['import_functions' => 'yes']]],
    [['final_class' => ['unknown' => true]]],
    [['new_with_parentheses' => true]],
    [['lowercase_keywords' => false]],
    [['Pint/laravel_blade' => []]],
    [['array_syntax' => ['syntax' => 'compact']]],
    [['concat_space' => false]],
    [['concat_space' => ['spacing' => 'two']]],
    [['blank_line_before_statement' => true]],
    [['blank_line_before_statement' => ['statements' => ['return', 'throw']]]],
]);

it('does not run risky transformations without explicit consent', function () {
    $rules = ['strict_comparison' => true, 'final_class' => true, 'visibility_required' => true];
    $source = '<?php class A { function run($a, $b) { return $a == $b; } }';

    expect(new Cleanup($rules, '8.4', 'safe')->format($source))
        ->toContain('public function', '$a == $b')
        ->not->toContain('final class');
    expect(new Cleanup($rules, '8.4', 'unsafe')->format($source))->toContain('final class', '$a === $b');
});

it('does not capture qualified function or constant prefixes with class imports', function (
    array $rules,
    string $class,
    string $reference,
    string $definitions,
) {
    $cleanup = new Cleanup($rules, '8.4', 'unsafe');
    $source = '<?php namespace App; $x = new \\'.$class.'; '.$reference;
    $actual = $cleanup->format($source);

    expect($actual)->toBe($source);
    expect($cleanup->format($actual))->toBe($actual);

    $process = new Process([PHP_BINARY, '-r', $definitions.substr($actual, 6)]);
    $process->mustRun();
    expect($process->getOutput())->toBe('LOCAL');

    // Without an existing prefix binding to preserve, importing is still allowed.
    expect($cleanup->format('<?php namespace App; $x = new \\'.$class.';'))
        ->toContain('use '.$class.';', 'new '.substr($class, strrpos('\\'.$class, '\\')).';');
})->with([
    'qualified function' => [
        ['fully_qualified_strict_types' => ['import_symbols' => true]],
        'Vendor\\Foo',
        'Foo\\run();',
        'namespace Vendor; class Foo {} namespace Vendor\\Foo; function run() { echo "VENDOR"; } namespace App\\Foo; function run() { echo "LOCAL"; } ',
    ],
    'qualified constant with case-insensitive prefix' => [
        ['fully_qualified_strict_types' => ['import_symbols' => true]],
        'Vendor\\Foo',
        'echo foo\\VALUE;',
        'namespace Vendor; class Foo {} namespace Vendor\\Foo; const VALUE = "VENDOR"; namespace App\\Foo; const VALUE = "LOCAL"; ',
    ],
    'global function prefix' => [
        ['global_namespace_import' => ['import_classes' => true]],
        'DateTime',
        'DateTime\\run();',
        'namespace DateTime; function run() { echo "GLOBAL"; } namespace App\\DateTime; function run() { echo "LOCAL"; } ',
    ],
    'global constant prefix' => [
        ['global_namespace_import' => ['import_classes' => true]],
        'DateTime',
        'echo DateTime\\VALUE;',
        'namespace DateTime; const VALUE = "GLOBAL"; namespace App\\DateTime; const VALUE = "LOCAL"; ',
    ],
]);

it('keeps backticks bound to the built-in despite shadowing and import rules', function (
    array $imports,
    string $shadow,
) {
    $cleanup = new Cleanup(['backtick_to_shell_exec' => true, ...$imports], '8.4', 'safe');
    $source =
        '<?php namespace Other; function shell_exec($s) { return "IMPORTED"; } namespace App; '
        .$shadow
        .' echo shell_exec("printf GLOBAL"); echo `printf GLOBAL`;';
    $actual = $cleanup->format($source);

    expect($actual)->toContain("\\shell_exec('printf GLOBAL')");
    expect($cleanup->format($actual))->toBe($actual);
    $process = new Process([PHP_BINARY, '-r', substr($actual, 6)]);
    $process->mustRun();
    expect($process->getOutput())->toBe(($shadow[0] === 'u' ? 'IMPORTED' : 'LOCAL').'GLOBAL');

    // Exercise the monolithic namespace path used by global_namespace_import too.
    $monolithic = $cleanup->format(
        '<?php namespace App; function shell_exec($s) { return "LOCAL"; } echo `printf GLOBAL`;',
    );
    expect($monolithic)->toContain("\\shell_exec('printf GLOBAL')");
    expect($cleanup->format($monolithic))->toBe($monolithic);
    $process = new Process([PHP_BINARY, '-r', substr($monolithic, 6)]);
    $process->mustRun();
    expect($process->getOutput())->toBe('GLOBAL');
})->with([
    'backticks only' => [[]],
    'import functions' => [['global_namespace_import' => ['import_functions' => true]]],
    'qualify functions' => [['global_namespace_import' => ['import_functions' => false]]],
    'combined imports' => [[
        'fully_qualified_strict_types' => ['import_symbols' => true],
        'global_namespace_import' => ['import_functions' => true],
    ]],
])->with([
    'local declaration' => ['function shell_exec($s) { return "LOCAL"; }'],
    'imported alias' => ['use function Other\\shell_exec;'],
]);

it('maps substr named offsets to mb_substr starts regardless of argument order', function (
    string $arguments,
    string $expectedArguments,
) {
    $cleanup = new Cleanup(['mb_str_functions' => true], '8.4', 'unsafe');
    $source = '<?php echo substr('.$arguments.');';
    $actual = $cleanup->format($source);

    expect($actual)->toBe('<?php echo mb_substr('.$expectedArguments.');');
    expect($cleanup->format($actual))->toBe($actual);
    foreach ([$source, $actual] as $code) {
        $process = new Process([PHP_BINARY, '-r', substr($code, 6)]);
        $process->mustRun();
        expect($process->getOutput())->toBe('bc');
    }
})->with([
    ['string: "abc", offset: 1', 'string: "abc", start: 1'],
    ['offset: 1, string: "abc"', 'start: 1, string: "abc"'],
    ['string: "abcd", offset: 1, length: 2', 'string: "abcd", start: 1, length: 2'],
    ['length: 2, offset: 1, string: "abcd"', 'length: 2, start: 1, string: "abcd"'],
    ['"abcd", offset: 1, length: 2', '"abcd", start: 1, length: 2'],
]);

it('preserves substr calls whose unpacked arguments may contain named offsets', function (
    string $prefix,
    string $arguments,
) {
    $cleanup = new Cleanup(['mb_str_functions' => true], '8.4', 'unsafe');
    $source = '<?php '.$prefix.' echo substr('.$arguments.');';
    $actual = $cleanup->format($source);

    expect($cleanup->format($actual))->toBe($actual);
    foreach ([$source, $actual] as $code) {
        $process = new Process([PHP_BINARY, '-r', substr($code, 6)]);
        $process->mustRun();
        expect($process->getOutput())->toBe('bc');
    }
})->with([
    '',
    'namespace Example; function substr($s, $offset, $length) { return "LOCAL"; } namespace Example; use function substr;',
])->with([
    '"abcd", ...["offset" => 1, "length" => 2]',
    '...["string" => "abcd", "offset" => 1, "length" => 2]',
]);
