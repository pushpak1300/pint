<?php

use Illuminate\Support\Facades\Process;

it('preserves prose comments and PHPDoc instead of applying the removed type-only extension', function () {
    $input = <<<'PHP'
        <?php

        // Explain why this value exists.
        /**
         * A useful description.
         *
         * @var int
         */
        $value = 1;

        PHP;

    $result = Process::input($input)->run('php pint -')->throw();

    expect($result->output())->toBe($input);
});
