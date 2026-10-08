<?php

use LaravelZero\Framework\Exceptions\ConsoleException;

it('ensures configuration file is valid', function () {
    [$statusCode, $output] = run('default', [
        'path' => base_path('tests/Fixtures/with-invalid-configuration'),
    ]);
})->throws(ConsoleException::class, 'is not valid JSON.');

it('rejects a configuration loaded over plaintext http', function () {
    run('default', [
        '--config' => 'http://example.com/pint.json',
    ]);
})->throws(ConsoleException::class, 'Loading the configuration over plaintext HTTP is not allowed. Use HTTPS.');

it('rejects legacy top-level rules with migration guidance', function () {
    $file = testOutputFile();
    file_put_contents($file, '{"rules":{"array_syntax":{"syntax":"short"}}}');

    run('default', [
        'path' => base_path('tests/Fixtures/without-issues-laravel'),
        '--config' => $file,
    ]);
})->throws(ConsoleException::class, 'PHP-CS-Fixer [rules] are not supported');

it('rejects the removed empty preset with migration guidance', function () {
    run('default', [
        'path' => base_path('tests/Fixtures/without-issues-laravel'),
        '--preset' => 'empty',
    ]);
})->throws(ConsoleException::class, 'preset was removed');

it('uses configured in paths when no path is provided', function () {
    $cwd = getcwd();

    chdir(base_path('tests/Fixtures/finder-in'));

    try {
        [$statusCode, $output] = run('default', []);
    } finally {
        chdir($cwd);
    }

    $output = str_replace('\\/', DIRECTORY_SEPARATOR, $output);

    expect($statusCode)
        ->toBe(1)
        ->and($output)
        ->toContain(implode(DIRECTORY_SEPARATOR, ['included', 'file.php']))
        ->and($output)
        ->not->toContain(implode(DIRECTORY_SEPARATOR, ['excluded', 'file.php']));
});

it('uses explicit paths over configured in paths', function () {
    $fixture = base_path('tests/Fixtures/finder-in');
    $cwd = getcwd();

    chdir($fixture);

    try {
        [$statusCode, $output] = run('default', [
            'path' => $fixture.'/excluded',
            '--config' => $fixture.'/pint.json',
        ]);
    } finally {
        chdir($cwd);
    }

    $output = str_replace('\\/', DIRECTORY_SEPARATOR, $output);

    expect($statusCode)
        ->toBe(1)
        ->and($output)
        ->toContain(implode(DIRECTORY_SEPARATOR, ['excluded', 'file.php']))
        ->and($output)
        ->not->toContain(implode(DIRECTORY_SEPARATOR, ['included', 'file.php']));
});
