<?php

use Illuminate\Support\Facades\Process;

it('places closure braces on the same line', function () {
    [$statusCode, $output] = run('default', [
        'path' => base_path('tests/Fixtures/fixers/braces.php'),
        '--preset' => 'laravel',
    ]);

    expect($statusCode)
        ->toBe(1)
        ->and($output)
        ->toContain('  ⨯')
        ->toContain(<<<'EOF'
              -$a = function ()
              -{
              +$a = function () {
                   // ..
               };
            EOF);
});

it('keeps anonymous class braces on the same line', function () {
    $input = file_get_contents(base_path('tests/Fixtures/fixers/braces.php'));
    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())
        ->toContain("new class {\n    // ..\n};")
        ->toContain("new class extends stdClass {\n    // ..\n};");
});
