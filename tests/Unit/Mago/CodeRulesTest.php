<?php

use App\Mago\CodeRules;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

function applyCodeRules(string $source, array $rules): string
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $original = $parser->parse($source);
    $clones = new NodeTraverser(new CloningVisitor)->traverse($original);
    $changed = new NodeTraverser(new CodeRules($rules, $parser->getTokens()))->traverse($clones);
    $output = new Standard()->printFormatPreserving($changed, $original, $parser->getTokens());

    // Reparse the actual integration output, not just a regenerated AST.
    return new Standard()->prettyPrintFile($parser->parse($output));
}

it('implements expression rules with format preserving output', function (
    string $rule,
    string $input,
    string $expected,
) {
    $printer = new Standard;
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    expect(applyCodeRules('<?php '.$input, [$rule => true]))
        ->toBe($printer->prettyPrintFile($parser->parse('<?php '.$expected)));
})->with([
    ['array_push', 'array_push($a, $v);', '$a[] = $v;'],
    ['array_push', '\\ArRaY_PuSh($a, $v);', '$a[] = $v;'],
    ['array_push', '$x = array_push($a, $v);', '$x = array_push($a, $v);'],
    ['array_push', 'array_push($a, $v, $w);', 'array_push($a, $v, $w);'],
    ['array_push', 'array_push($a, $v,);', 'array_push($a, $v,);'],
    ['array_push', 'array_push($a, ...$v);', 'array_push($a, ...$v);'],
    ['array_push', 'array_push($o?->a, $v);', 'array_push($o?->a, $v);'],
    ['array_push', 'array_push($a, $v and $w);', 'array_push($a, $v and $w);'],
    ['array_push', 'array_push($a, yield $v);', 'array_push($a, yield $v);'],
    ['backtick_to_shell_exec', '`ls`; ', "\\shell_exec('ls');"],
    ['backtick_to_shell_exec', '`ls $path`; ', '\\shell_exec("ls $path");'],
    ['backtick_to_shell_exec', '`ls "x"`; ', '`ls "x"`;'],
    [
        'date_time_immutable',
        'new DateTime; DateTime::createFromFormat("Y", "2026");',
        'new DateTimeImmutable; DateTimeImmutable::createFromFormat("Y", "2026");',
    ],
    ['date_time_immutable', 'DateTime::ATOM; DateTime::class;', 'DateTime::ATOM; DateTime::class;'],
    ['date_time_immutable', 'namespace A; new DateTime;', 'namespace A; new DateTime;'],
    [
        'date_time_immutable',
        'namespace A; use DateTime; new DateTime;',
        'namespace A; use DateTime; new \\DateTimeImmutable;',
    ],
    [
        'date_time_immutable',
        'namespace A; use DateTime; function f(DateTime $d): DateTime { return $d; }',
        'namespace A; use DateTime; function f(\\DateTimeImmutable $d): \\DateTimeImmutable { return $d; }',
    ],
    ['date_time_immutable', 'use DateTime as Clock; new Clock;', 'use DateTime as Clock; new Clock;'],
    [
        'date_time_immutable',
        'use Vendor\\Clock as DateTime; new DateTime;',
        'use Vendor\\Clock as DateTime; new DateTime;',
    ],
    [
        'date_time_immutable',
        'date_create(); date_create_from_format("Y", "2026");',
        'date_create_immutable(); date_create_immutable_from_format("Y", "2026");',
    ],
    [
        'modernize_types_casting',
        'intval($a + $b); floatval($a); doubleval($a); strval($a); boolval($a);',
        '(int) ($a + $b); (float) $a; (float) $a; (string) $a; (bool) $a;',
    ],
    [
        'modernize_types_casting',
        'intval($a, 16); intval(value: $a); intval(...$a);',
        'intval($a, 16); intval(value: $a); intval(...$a);',
    ],
    ['strict_comparison', '$a == $b; $a != $b; $a <> $b;', '$a === $b; $a !== $b; $a !== $b;'],
    ['declare_strict_types', 'echo 1;', 'declare(strict_types=1); echo 1;'],
    ['declare_strict_types', 'declare(strict_types=0); echo 1;', 'declare(strict_types=1); echo 1;'],
    [
        'mb_str_functions',
        'strlen($s); substr($s, 1, 2); strlen($s, 2);',
        'mb_strlen($s); mb_substr($s, 1, 2); strlen($s, 2);',
    ],
    ['mb_str_functions', 'use function strlen; strlen($s);', 'use function mb_strlen; mb_strlen($s);'],
    [
        'mb_str_functions',
        'namespace A; use function strlen; strlen($s);',
        'namespace A; use function mb_strlen; mb_strlen($s);',
    ],
    [
        'mb_str_functions',
        'namespace A; function strlen($s) {} strlen($s); \\strlen($s);',
        'namespace A; function strlen($s) {} strlen($s); \\mb_strlen($s);',
    ],
    ['mb_str_functions', 'use function Vendor\\strlen; strlen($s);', 'use function Vendor\\strlen; strlen($s);'],
    ['mb_str_functions', 'use function strlen as length; length($s);', 'use function strlen as length; length($s);'],
    [
        'mb_str_functions',
        '$o->strlen($s); C::strlen($s); A\\strlen($s); namespace\\strlen($s); strlen(...);',
        '$o->strlen($s); C::strlen($s); A\\strlen($s); namespace\\strlen($s); strlen(...);',
    ],
    [
        'no_superfluous_elseif',
        'if ($a) { return; } elseif ($b) { return; } elseif ($c) { work(); }',
        'if ($a) { return; } if ($b) { return; } if ($c) { work(); }',
    ],
    [
        'no_superfluous_elseif',
        'if ($a) { work(); } elseif ($b) { return; }',
        'if ($a) { work(); } elseif ($b) { return; }',
    ],
    [
        'no_superfluous_elseif',
        'if ($a) { return; work(); } elseif ($b) { return; }',
        'if ($a) { return; work(); } elseif ($b) { return; }',
    ],
    [
        'no_useless_else',
        'if ($a) { throw new Exception; } else { work(); }',
        'if ($a) { throw new Exception; } work();',
    ],
    [
        'no_useless_else',
        'if ($a) { $b && throw new Exception; } else { work(); }',
        'if ($a) { $b && throw new Exception; } else { work(); }',
    ],
    [
        'no_useless_else',
        'if ($a) { if ($b) { return; } } else { work(); }',
        'if ($a) { if ($b) { return; } } else { work(); }',
    ],
    ['no_useless_else', 'if ($a) { work(); } else {}', 'if ($a) { work(); }'],
    [
        'no_useless_else',
        'if ($a) { return; } else { if ($b) { function f() {} } }',
        'if ($a) { return; } else { if ($b) { function f() {} } }',
    ],
    [
        'no_useless_else',
        'if ($a) { return; } else { $f = function () {}; $c = new class {}; }',
        'if ($a) { return; } $f = function () {}; $c = new class {};',
    ],
    ['no_useless_else', 'if ($a): return; else: work(); endif;', 'if ($a) { return; } work();'],
]);

it('leaves absent and disabled rules untouched and accepts option arrays', function () {
    $source = '<?php $a == $b;';
    expect(applyCodeRules($source, []))->toBe(applyCodeRules($source, ['strict_comparison' => false]));
    expect(applyCodeRules($source, ['strict_comparison' => []]))->toContain('===');
});

it('preserves comments and avoids adding strict types to inline HTML', function () {
    expect(applyCodeRules('<?php /* header */ echo 1;', ['declare_strict_types' => true]))
        ->toContain('/* header */', 'strict_types=1');
    expect(applyCodeRules('hello <?php echo 1;', ['declare_strict_types' => true]))->not->toContain('strict_types');
    expect(applyCodeRules('<?php if ($a) { return; } /* fallback */ else { /* body */ work(); }', [
        'no_useless_else' => true,
    ]))
        ->toContain('/* fallback */', '/* body */');
    $empty = applyCodeRules('<?php if ($a) { work(); } else { /* empty */ }', ['no_useless_else' => true]);
    expect($empty)->toContain('/* empty */')->not->toContain('else');
});

it('maps every supported multibyte arity only when the internal function exists', function (
    string $name,
    array $counts,
) {
    foreach (range(0, 5) as $count) {
        $source = '<?php '.$name.'('.implode(', ', array_fill(0, $count, '$x')).');';
        $enabled =
            in_array($count, $counts, true)
            && function_exists('mb_'.$name)
            && new ReflectionFunction('mb_'.$name)->isInternal();
        expect(applyCodeRules($source, ['mb_str_functions' => true]))->toContain(($enabled ? 'mb_' : '').$name.'(');
    }
})->with([
    ['str_split', [1, 2, 3]],
    ['stripos', [2, 3]],
    ['stristr', [2, 3]],
    ['strpos', [2, 3]],
    ['strripos', [2, 3]],
    ['strrpos', [2, 3]],
    ['strstr', [2, 3]],
    ['substr', [2, 3]],
    ['strlen', [1]],
    ['strtolower', [1]],
    ['strtoupper', [1]],
    ['strrchr', [2]],
    ['substr_count', [2, 3, 4]],
    ['str_pad', [1, 2, 3, 4]],
    ['trim', [1, 2]],
    ['ltrim', [1, 2]],
    ['rtrim', [1, 2]],
]);

it('recognizes only direct terminating statements', function (string $terminator) {
    $source = '<?php if ($a) { '.$terminator.' } else { work(); }';
    expect(applyCodeRules($source, ['no_useless_else' => true]))->not->toContain('else');
})->with(['return;', 'throw new Exception;', 'break;', 'continue;', 'goto done;', 'exit;']);

it('does not hoist any named declaration', function (string $declaration) {
    $source = '<?php if ($a) { return; } else { '.$declaration.' }';
    expect(applyCodeRules($source, ['no_useless_else' => true]))->toContain('else');
})->with(['function f() {}', 'class C {}', 'interface I {}', 'trait T {}', 'enum E {}']);

it('honors strict declaration strategies and shebangs', function () {
    $source = '<?php declare(strict_types=0); echo 1;';
    expect(applyCodeRules($source, ['declare_strict_types' => ['strategy' => 'add_when_missing']]))
        ->toContain('strict_types=0');
    expect(applyCodeRules($source, ['declare_strict_types' => ['preserve_existing_declaration' => true]]))
        ->toContain('strict_types=0');
    expect(applyCodeRules($source, ['declare_strict_types' => ['strategy' => 'remove']]))
        ->not
        ->toContain('strict_types');
    expect(applyCodeRules("#!/usr/bin/env php\n<?php echo 1;", ['declare_strict_types' => true]))
        ->toContain('#!/usr/bin/env php', 'strict_types=1');
});

it('keeps namespace blocks independent and sees nested declarations', function () {
    $source = '<?php namespace A { use function Vendor\\strlen; strlen($s); } namespace B { strlen($s); } namespace C { function outer() { function strlen($s) {} } strlen($s); }';
    $output = applyCodeRules($source, ['mb_str_functions' => true]);
    expect(substr_count($output, 'mb_strlen('))->toBe(1);
    expect(substr_count($output, 'strlen($s);'))->toBe(3);
});

it('cleans alternative syntax elseif chains without invalid PHP', function () {
    $source = '<?php if ($a): return; elseif ($b): return; else: work(); endif;';
    $output = applyCodeRules($source, ['no_superfluous_elseif' => true, 'no_useless_else' => true]);
    expect($output)->not->toContain('elseif', 'else');
    expect(substr_count($output, 'if ('))->toBe(2);
});
