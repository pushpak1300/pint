<?php

use App\Repositories\ConfigurationJsonRepository;
use LaravelZero\Framework\Exceptions\ConsoleException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

uses(Tests\TestCase::class);

function bladeInput(bool $blade): InputInterface
{
    $input = new ArrayInput(
        $blade ? ['--blade' => true] : [],
        new InputDefinition([
            new InputOption('blade', null, InputOption::VALUE_NONE),
        ]),
    );

    return $input;
}

it('works without json file', function () {
    $repository = new ConfigurationJsonRepository(null, 'psr12');

    expect($repository->finder())
        ->toBeEmpty()
        ->and($repository->formatter())
        ->toBeEmpty()
        ->and($repository->linter())
        ->toBeEmpty()
        ->and($repository->blade())
        ->toBeFalse()
        ->and($repository->phpVersion())
        ->toBe('8.4');
});

it('rejects unsupported remote rules without depending on the network', function () {
    $repository = new class(
        'data://text/plain,'.rawurlencode('{"rules":{"phpdoc_align":true}}'),
        'psr12',
    ) extends ConfigurationJsonRepository {
        protected function fileExists(string $path)
        {
            return true;
        }
    };

    $repository->linter();
})->throws(RuntimeException::class, 'Unsupported Pint cleanup rule [phpdoc_align]');

it('may have rules options', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/rules/pint.json', 'psr12');

    expect($repository->linter())
        ->toBe([
            'no-redundant-use' => ['enabled' => false],
        ])
        ->and($repository->formatter())
        ->toBe(['print-width' => 100])
        ->and($repository->phpVersion())
        ->toBe('8.2');
});

it('enables the blade rule when the --blade option is passed', function () {
    app()->instance(InputInterface::class, bladeInput(true));

    $repository = new ConfigurationJsonRepository(null, null);

    expect($repository->blade())->toBeTrue();
});

it('lets the --blade option take over even when disabled in pint.json', function () {
    app()->instance(InputInterface::class, bladeInput(true));

    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/rules/blade-disabled.json', null);

    expect($repository->blade())->toBeTrue();
});

it('respects the blade rule from pint.json when the --blade option is absent', function () {
    app()->instance(InputInterface::class, bladeInput(false));

    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/rules/blade-disabled.json', null);

    expect($repository->blade())->toBeFalse();
});

it('may have finder options', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/finder/pint.json', null);

    expect($repository->finder())->toBe([
        'exclude' => [
            'my-dir',
        ],
        'notName' => [
            '*-my-file.php',
        ],
        'notPath' => [
            'path/to/excluded-file.php',
        ],
    ]);
});

it('may define paths to inspect', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/finder-in/pint.json', null);

    expect($repository->finder())
        ->toMatchArray([
            'in' => [
                'included',
            ],
        ])
        ->and($repository->hasIncludedPaths())
        ->toBeTrue();
});

it('may have a preset option', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/preset/pint.json', null);

    expect($repository->preset())->toBe('laravel');
});

it('properly extend the base config file', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/extend/pint.json', null);

    expect($repository->preset())
        ->toBe('laravel')
        ->and($repository->formatter())
        ->toBe(['print-width' => 120, 'tab-width' => 4])
        ->and($repository->linter())
        ->toBe([
            'array-style' => ['enabled' => false, 'style' => 'short'],
            'no-redundant-use' => ['enabled' => true],
            'no-fully-qualified-global-class-like' => ['enabled' => true],
        ])
        ->and($repository->blade())
        ->toBeFalse()
        ->and($repository->phpVersion())
        ->toBe('8.2');
});

it('lets the CLI preset override the extended configuration preset', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/extend/pint.json', 'psr12');

    expect($repository->preset())->toBe('psr12');
});

it('rejects the removed empty preset', function () {
    new ConfigurationJsonRepository(null, 'empty')->preset();
})->throws(ConsoleException::class, 'preset was removed');

it('rejects removed presets from the CLI and configuration', function (string $preset) {
    expect(fn () => new ConfigurationJsonRepository(null, $preset)->preset())
        ->toThrow(ConsoleException::class, 'Select laravel or psr12');
    expect(fn () => configurationFixture(['preset' => $preset])->preset())
        ->toThrow(ConsoleException::class, 'Select laravel or psr12');
})->with(['per', 'symfony']);

it('rejects invalid configuration section types', function (string $json, string $message) {
    $repository = new class('data://text/plain,'.rawurlencode($json), null) extends ConfigurationJsonRepository
    {
        protected function fileExists(string $path)
        {
            return true;
        }
    };

    expect(fn () => $repository->finder())->toThrow(ConsoleException::class, $message);
})->with([
    'formatter scalar' => ['{"formatter":true}', 'Configuration [formatter] must be an object'],
    'linter scalar' => ['{"linter":true}', 'Configuration [linter] must be an object'],
    'linter rules scalar' => ['{"linter":{"rules":true}}', 'Configuration [linter.rules] must be an object'],
    'blade scalar' => ['{"blade":"true"}', 'Configuration [blade] must be a boolean'],
    'linter file selection' => ['{"linter":{"excludes":[]}}', 'Only [linter.rules] is supported'],
]);

it('throw an error if the extended configuration also has an extend', function () {
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/extend_recursive/pint.json', null);

    $repository->finder();
})->throws(LogicException::class);

function configurationFixture(array $configuration): ConfigurationJsonRepository
{
    $path = testOutputFile();
    file_put_contents($path, json_encode($configuration, JSON_THROW_ON_ERROR));

    return new ConfigurationJsonRepository($path, null);
}

it('uses Laravel defaults without enabling compatibility refactors', function () {
    $repository = new ConfigurationJsonRepository(null, null);

    expect($repository->preset())
        ->toBe('laravel')
        ->and($repository->analyzer())
        ->toBe(['strictness' => 'balanced'])
        ->and($repository->guard())
        ->toBeEmpty()
        ->and($repository->cleanup())
        ->toBeEmpty()
        ->and($repository->fixSafety())
        ->toBe('safe');

    foreach (['format', 'lint', 'analyze', 'guard', 'refactor'] as $section) {
        expect($repository->sectionEnabled($section))->toBeTrue();
    }
});

it('exposes canonical sections without leaking enabled to native options', function () {
    $repository = configurationFixture([
        'format' => ['print-width' => 90],
        'lint' => ['enabled' => false, 'rules' => ['array-style' => ['enabled' => false]]],
        'analyze' => ['enabled' => false, 'strictness' => 'strict', 'find-unused-expressions' => true],
        'guard' => ['enabled' => false, 'rules' => ['foo' => ['enabled' => false]]],
        'refactor' => ['enabled' => false, 'rules' => ['strict_comparison' => true]],
    ]);

    expect($repository->formatter())
        ->toBe(['print-width' => 90])
        ->and($repository->linter())
        ->toBe(['array-style' => ['enabled' => false]])
        ->and($repository->analyzer())
        ->toBe(['strictness' => 'strict', 'find-unused-expressions' => true])
        ->and($repository->guard())
        ->toBe(['rules' => ['foo' => ['enabled' => false]]])
        ->and($repository->cleanup())
        ->toBe(['strict_comparison' => true])
        ->and($repository->fixSafety())
        ->toBe('safe');

    foreach (['lint', 'analyze', 'guard', 'refactor'] as $section) {
        expect($repository->sectionEnabled($section))->toBeFalse();
    }
});

it('retains legacy native aliases and Blade settings', function () {
    app()->instance(InputInterface::class, bladeInput(false));
    $repository = configurationFixture([
        'rules' => [
            'concat_space' => ['spacing' => 'one'],
            'blank_line_before_statement' => ['statements' => ['return']],
            'array_syntax' => ['syntax' => 'long'],
            'no_unused_imports' => false,
            'Pint/laravel_blade' => true,
        ],
    ]);

    expect($repository->formatter())
        ->toBe([
            'space-around-concatenation-binary-operator' => true,
            'empty-line-before-return' => true,
        ])
        ->and($repository->linter())
        ->toBe([
            'array-style' => ['enabled' => true, 'style' => 'long'],
            'no-redundant-use' => ['enabled' => false],
        ])
        ->and($repository->blade())
        ->toBeTrue();
});

it('merges matching and disjoint aliases', function () {
    $repository = configurationFixture([
        'formatter' => ['print-width' => 90],
        'format' => ['print-width' => 90, 'tab-width' => 4],
        'linter' => ['rules' => ['array-style' => ['enabled' => true]]],
        'lint' => ['rules' => ['array-style' => ['style' => 'long']]],
        'rules' => ['strict_comparison' => true],
        'refactor' => ['rules' => ['strict_comparison' => true, 'no_useless_else' => true]],
    ]);

    expect($repository->formatter())
        ->toBe(['print-width' => 90, 'tab-width' => 4])
        ->and($repository->linter())
        ->toBe(['array-style' => ['enabled' => true, 'style' => 'long']])
        ->and($repository->cleanup())
        ->toBe(['strict_comparison' => true, 'no_useless_else' => true]);
});

it('clearly rejects conflicting aliases', function (array $configuration, string $method) {
    expect(fn () => configurationFixture($configuration)->{$method}())
        ->toThrow(ConsoleException::class, 'Conflicting configuration');
})->with([
    [['formatter' => ['print-width' => 80], 'format' => ['print-width' => 100]], 'formatter'],
    [
        [
            'linter' => ['rules' => ['array-style' => ['enabled' => true]]],
            'lint' => ['rules' => ['array-style' => ['enabled' => false]]],
        ],
        'linter',
    ],
    [['rules' => ['strict_comparison' => true], 'refactor' => ['rules' => ['strict_comparison' => false]]], 'cleanup'],
    [
        [
            'rules' => ['concat_space' => ['spacing' => 'one']],
            'format' => ['space-around-concatenation-binary-operator' => false],
        ],
        'formatter',
    ],
    [
        ['rules' => ['no_unused_imports' => true], 'lint' => ['rules' => ['no-redundant-use' => ['enabled' => false]]]],
        'linter',
    ],
    [['rules' => ['Pint/laravel_blade' => true], 'blade' => false], 'finder'],
]);

it('validates canonical sections', function (array $configuration, string $message) {
    expect(fn () => configurationFixture($configuration)->finder())->toThrow(ConsoleException::class, $message);
})->with([
    [['format' => false], '[format] must be an object'],
    [['lint' => null], '[lint] must be an object'],
    [['analyze' => ['strict']], '[analyze] must be an object'],
    [['guard' => 'yes'], '[guard] must be an object'],
    [['refactor' => true], '[refactor] must be an object'],
    [['lint' => ['rules' => true]], '[lint.rules] must be an object'],
    [['lint' => ['rules' => ['array-style' => true]]], 'must be an options object'],
    [['lint' => ['rules' => ['array-style' => ['enabled' => 'no']]]], 'must be a boolean'],
    [['guard' => ['enabled' => null]], '[guard.enabled] must be a boolean'],
    [['refactor' => ['enabled' => 'false']], '[refactor.enabled] must be a boolean'],
    [['analyze' => ['strictness' => 'lenient']], '[analyze.strictness] must be balanced or strict'],
    [['analyze' => ['strictness' => null]], '[analyze.strictness] must be balanced or strict'],
    [['refactor' => ['rules' => false]], '[refactor.rules] must be an object'],
    [['php-version' => 8.4], '[php-version] must be a version string'],
    [['laravel-version' => null], '[laravel-version] must be a version string'],
    [['linter' => ['rules' => null]], '[linter.rules] must be an object'],
    [['rules' => [true]], '[rules] must be an object'],
    [['blade' => null], '[blade] must be a boolean'],
]);

it('validates compatibility rules in the canonical section', function () {
    configurationFixture(['refactor' => ['rules' => ['phpdoc_align' => true]]])->cleanup();
})->throws(RuntimeException::class, 'Unsupported Pint cleanup rule [phpdoc_align]');

it('rejects unknown stage names', function () {
    new ConfigurationJsonRepository(null, null)->sectionEnabled('typo');
})->throws(InvalidArgumentException::class, 'Unknown configuration section');

it('extends canonical configuration and keeps explicit versions authoritative', function () {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/base.json', json_encode([
        'format' => ['print-width' => 80, 'tab-width' => 4],
        'analyze' => ['strictness' => 'strict', 'enabled' => true],
        'refactor' => ['rules' => ['strict_comparison' => true]],
        'php-version' => '8.2',
        'laravel-version' => '12',
    ]));
    file_put_contents($root.'/pint.json', json_encode([
        'extend' => 'base.json',
        'format' => ['print-width' => 100],
        'analyze' => ['enabled' => false],
        'php-version' => '8.4',
        'laravel-version' => '13.1',
    ]));
    $repository = new ConfigurationJsonRepository($root.'/pint.json', null);

    expect($repository->formatter())
        ->toBe(['print-width' => 100, 'tab-width' => 4])
        ->and($repository->analyzer())
        ->toBe(['strictness' => 'strict'])
        ->and($repository->sectionEnabled('analyze'))
        ->toBeFalse()
        ->and($repository->cleanup())
        ->toBe(['strict_comparison' => true])
        ->and($repository->projectVersions($root))
        ->toBe(['php' => '8.4', 'laravel' => '13.1'])
        ->and($repository->localPaths())
        ->toBe([$root.'/pint.json', $root.'/base.json']);
});

it('infers project versions independently of the host and lock', function () {
    $root = freshBladeTempDirectory();
    mkdir($root.'/vendor/composer', recursive: true);
    file_put_contents($root.'/composer.json', '{"require":{"php":"^8.2 || ^8.4"}}');
    file_put_contents($root.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"v11.0.0"}]}');
    file_put_contents(
        $root.'/vendor/composer/installed.json',
        '{"packages":[{"name":"laravel/framework","version":"v12.1.0"}]}',
    );
    $repository = new ConfigurationJsonRepository(null, null);
    $cwd = getcwd();

    try {
        chdir($root);
        expect($repository->phpVersion())
            ->toBe('8.2')
            ->and($repository->projectVersions($root))
            ->toBe(['php' => '8.2', 'laravel' => '12.1']);
        unlink($root.'/composer.json');
        expect($repository->phpVersion())
            ->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
            ->and($repository->formatter())
            ->toBeEmpty();
    } finally {
        chdir($cwd);
    }
});

it('requires project information only when project versions are requested', function () {
    $root = freshBladeTempDirectory();
    $repository = new ConfigurationJsonRepository(null, null);

    expect($repository->formatter())
        ->toBeEmpty()
        ->and(fn () => $repository->projectVersions($root))
        ->toThrow(RuntimeException::class, 'missing');
});

it('keeps formatting standalone when Composer cannot supply a PHP floor', function () {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/composer.json', '{"require":{"php":"*"}}');
    $repository = new ConfigurationJsonRepository(null, null);
    $cwd = getcwd();

    try {
        chdir($root);
        expect($repository->phpVersion())
            ->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
            ->and(fn () => $repository->projectVersions($root))
            ->toThrow(RuntimeException::class, 'Cannot infer a PHP floor');
        expect(fn () => configurationFixture(['php-version' => '^8.4'])->phpVersion())
            ->toThrow(RuntimeException::class, 'php-version');
    } finally {
        chdir($cwd);
    }
});

it('rejects conflicting list-valued aliases rather than combining their values', function () {
    configurationFixture([
        'rules' => ['ordered_class_elements' => ['order' => ['constant']]],
        'refactor' => ['rules' => ['ordered_class_elements' => ['order' => ['constant', 'property']]]],
    ])->cleanup();
})->throws(ConsoleException::class, 'Conflicting configuration');
