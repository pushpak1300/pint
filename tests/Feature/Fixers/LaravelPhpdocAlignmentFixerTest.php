<?php

use Illuminate\Support\Facades\Process;

it('preserves PHPDoc parameter alignment until a custom extension is approved', function () {
    $input = file_get_contents(base_path('tests/Fixtures/fixers/laravel_phpdoc_alignment.php'));
    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())->toBe($input);
});
