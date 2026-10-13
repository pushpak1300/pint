<?php

use App\Actions\ElaborateSummary;
use App\ValueObjects\Error;
use App\ValueObjects\ErrorsManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;

it('preserves failure exit codes independently of the engine', function (
    bool $test,
    bool $bail,
    bool $repair,
    bool $changed,
    ?int $errorType,
    int $expected,
) {
    $definition = new InputDefinition(array_map(
        fn ($name) => new InputOption(
            $name,
            null,
            in_array($name, ['format', 'output-to-file', 'output-format'])
                ? InputOption::VALUE_REQUIRED
                : InputOption::VALUE_NONE,
        ),
        ['test', 'bail', 'repair', 'format', 'output-to-file', 'output-format'],
    ));
    $input = new ArrayInput([
        '--test' => $test,
        '--bail' => $bail,
        '--repair' => $repair,
        '--format' => 'agent',
    ], $definition);
    $errors = new ErrorsManager;
    if ($errorType !== null) {
        $errors->report(new Error($errorType, 'file.php', new Exception('bad code')));
    }
    $action = new ElaborateSummary($errors, $input, new BufferedOutput, null);
    $changes = $changed ? ['file.php' => ['appliedFixers' => ['format'], 'diff' => '']] : [];
    expect($action->execute(1, $changes))->toBe($expected);
})->with([
    'clean' => [false, false, false, false, null, 0],
    'fixed' => [false, false, false, true, null, 0],
    'test' => [true, false, false, true, null, 1],
    'bail' => [false, true, false, true, null, 1],
    'repair' => [false, false, true, true, null, 1],
    'invalid' => [false, false, false, false, Error::TYPE_INVALID, 1],
    'exception' => [false, false, false, false, Error::TYPE_EXCEPTION, 1],
    'lint' => [false, false, false, false, Error::TYPE_LINT, 1],
]);
