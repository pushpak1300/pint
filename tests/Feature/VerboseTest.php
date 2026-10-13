<?php

it('displays the code diff', function () {
    [$statusCode, $output] = run('default', [
        'path' => base_path('tests/Fixtures/with-fixable-issues'),
        '--preset' => 'psr12',
    ]);

    expect($statusCode)->toBe(1)->and($output)->toContain('-$a = new stdClass;')->toContain('+$a = new stdClass()');
});

it('highlights the invalid source line rather than the internal exception line', function () {
    [$statusCode, $output] = run('default', [
        'path' => base_path('tests/Fixtures/with-non-fixable-issues'),
    ]);

    expect($statusCode)->toBe(1)->and($output)->toContain('3▕', '$a = new stdClass');
});
