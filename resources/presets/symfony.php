<?php

// Symfony is an approximation using Mago's existing formatting options.
return [
    'formatter' => [
        'preset' => 'psr-12',
        'single-quote' => true,
        'space-around-concatenation-binary-operator' => false,
        'empty-line-before-return' => true,
    ],
    'linter' => [
        'array-style' => ['enabled' => true, 'style' => 'short'],
        'no-redundant-use' => ['enabled' => true],
    ],
];
