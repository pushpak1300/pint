<?php

use App\Mago\ImportRules;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Assert;

/**
 * @param  array<string, mixed>  $rules
 */
function rewriteImportRules(string $code, array $rules): string
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $original = $parser->parse($code);
    $cloned = new NodeTraverser(new CloningVisitor)->traverse($original);
    $changed = new NodeTraverser(new ImportRules($rules))->traverse($cloned);

    return new Standard()->printFormatPreserving($changed, $original, $parser->getTokens());
}

it('imports explicitly global references in separate categories without capturing fallback calls', function () {
    $output = rewriteImportRules('<?php namespace App; new \\DateTime; \\count([]); \\M_PI; count([]);', [
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => true, 'import_constants' => true],
        'fully_qualified_strict_types' => true,
    ]);

    expect($output)->toContain('use DateTime;', 'use const M_PI;', 'new DateTime;', '\\count([]);');
    Assert::assertStringNotContainsString('use function count;', $output);
});

it('reuses global aliases and does not fabricate conflicting aliases', function () {
    $output = rewriteImportRules('<?php namespace App; use DateTime as Clock; use Other\\Exception; new \\DateTime; new \\Exception;', [
        'global_namespace_import' => true,
    ]);

    expect($output)->toContain('new Clock;', 'new \\Exception;');
    Assert::assertStringNotContainsString('use DateTime;', $output);
});

it('blocks imports shadowing later declarations and unqualified references', function () {
    $output = rewriteImportRules('<?php namespace App; new \\Exception; new \\DateTime; new datetime; class Exception {}', [
        'global_namespace_import' => true,
    ]);

    expect($output)->toContain('new \\Exception;', 'new \\DateTime;');
    Assert::assertStringNotContainsString('use Exception;', $output);
    Assert::assertStringNotContainsString('use DateTime;', $output);
});

it('keeps class function and constant collision tables independent', function () {
    $output = rewriteImportRules('<?php namespace App; class Same {} function same() {} const SAME = 1; new \\Same; \\Same(); \\Same; \\SAME;', [
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => true, 'import_constants' => true],
    ]);

    expect($output)->toContain('new \\Same;', '\\Same();', 'use const Same;', '\\SAME;');
    Assert::assertStringNotContainsString('use function Same;', $output);
    Assert::assertStringNotContainsString('use Same;', $output);
});

it('qualifies only nonaliased global imports when false and respects null', function () {
    $output = rewriteImportRules(
        '<?php namespace App; use DateTime; use Exception as Error; use function count; use const M_PI; new DateTime; new Error; count([]); M_PI;',
        [
            'global_namespace_import' => [
                'import_classes' => false,
                'import_functions' => false,
                'import_constants' => null,
            ],
            'fully_qualified_strict_types' => true,
        ],
    );

    expect($output)->toContain('new \\DateTime;', 'new Error;', '\\count([]);', 'M_PI;');
});

it('guards multiple namespaces global scopes and inline html', function (string $code) {
    expect(rewriteImportRules($code, ['global_namespace_import' => true]))->toBe($code);
})->with([
    '<?php namespace A { new \\DateTime; } namespace B { new \\DateTime; }',
    '<?php namespace { new \\DateTime; }',
    '<?php namespace A; new \\DateTime; ?>html<?php new \\Exception;',
]);

it('shortens classes with aliases prefix imports and the current namespace only', function () {
    $output = rewriteImportRules(
        '<?php namespace App; use Vendor\\Thing as Alias; use Vendor\\Package as Prefix; use Other\\Local; new \\Vendor\\Thing; new \\Vendor\\Package\\Child; new \\App\\Own; new \\App\\Local; \\Vendor\\functionName(); \\Vendor\\CONSTANT;',
        ['fully_qualified_strict_types' => true],
    );

    expect($output)->toContain(
        'new Alias;',
        'new Prefix\\Child;',
        'new Own;',
        'new \\App\\Local;',
        '\\Vendor\\functionName();',
        '\\Vendor\\CONSTANT;',
    );
});

it('does not auto import two classes sharing a basename or namespaced unqualified uses', function () {
    $code = '<?php namespace App; new \\First\\Thing; new \\Second\\Thing; new Local; new \\Vendor\\Local;';
    expect(rewriteImportRules($code, ['fully_qualified_strict_types' => ['import_symbols' => true]]))->toBe($code);
    expect(rewriteImportRules($code, ['fully_qualified_strict_types' => true]))->toBe($code);
});

it('optionally imports a noncolliding class', function () {
    $output = rewriteImportRules('<?php namespace App; new \\Vendor\\Thing; new \\Vendor\\Thing;', ['fully_qualified_strict_types' => [
        'import_symbols' => true,
    ]]);

    expect($output)->toContain('use Vendor\\Thing;', 'new Thing;');
    Assert::assertStringNotContainsString('new \\Vendor\\Thing;', $output);
});

it('removes global class slashes unless imports collide', function () {
    expect(rewriteImportRules('<?php use Other\\DateTime; new \\DateTime; new \\Exception; \\count([]); \\M_PI;', [
        'fully_qualified_strict_types' => true,
    ]))
        ->toContain('new \\DateTime;', 'new Exception;', '\\count([]);', '\\M_PI;');
});

it('preserves class attributes comments traits types catches and namespace structure', function () {
    $code = <<<'PHP'
        <?php
        namespace App {
            use Vendor\Thing as Alias;
            // keep this comment
            #[\Vendor\Thing]
            class Example extends \Vendor\Thing {
                use \Vendor\Thing;
                public function run(\Vendor\Thing $value): \Vendor\Thing {
                    try { return new \Vendor\Thing; } catch (\Vendor\Thing $exception) {
                        \Vendor\Thing::run();
                        return $value instanceof \Vendor\Thing ? $value : $exception;
                    }
                }
            }
        }
        PHP;
    $output = rewriteImportRules($code, ['fully_qualified_strict_types' => true]);

    expect($output)->toContain(
        'namespace App {',
        '// keep this comment',
        '#[Alias]',
        'extends Alias',
        'use Alias;',
        'Alias $value',
        ': Alias',
        'catch (Alias',
        'Alias::run()',
        'instanceof Alias',
    );
    Assert::assertStringNotContainsString('\\Vendor\\Thing', $output);
});

it('rewrites validated phpdoc types without rewriting prose or templates', function () {
    $code = <<<'PHP'
        <?php namespace App;
        use Vendor\Thing as Alias;
        /**
         * Mention \Vendor\Thing in prose.
         * @template T
         * @param \Vendor\Thing|null $value prose \Vendor\Thing
         * @psalm-return \Vendor\Thing[]
         * @var T
         * @return array<T, \Vendor\Thing>
         */
        function run($value) {}
        PHP;
    $output = rewriteImportRules($code, ['fully_qualified_strict_types' => true]);

    expect($output)->toContain(
        'Mention \\Vendor\\Thing in prose.',
        '@param Alias|null $value prose \\Vendor\\Thing',
        '@psalm-return Alias[]',
        '@var T',
        '@return array<T, \\Vendor\\Thing>',
    );
});

it('blocks global imports shadowing phpdoc types including unsupported compound types', function (string $type) {
    $code = '<?php namespace App; /** @var '.$type.' $value */ $value = new \\DateTime;';

    expect(rewriteImportRules($code, ['global_namespace_import' => true]))->toBe($code);
})->with(['DateTime', 'array<DateTime>', 'DateTime | null']);

it('keeps disabled rules byte identical and changes idempotent', function () {
    $code = '<?php namespace App; /* comment */ new \\DateTime;';
    expect(rewriteImportRules($code, []))->toBe($code);
    expect(rewriteImportRules($code, ['global_namespace_import' => false, 'fully_qualified_strict_types' => false]))
        ->toBe($code);
    $rules = ['global_namespace_import' => true, 'fully_qualified_strict_types' => true];
    $once = rewriteImportRules($code, $rules);
    expect(rewriteImportRules($once, $rules))->toBe($once);
});

it('imports all three Pinkary categories and preserves repeated-run stability', function () {
    $code = '<?php namespace App; /** @var \\DateTime $value */ $value = new \\DateTime; \\strlen("hello"); \\PHP_VERSION;';
    $rules = [
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => true, 'import_constants' => true],
        'fully_qualified_strict_types' => true,
    ];
    $output = rewriteImportRules($code, $rules);

    expect($output)->toContain(
        'use DateTime;',
        'use function strlen;',
        'use const PHP_VERSION;',
        '@var DateTime',
        'new DateTime;',
        'strlen("hello");',
    );
    expect(rewriteImportRules($output, $rules))->toBe($output);
});

it('supports group imports relative class names and see members without touching prose', function () {
    $code = <<<'PHP'
        <?php namespace App;
        use Vendor\{Thing as Alias, Other};
        /**
         * @see \Vendor\Thing::run()
         * @property-read \Vendor\Other $value
         * prose @return \Vendor\Thing
         * @return \Vendor\Thing | Other
         */
        function run(namespace\Own $value) { new \Vendor\Other; }
        PHP;
    $output = rewriteImportRules($code, ['fully_qualified_strict_types' => true]);

    expect($output)->toContain(
        '@see Alias::run()',
        '@property-read Other $value',
        'prose @return \\Vendor\\Thing',
        '@return \\Vendor\\Thing | Other',
        'run(Own $value)',
        'new Other;',
    );
});

it('keeps fully qualified class decisions stable when global importing is false', function () {
    $code = '<?php namespace App; use DateTime; new DateTime;';
    $rules = ['global_namespace_import' => ['import_classes' => false], 'fully_qualified_strict_types' => true];
    $output = rewriteImportRules($code, $rules);

    expect(rewriteImportRules($output, $rules))->toBe($output);
});

it('supports explicit global slash retention without touching function or constant names', function () {
    $code = '<?php new Exception; count([]); M_PI;';
    expect(rewriteImportRules($code, ['fully_qualified_strict_types' => [
        'leading_backslash_in_global_namespace' => true,
    ]]))
        ->toContain('new \\Exception;', 'count([]);', 'M_PI;');
});
