<?php

use Illuminate\Support\Facades\Process;

it('preserves comparison operand order outside the selected cleanup rules', function () {
    $input = file_get_contents(base_path('tests/Fixtures/fixers/yoda_style.php'));
    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())
        ->toContain('if (null === $int) {')
        ->toContain('if ($object->count() === $int) {')
        ->toContain('if (array_values($array) !== $array) {')
        ->toContain('if ($object->int === $int && (int) $object->int === $int) {');
});
