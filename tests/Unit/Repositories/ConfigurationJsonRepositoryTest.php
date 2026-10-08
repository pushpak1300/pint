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
        ->toBe(PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
});

it('rejects legacy remote configuration without depending on the network', function () {
    $repository = new class(
        'data://text/plain,'.rawurlencode('{"rules":{"no_unused_imports":false}}'),
        'psr12',
    ) extends ConfigurationJsonRepository {
        protected function fileExists(string $path)
        {
            return true;
        }
    };

    $repository->linter();
})->throws(ConsoleException::class, 'PHP-CS-Fixer [rules] are not supported');

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
    $repository = new ConfigurationJsonRepository(dirname(__DIR__, 2).'/Fixtures/extend/pint.json', 'symfony');

    expect($repository->preset())->toBe('symfony');
});

it('rejects the removed empty preset', function () {
    new ConfigurationJsonRepository(null, 'empty')->preset();
})->throws(ConsoleException::class, 'preset was removed');

it('rejects invalid configuration section types', function (string $json, string $message) {
    $repository = new class('data://text/plain,'.rawurlencode($json), null) extends ConfigurationJsonRepository {
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
