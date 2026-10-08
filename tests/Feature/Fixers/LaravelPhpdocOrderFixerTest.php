<?php

use Illuminate\Support\Facades\Process;

it('preserves PHPDoc tag order until a custom extension is approved', function () {
    $input = file_get_contents(base_path('tests/Fixtures/fixers/laravel_phpdoc_order.php'));
    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())->toBe($input);
});
