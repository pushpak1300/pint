<?php

return [
    'formatter' => [
        'preset' => 'laravel',
        'classlike-brace-style' => 'next-line',
        'inline-empty-control-braces' => false,
        'inline-empty-closure-braces' => false,
        'inline-empty-anonymous-class-braces' => false,
        'array-table-style-alignment' => false,
        'empty-line-after-property' => true,
        'empty-line-after-class-like-constant' => true,
    ],
    'linter' => [
        'array-style' => ['enabled' => true, 'style' => 'short'],
        'no-redundant-use' => ['enabled' => true],
        'no-fully-qualified-global-class-like' => ['enabled' => true],
    ],
];
