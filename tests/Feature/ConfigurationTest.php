<?php

use LaravelZero\Framework\Exceptions\ConsoleException;
use Symfony\Component\Process\Process;

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

it('rejects unsupported legacy rules with migration guidance', function () {
    $file = testOutputFile();
    file_put_contents($file, '{"rules":{"phpdoc_align":true}}');

    run('default', [
        'path' => base_path('tests/Fixtures/without-issues-laravel'),
        '--config' => $file,
    ]);
})->throws(RuntimeException::class, 'Unsupported Pint cleanup rule [phpdoc_align]');

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

it('lets explicit files override finder exclusions but preserves directory discovery', function (
    string $path,
    bool $explicit,
) {
    $directory = freshBladeTempDirectory();
    mkdir($directory.'/storage');
    file_put_contents($directory.'/storage/A.php', '<?php $a=1;');
    file_put_contents($directory.'/Visible.php', '<?php $a=1;');
    file_put_contents($directory.'/pint.json', '{"notName":["A.php"]}');

    $process = new Process(['php', base_path('pint'), 'format', '--format=json', $path], $directory);
    $process->run();

    expect($process->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($directory.'/storage/A.php'))
        ->toBe($explicit ? "<?php\n\n\$a = 1;\n" : '<?php $a=1;')
        ->and(file_get_contents($directory.'/Visible.php'))
        ->toBe($explicit ? '<?php $a=1;' : "<?php\n\n\$a = 1;\n");
})->with([
    'plain explicit file' => ['storage/A.php', true],
    'dot explicit file' => ['./storage/A.php', true],
    'directory control' => ['.', false],
]);
