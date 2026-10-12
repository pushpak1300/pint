<?php

use App\Repositories\ConfigurationJsonRepository;
use App\Support\Mago;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/pint-migration-'.bin2hex(random_bytes(8));
    mkdir($this->project, 0700);
    file_put_contents($this->project.'/pint.json', '{}');
    $this->pint = function (array $options = [], array $environment = [], array $phpOptions = []): Process {
        $process = new Process(
            [
                PHP_BINARY,
                ...$phpOptions,
                base_path('pint'),
                'format',
                '--config',
                $this->project.'/pint.json',
                '--format=json',
                ...$options,
                $this->project,
            ],
            $this->project,
            $environment,
            timeout: 60,
        );
        $process->run();

        return $process;
    };
});

afterEach(function () {
    new Filesystem()->remove($this->project);
});

it('checks without writes and fixes valid files without touching a broken neighbour', function () {
    file_put_contents($this->project.'/pint.json', '{"rules":{"visibility_required":true}}');
    $input = '<?php $items=array("first", "second");';
    $broken = '<?php function broken( {';
    file_put_contents($this->project.'/Valid.php', $input);
    file_put_contents($this->project.'/Broken.php', $broken);

    $check = ($this->pint)(['--test']);
    expect($check->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Valid.php'))
        ->toBe($input)
        ->and(file_get_contents($this->project.'/Broken.php'))
        ->toBe($broken)
        ->and($check->getErrorOutput())
        ->toContain('Broken.php');

    $fix = ($this->pint)(['--repair']);
    expect($fix->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Valid.php'))
        ->toBe("<?php\n\n\$items = ['first', 'second'];\n")
        ->and(file_get_contents($this->project.'/Broken.php'))
        ->toBe($broken);

    unlink($this->project.'/Broken.php');
    expect(($this->pint)(['--test'])->getExitCode())->toBe(0);
});

it('isolates Pint from Mago environment and project configuration', function () {
    file_put_contents($this->project.'/Example.php', '<?php $value = array(1, 2);');
    file_put_contents($this->project.'/mago.toml', 'this is not valid TOML');
    $process = ($this->pint)([], ['MAGO_PHP_VERSION' => '4.0']);

    expect($process->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe("<?php\n\n\$value = [1, 2];\n");
});

it('invalidates the cache when cleanup configuration or source changes', function () {
    $path = $this->project.'/Example.php';
    $cache = $this->project.'/cache.json';
    file_put_contents($path, "<?php\n\nuse App\\Unused;\n\n\$value = 1;\n");
    file_put_contents($this->project.'/pint.json', json_encode([
        'linter' => ['rules' => ['no-redundant-use' => ['enabled' => false]]],
    ]));
    expect(($this->pint)(['--cache-file='.$cache])->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toContain('use App\\Unused;');
    $first = json_decode(file_get_contents($cache), true);
    file_put_contents($this->project.'/pint.json', '{}');
    expect(($this->pint)(['--cache-file='.$cache, '--test'])->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($path))
        ->toContain('use App\\Unused;');
    expect(($this->pint)(['--cache-file='.$cache])->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->not->toContain('Unused');
    $second = json_decode(file_get_contents($cache), true);
    expect($second['signature'])->not->toBe($first['signature']);
    file_put_contents($path, '<?php $value=3;');
    expect(($this->pint)(['--cache-file='.$cache, '--test'])->getExitCode())->toBe(1);
});

it('produces the same output with sequential and parallel execution', function () {
    $files = ['A.php' => '<?php $a=array(1, 2);', 'B.php' => '<?php class B { public function value(){return "b";} }'];
    foreach ($files as $name => $code) {
        file_put_contents($this->project.'/'.$name, $code);
    }
    expect(($this->pint)()->getExitCode())->toBe(0);
    $expected = [];
    foreach ($files as $name => $code) {
        $expected[$name] = file_get_contents($this->project.'/'.$name);
        file_put_contents($this->project.'/'.$name, $code);
    }
    expect(($this->pint)(['--parallel', '--max-processes=2'])->getExitCode())->toBe(0);
    foreach ($expected as $name => $code) {
        expect(file_get_contents($this->project.'/'.$name))->toBe($code);
    }
    expect(($this->pint)(['--parallel', '--test'])->getExitCode())->toBe(0);
});

it('rejects unknown formatter settings and rules before modifying files', function (array $configuration) {
    $input = '<?php $x=1;';
    file_put_contents($this->project.'/Example.php', $input);
    file_put_contents($this->project.'/pint.json', json_encode($configuration));

    expect(($this->pint)()->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe($input);
})->with([
    'formatter typo' => [['formatter' => ['single-qoute' => true]]],
    'unknown rule' => [['linter' => ['rules' => ['not-a-rule' => ['enabled' => true]]]]],
]);

it('requires explicit consent for unsafe fixes and leaves them off by default', function () {
    $path = $this->project.'/Example.php';
    file_put_contents($path, '<?php function identity($x) { return $x; }');
    $configuration = ['linter' => ['rules' => ['strict-types' => ['enabled' => true]]]];
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->not->toContain('declare(strict_types=1)');
    $configuration['fix-safety'] = 'unsafe';
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toContain('declare(strict_types=1)');
});

it('keeps generated syntax compatible with the configured PHP version', function () {
    $path = $this->project.'/Example.php';
    file_put_contents($path, '<?php (new Example())->run();');
    file_put_contents($this->project.'/pint.json', '{"php-version":"8.3"}');
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe("<?php\n\n(new Example)->run();\n");

    file_put_contents($this->project.'/pint.json', '{"php-version":"8.4"}');
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe("<?php\n\nnew Example()->run();\n");
});

it('keeps nested new-property chains valid and stable for the configured PHP version', function (string $version) {
    $path = $this->project.'/Example.php';
    file_put_contents($this->project.'/pint.json', json_encode(['php-version' => $version]));
    file_put_contents($path, <<<'PHP'
        <?php
        // café: offsets must be bytes, not characters.
        $value = (new Outer((new Inner())->map->toString()->values()->all()))
            ->map->toString()->values()->all();
        $other = (new Example($value))
            ?->items?->first()?->values()?->all();
        $literal = 'new Example()->items';
        PHP);

    $fix = ($this->pint)();
    expect($fix->getExitCode())->toBe(0, $fix->getErrorOutput().$fix->getOutput());
    $formatted = file_get_contents($path);
    expect($formatted)->toContain("'new Example()->items'");
    if ($version === '8.3') {
        expect($formatted)->toContain('(new Inner', '(new Outer(', '(new Example($value))');
    }
    expect(($this->pint)(['--test'])->getExitCode())->toBe(0);
    expect(file_get_contents($path))->toBe($formatted);

    if ($version === '8.3') {
        $invalid = '<?php $value = new Example()->items;';
        file_put_contents($path, $invalid);
        expect(($this->pint)()->getExitCode())->toBe(1);
        expect(file_get_contents($path))->toBe($invalid);
    }
})->with(['8.3', '8.4']);

it('serves cached PHP without starting Mago but still starts it for changed files', function () {
    $path = $this->project.'/Example.php';
    $options = ['--cache-file='.$this->project.'/cache.json'];
    file_put_contents($path, '<?php $items=array(1, 2);');
    expect(($this->pint)($options)->getExitCode())->toBe(0);
    $formatted = file_get_contents($path);

    $cached = ($this->pint)([...$options, '--test'], phpOptions: ['-d', 'disable_functions=proc_open']);
    expect($cached->getExitCode())->toBe(0)->and(file_get_contents($path))->toBe($formatted);

    $changed = '<?php $items=array(3, 4);';
    file_put_contents($path, $changed);
    $miss = ($this->pint)($options, phpOptions: ['-d', 'disable_functions=proc_open']);
    expect($miss->getExitCode())->toBe(1)->and(file_get_contents($path))->toBe($changed);

    expect(($this->pint)($options)->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe("<?php\n\n\$items = [3, 4];\n");
});

it('validates changed configuration even with no files to format', function () {
    $options = ['--cache-file='.$this->project.'/cache.json'];
    expect(($this->pint)($options)->getExitCode())->toBe(0);
    file_put_contents($this->project.'/pint.json', '{"formatter":{"single-qoute":true}}');

    expect(($this->pint)($options)->getExitCode())->toBe(1);
});

it('keeps the Mago fingerprint stable across initialization and formatting', function () {
    $mago = new Mago(new ConfigurationJsonRepository($this->project.'/pint.json', null));
    $fingerprint = $mago->fingerprint();

    expect($mago->formatFiles([]))->toBe([])->and($mago->fingerprint())->toBe($fingerprint);
    $result = $mago->formatFiles(['Example.php' => '<?php $items=array(1, 2);']);
    expect($result['Example.php']['code'])
        ->toBe("<?php\n\n\$items = [1, 2];\n")
        ->and($mago->fingerprint())
        ->toBe($fingerprint);
});

it('maps common legacy formatting rules to native settings', function (array $rules, string $array) {
    $path = $this->project.'/Example.php';
    file_put_contents($this->project.'/pint.json', json_encode(['rules' => $rules]));
    file_put_contents($path, <<<'PHP'
        <?php namespace App;
        use Vendor\Unused;
        function sample() { $list = array(1, 2); $text = 'a'.'b'; return $list; }
        PHP);

    $fix = ($this->pint)();
    expect($fix->getExitCode())->toBe(0, $fix->getErrorOutput().$fix->getOutput());
    $output = file_get_contents($path);
    expect($output)->toContain('$list = '.$array.';');
    expect(str_contains($output, 'use Vendor\\Unused;'))->toBe($rules['no_unused_imports'] === false);
    expect(str_contains($output, "\n\n    return"))->toBe($rules['blank_line_before_statement'] !== false);
    expect($output)->toContain(($rules['concat_space']['spacing'] ?? 'none') === 'one' ? "'a' . 'b'" : "'a'.'b'");
    expect(($this->pint)(['--test'])->getExitCode())->toBe(0);
})->with([
    'long arrays, spaced concatenation and return separation' => [
        [
            'array_syntax' => ['syntax' => 'long'],
            'concat_space' => ['spacing' => 'one'],
            'blank_line_before_statement' => ['statements' => ['return']],
            'no_unused_imports' => true,
        ],
        'array(1, 2)',
    ],
    'short arrays, default concatenation and disabled cleanups' => [
        [
            'array_syntax' => true,
            'concat_space' => true,
            'blank_line_before_statement' => false,
            'no_unused_imports' => false,
        ],
        '[1, 2]',
    ],
    'disabled array conversion' => [
        [
            'array_syntax' => false,
            'concat_space' => ['spacing' => 'one'],
            'blank_line_before_statement' => false,
            'no_unused_imports' => true,
        ],
        'array(1, 2)',
    ],
]);

it('runs bundled cleanup rules together and reaches a cache-free fixed point', function (int $threads) {
    $configuration = json_decode(file_get_contents(base_path('tests/Fixtures/mago/pinkary.json')), true);
    $configuration['rules']['Pint/laravel_blade'] = false;
    $configuration['fix-safety'] = 'unsafe';
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    file_put_contents($this->project.'/mago.toml', 'this is not valid TOML');
    $path = $this->project.'/Example.php';
    file_put_contents($path, <<<'PHP'
        <?php
        declare(strict_types=0);
        namespace Example;
        use DateTime;
        class Service {
            protected function last() { return new DateTime; }
            const TOKEN = 1;
            public function run($left, $right, $values) {
                array_push($values, intval($left));
                if ($left == $right) { return strlen($left); } else { return `pwd`; }
            }
        }
        PHP);

    $fix = ($this->pint)(['--parallel', '--max-processes='.$threads], ['MAGO_PHP_VERSION' => '4.0']);
    expect($fix->getExitCode())->toBe(0, $fix->getErrorOutput().$fix->getOutput());
    $formatted = file_get_contents($path);
    expect($formatted)
        ->toContain(
            'declare(strict_types=1)',
            'final class Service',
            'private function last',
            'public const TOKEN',
            'use DateTimeImmutable;',
            '$values[] = (int) $left;',
            '$left === $right',
            'mb_strlen($left)',
            "shell_exec('pwd')",
        )
        ->not->toContain('else');
    expect(strpos($formatted, 'public const TOKEN'))->toBeLessThan(strpos($formatted, 'public function run'));
    expect(strpos($formatted, 'public function run'))->toBeLessThan(strpos($formatted, 'private function last'));
    $check = ($this->pint)(['--test']);
    expect($check->getExitCode())->toBe(0, $check->getErrorOutput().$check->getOutput());
})->with([1, 2]);

it('requires unsafe opt-in for risky cleanup while still applying safe cleanup', function () {
    $path = $this->project.'/Example.php';
    $configuration = ['rules' => ['strict_comparison' => true, 'final_class' => true, 'visibility_required' => true]];
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    file_put_contents($path, '<?php class Example { function run($a, $b) { return $a == $b; } }');
    expect(($this->pint)()->getExitCode())->toBe(0);
    expect(file_get_contents($path))->toContain('public function', '$a == $b')->not->toContain('final class');
    $configuration['fix-safety'] = 'unsafe';
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    expect(($this->pint)()->getExitCode())->toBe(0);
    expect(file_get_contents($path))->toContain('final class', '$a === $b');
});

it('invalidates cleanup caches on rule changes without starting workers on cache hits', function () {
    $path = $this->project.'/Example.php';
    $options = ['--cache-file='.$this->project.'/cache.json'];
    file_put_contents(
        $this->project.'/pint.json',
        '{"rules":{"visibility_required":true,"strict_comparison":false},"fix-safety":"unsafe"}',
    );
    file_put_contents($path, '<?php $same = $a == $b;');
    expect(($this->pint)($options)->getExitCode())->toBe(0);
    file_put_contents($this->project.'/pint.json', '{"rules":{"strict_comparison":true},"fix-safety":"unsafe"}');
    expect(($this->pint)([...$options, '--test'])->getExitCode())->toBe(1);
    expect(($this->pint)($options)->getExitCode())->toBe(0);
    expect(file_get_contents($path))->toContain('$a === $b');
    expect(($this->pint)([...$options, '--test'], phpOptions: ['-d', 'disable_functions=proc_open'])->getExitCode())
        ->toBe(0);
});

it('does not apply whole-file cleanup to Blade PHP fragments', function () {
    file_put_contents(
        $this->project.'/pint.json',
        '{"rules":{"declare_strict_types":true,"strict_comparison":true},"fix-safety":"unsafe"}',
    );
    $mago = new Mago(new ConfigurationJsonRepository($this->project.'/pint.json', null));

    expect($mago->formatFragment('<?php $same = $a == $b; ?>'))
        ->toContain('$a == $b;', '?>')
        ->not->toContain('declare(');
});

it('keeps Laravel layout without changing arrays, closures or string contents', function (bool $nativeOverrides) {
    $configuration = [
        'formatter' => ['print-width' => 60, 'classlike-brace-style' => 'same-line'],
        'linter' => ['rules' => ['array-style' => ['enabled' => false]]],
    ];
    if ($nativeOverrides) {
        $configuration['formatter'] += ['closure-brace-style' => 'same-line', 'trailing-comma' => true];
    }
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    $mago = new Mago(new ConfigurationJsonRepository($this->project.'/pint.json', null));
    $source = <<<'PHP'
        <?php
        $object = new class(Example::class, new class { public $value = 1; }) { public $value = 2; };
        class Named { public $value = 3; }
        $callback = function () { return 'literal: new class { call(1,) }'; };
        function sample($firstParameter, $secondParameter, $thirdParameter) {}
        sample('first_argument_long', 'second_argument_long', 'third_argument');
        $values = ['first_array_value_long', 'second_array_value_long', 'third_array_value'];
        $legacy = array('first_array_value_long', 'second_array_value_long', 'third_array_value');
        $interpolated = "){$values[0]}(";
        $text = <<<'TEXT'
        /*
         | Keep this text unchanged.
         */
        TEXT;
        /*
         | Align this comment.
         */
        return $object;
        PHP;
    $source .= "\n\$lineEnds = 'a\r\nb';";
    $result = $mago->formatFiles(['Example.php' => $source])['Example.php'];
    expect($result['error'])->toBeNull();
    $output = $result['code'];
    expect($output)->toContain(
        'class Named {',
        '$callback = function () {',
        "'literal: new class { call(1,) }'",
        "'third_array_value',\n]",
        "'third_array_value',\n)",
        "\n| Align this comment.\n*/",
    );
    $parser = new PhpParser\ParserFactory()->createForNewestSupportedVersion();
    $strings = fn (string $code) => array_map(
        fn (PhpParser\Node\Scalar\String_ $node) => $node->value,
        new PhpParser\NodeFinder()->findInstanceOf($parser->parse($code), PhpParser\Node\Scalar\String_::class),
    );
    expect($strings($output))->toBe($strings($source));
    expect($output)->toContain($nativeOverrides ? 'new class {' : "new class\n");
    expect($output)->toContain("\$thirdParameter,\n)", "'third_argument',\n)");
    expect($mago->formatFiles(['Example.php' => $output])['Example.php'])->toBe($result);
})->with([false, true]);
