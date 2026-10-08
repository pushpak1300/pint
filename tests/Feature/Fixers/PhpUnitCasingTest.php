<?php

use Illuminate\Support\Facades\Process;

it('preserves PHPUnit method casing until a custom extension is approved', function () {
    $input = file_get_contents(base_path('tests/Fixtures/fixers/phpunit_method_casing.php'));
    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())->toBe($input);
});
