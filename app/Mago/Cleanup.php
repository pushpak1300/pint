<?php

namespace App\Mago;

use Mago\Sdk\Extension;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Reporting\Safety;
use Mago\Sdk\Reporting\TextEdit;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Worker;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter\Standard;
use RuntimeException;

final class Cleanup implements Rule
{
    /**
     * Reuse parser tables and format-preserving printer setup across files in a worker.
     */
    private Parser $parser;

    private Standard $printer;

    /**
     * Bump when a bundled transformation changes to invalidate formatting caches.
     */
    public const VERSION = 3;

    /**
     * Supported compatibility rules, with the option keys accepted by each rule.
     *
     * @var array<string, list<string>>
     */
    private const OPTIONS = [
        'array_push' => [],
        'backtick_to_shell_exec' => [],
        'date_time_immutable' => [],
        'declare_strict_types' => ['strategy'],
        'final_class' => [],
        'final_internal_class' => ['include', 'exclude', 'consider_absent_docblock_as_internal_class'],
        'final_public_method_for_abstract_class' => [],
        'fully_qualified_strict_types' => ['import_symbols', 'leading_backslash_in_global_namespace', 'phpdoc_tags'],
        'global_namespace_import' => ['import_classes', 'import_constants', 'import_functions'],
        'mb_str_functions' => [],
        'modernize_types_casting' => [],
        'no_superfluous_elseif' => [],
        'no_useless_else' => [],
        'ordered_class_elements' => ['order', 'sort_algorithm', 'case_sensitive'],
        'ordered_interfaces' => ['order', 'direction', 'case_sensitive'],
        'ordered_traits' => ['case_sensitive'],
        'protected_to_private' => [],
        'self_accessor' => [],
        'self_static_accessor' => [],
        'strict_comparison' => [],
        'visibility_required' => ['elements'],
        'modifier_keywords' => ['elements'],
        // These are provided by the native formatter or the existing Blade pipeline.
        'array_syntax' => ['syntax'],
        'concat_space' => ['spacing'],
        'blank_line_before_statement' => ['statements'],
        'no_unused_imports' => [],
        'lowercase_keywords' => [],
        'lowercase_static_reference' => [],
        'no_multiple_statements_per_line' => [],
        'new_with_parentheses' => [],
        'Pint/laravel_blade' => [],
    ];

    /** @var list<string> */
    private const RISKY = [
        'array_push',
        'date_time_immutable',
        'declare_strict_types',
        'final_class',
        'final_internal_class',
        'final_public_method_for_abstract_class',
        'mb_str_functions',
        'modernize_types_casting',
        'ordered_traits',
        'self_accessor',
        'strict_comparison',
    ];

    /**
     * Create a worker rule from validated, explicitly selected cleanup settings.
     *
     * @param  array<string, bool|array<string, mixed>>  $rules
     */
    public function __construct(
        private array $rules,
        private string $phpVersion,
        private string $safety,
        private ?string $cacheDirectory = null,
    ) {
        $this->rules = self::active($rules, $safety);
    }

    /**
     * Reject unknown rules and options instead of silently promising compatibility.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function validate(array $rules): void
    {
        foreach ($rules as $name => $options) {
            if (! array_key_exists($name, self::OPTIONS)) {
                throw new RuntimeException(
                    "Unsupported Pint cleanup rule [{$name}]. See UPGRADE.md for the supported subset.",
                );
            }

            if (! is_bool($options) && ! is_array($options)) {
                throw new RuntimeException("Rule [{$name}] must be a boolean or an options object.");
            }

            if ($name === 'Pint/laravel_blade' && ! is_bool($options)) {
                throw new RuntimeException('Rule [Pint/laravel_blade] must be a boolean.');
            }

            if ($name === 'concat_space' && $options === false) {
                throw new RuntimeException(
                    'Mago always formats concatenation; select concat_space spacing none or one.',
                );
            }

            if (
                $name === 'blank_line_before_statement'
                && $options !== false
                && $options !== ['statements' => ['return']]
            ) {
                throw new RuntimeException(
                    'Only blank_line_before_statement with statements=[return] or false is supported.',
                );
            }

            if ($name === 'new_with_parentheses' && $options !== false) {
                throw new RuntimeException(
                    'Only new_with_parentheses=false is supported; use Mago formatter settings for parentheses.',
                );
            }

            if (
                in_array(
                    $name,
                    ['lowercase_keywords', 'lowercase_static_reference', 'no_multiple_statements_per_line'],
                    true,
                )
                && $options !== true
            ) {
                throw new RuntimeException("Mago always applies [{$name}]; it cannot be disabled independently.");
            }

            if (is_array($options)) {
                foreach ($options as $key => $value) {
                    if (! in_array($key, self::OPTIONS[$name], true)) {
                        throw new RuntimeException("Unsupported option [{$name}.{$key}].");
                    }

                    $valid = match ($key) {
                        'syntax' => in_array($value, ['short', 'long'], true),
                        'spacing' => in_array($value, ['none', 'one'], true),
                        'statements' => $value === ['return'],
                        'strategy' => in_array($value, ['enforce', 'add_when_missing', 'remove'], true),
                        'sort_algorithm' => in_array($value, ['none', 'alpha'], true),
                        'direction' => in_array($value, ['ascend', 'descend'], true),
                        'order' => $name === 'ordered_interfaces'
                            ? in_array($value, ['alpha', 'length'], true)
                            : is_array($value)
                                && array_is_list($value)
                                && array_all($value, fn ($item) => is_string($item)),
                        'elements', 'include', 'exclude', 'phpdoc_tags' => is_array($value)
                            && array_is_list($value)
                            && array_all($value, fn ($item) => is_string($item)),
                        'import_classes', 'import_constants', 'import_functions' => is_bool($value) || $value === null,
                        default => is_bool($value),
                    };

                    if (! $valid) {
                        throw new RuntimeException("Invalid option [{$name}.{$key}].");
                    }
                }
            }
        }
    }

    /**
     * Select only implemented transforms permitted by the requested safety level.
     *
     * @param  array<string, bool|array<string, mixed>>  $rules
     * @return array<string, bool|array<string, mixed>>
     */
    public static function active(array $rules, string $safety): array
    {
        return array_filter(
            $rules,
            fn ($options, $name) => (
                $options !== false
                && ! in_array(
                    $name,
                    [
                        'array_syntax',
                        'concat_space',
                        'blank_line_before_statement',
                        'no_unused_imports',
                        'lowercase_keywords',
                        'lowercase_static_reference',
                        'no_multiple_statements_per_line',
                        'new_with_parentheses',
                        'Pint/laravel_blade',
                    ],
                    true,
                )
                && ($safety === 'unsafe' || ! in_array($name, self::RISKY, true))
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Start only Pint's bundled extension; never load workers from the project.
     */
    public static function work(string $path): void
    {
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        new Worker(new Extension(
            identifier: 'pint/cleanup',
            name: 'Pint cleanup',
            version: (string) self::VERSION,
            linterRules: [new self(
                $settings['rules'],
                $settings['php-version'],
                $settings['fix-safety'],
                dirname($path).'/cleanup-cache',
            )],
        ))->run();
    }

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'pint/cleanup',
            name: 'Pint cleanup',
            description: 'Apply explicitly selected Pint-owned source transformations.',
            defaultLevel: Level::Warning,
            defaultEnabled: true,
            targets: [NodeKind::Program],
        );
    }

    /**
     * Submit one coherent edit so dependent transforms cannot overlap each other.
     */
    public function lint(LintContext $context): void
    {
        $context->cancellation->throwIfCancelled();
        $source = $context->file->contents;
        $cached = $this->cacheDirectory === null ? null : $this->cacheDirectory.'/'.hash('sha256', $source);

        // Fix passes restart workers. Remember only exact inputs proven clean in this private run.
        if ($cached !== null && is_file($cached)) {
            return;
        }

        $formatted = $this->format($source);

        if ($formatted !== $source) {
            $span = new Span(0, strlen($source));
            $safety = array_intersect(array_keys($this->rules), self::RISKY) === [] ? Safety::Safe : Safety::Unsafe;

            $context->report(Issue::new('Apply Pint cleanup rules.', $span)->withEdit(
                TextEdit::replace($span, $formatted)->withSafety($safety),
            ));
        } elseif ($cached !== null && file_put_contents($cached, '') === false) {
            throw new RuntimeException('Cannot write Pint cleanup cache.');
        }
    }

    /**
     * Transform syntax while preserving untouched source, comments, and literals.
     */
    public function format(string $source): string
    {
        if ($this->rules === []) {
            return $source;
        }

        $parser = $this->parser ??= new ParserFactory()->createForVersion(PhpVersion::fromString($this->phpVersion));
        $original = $parser->parse($source) ?? [];
        $nodes = new NodeTraverser(new CloningVisitor)->traverse($original);

        foreach ([
            new ClassRules($this->rules),
            new CodeRules($this->rules, $parser->getTokens()),
            new ImportRules($this->rules),
        ] as $visitor) {
            $nodes = new NodeTraverser($visitor)->traverse($nodes);
        }

        return ($this->printer ??= new Standard)->printFormatPreserving($nodes, $original, $parser->getTokens());
    }
}
