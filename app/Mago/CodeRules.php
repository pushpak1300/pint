<?php

namespace App\Mago;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Token;
use ReflectionFunction;

final class CodeRules extends NodeVisitorAbstract
{
    private string $namespace = '';

    /** @var array<string, array<string, true>> */
    private array $functions = [];

    /** @var array<string, string> */
    private array $imports = [];

    private bool $dateTimeImported = false;

    private bool $dateTimeShadowed = false;

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<Token>  $tokens
     */
    public function __construct(
        private array $rules,
        private array $tokens = [],
    ) {}

    public function beforeTraverse(array $nodes): array
    {
        $this->functions = [];
        $this->collectFunctions($nodes, '');
        $this->setScope($nodes, '');

        $html = new NodeFinder()->findInstanceOf($nodes, Stmt\InlineHTML::class);
        $shebang =
            count($html) === 1
            && isset($nodes[0])
            && $nodes[0] === $html[0]
            && preg_match('/\A#![^\r\n]*(?:\r?\n)?\z/', $html[0]->value) === 1;
        if (
            $this->enabled('declare_strict_types')
            && $this->strictStrategy() !== 'remove'
            && (
                $html === []
                || $shebang
            )
        ) {
            $existing = new NodeFinder()->findFirst(
                $nodes,
                fn (Node $node): bool => (
                    $node instanceof Node\DeclareItem
                    && strtolower($node->key->name) === 'strict_types'
                ),
            );

            if (! $existing) {
                array_splice($nodes, $shebang ? 1 : 0, 0, [new Stmt\Declare_([new Node\DeclareItem(
                    'strict_types',
                    new Scalar\Int_(1),
                )])]);
            }
        }

        return $nodes;
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\Namespace_) {
            $this->setScope($node->stmts, $node->name?->toString() ?? '');
        }

        if ($this->enabled('date_time_immutable') && ! $node instanceof Expr\ClassConstFetch) {
            foreach (['type', 'returnType', 'types', 'class', 'extends', 'implements', 'traits'] as $field) {
                if (! property_exists($node, $field)) {
                    continue;
                }
                $value = $node->$field;
                if ($value instanceof Name && $this->isDateTime($value)) {
                    $node->$field = $this->immutableName($value);
                } elseif (is_array($value)) {
                    foreach ($value as $index => $name) {
                        if ($name instanceof Name && $this->isDateTime($name)) {
                            $node->{$field}[$index] = $this->immutableName($name);
                        }
                    }
                }
            }
        }

        return null;
    }

    public function leaveNode(Node $node): Node|array|int|null
    {
        if (
            $node instanceof Node\DeclareItem
            && strtolower($node->key->name) === 'strict_types'
            && $this->enabled('declare_strict_types')
        ) {
            if ($this->strictStrategy() === 'remove') {
                return NodeTraverser::REMOVE_NODE;
            }
            if ($this->strictStrategy() === 'enforce') {
                $node->value = new Scalar\Int_(1, $node->value->getAttributes());
            }
        }
        if ($node instanceof Stmt\Declare_ && $node->declares === [] && $this->enabled('declare_strict_types')) {
            return (
                $node->getComments() === []
                    ? NodeTraverser::REMOVE_NODE
                    : new Stmt\Nop(['comments' => $node->getComments()])
            );
        }

        if ($node instanceof Expr\BinaryOp && $this->enabled('strict_comparison')) {
            if ($node instanceof Expr\BinaryOp\Equal) {
                return new Expr\BinaryOp\Identical($node->left, $node->right, $this->replacementAttributes($node));
            }
            if ($node instanceof Expr\BinaryOp\NotEqual) {
                return new Expr\BinaryOp\NotIdentical($node->left, $node->right, $this->replacementAttributes($node));
            }
        }

        if ($node instanceof Expr\ShellExec && $this->enabled('backtick_to_shell_exec')) {
            foreach ($node->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart && strpbrk($part->value, "'\"`") !== false) {
                    return null;
                }
            }
            $value =
                count($node->parts) === 1 && $node->parts[0] instanceof Node\InterpolatedStringPart
                    ? new Scalar\String_($node->parts[0]->value)
                    : new Scalar\InterpolatedString($node->parts);

            return new Expr\FuncCall(
                new Name\FullyQualified('shell_exec'),
                [new Node\Arg($value)],
                $this->replacementAttributes($node),
            );
        }

        if ($node instanceof Expr\FuncCall && ($name = $this->globalFunction($node)) !== null) {
            if (
                $this->enabled('modernize_types_casting')
                && count($node->args) === 1
                && $node->args[0] instanceof Node\Arg
                && ! $node->args[0]->unpack
                && $node->args[0]->name === null
            ) {
                $cast = match ($name) {
                    'intval' => Expr\Cast\Int_::class,
                    'floatval', 'doubleval' => Expr\Cast\Double::class,
                    'strval' => Expr\Cast\String_::class,
                    'boolval' => Expr\Cast\Bool_::class,
                    default => null,
                };
                if ($cast !== null) {
                    $value = $node->args[0]->value;
                    $value->setAttributes($this->replacementAttributes($value));
                    $attributes = $this->replacementAttributes($node);
                    if ($cast === Expr\Cast\Double::class) {
                        $attributes['kind'] = Expr\Cast\Double::KIND_FLOAT;
                    }

                    return new $cast($value, $attributes);
                }
            }
            // Unpacked arrays can hide named offsets that mb_substr does not accept.
            if (
                $name === 'substr'
                && $this->enabled('mb_str_functions')
                && array_any(
                    $node->args,
                    fn ($arg) => (
                        $arg instanceof Node\Arg
                        && $arg->unpack
                    ),
                )
            ) {
                if (($this->imports[$name] ?? null) === $name) {
                    $node->name = new Name\FullyQualified($name);
                }

                return null;
            }
            $replacement = $this->replacement($name, count($node->args));
            if ($replacement !== null) {
                if ($name === 'substr' && $replacement === 'mb_substr') {
                    foreach ($node->args as $arg) {
                        if ($arg instanceof Node\Arg && $arg->name?->name === 'offset') {
                            $arg->name = new Node\Identifier('start', $arg->name->getAttributes());
                        }
                    }
                }
                $node->name = $node->name instanceof Name\FullyQualified
                    ? new Name\FullyQualified($replacement)
                    : new Name($replacement);
            }
        }

        if (
            $node instanceof Stmt\Expression
            && $node->expr instanceof Expr\FuncCall
            && $this->enabled('array_push')
            && $this->globalFunction($node->expr) === 'array_push'
            && ! $this->hasTrailingComma($node->expr)
        ) {
            $args = $node->expr->args;
            if (
                count($args) === 2
                && $args[0] instanceof Node\Arg
                && $args[1] instanceof Node\Arg
                && ! $args[0]->unpack
                && ! $args[1]->unpack
                && $args[0]->name === null
                && $args[1]->name === null
                && ! new NodeFinder()->findFirst(
                    [$args[0]->value],
                    fn (Node $n): bool => (
                        $n instanceof Expr\NullsafeMethodCall
                        || $n instanceof Expr\NullsafePropertyFetch
                    ),
                )
                && ! (
                    $args[1]->value instanceof Expr\BinaryOp\LogicalAnd
                    || $args[1]->value instanceof Expr\BinaryOp\LogicalOr
                    || $args[1]->value instanceof Expr\BinaryOp\LogicalXor
                    || $args[1]->value instanceof Expr\Yield_
                    || $args[1]->value instanceof Expr\YieldFrom
                )
            ) {
                $node->expr = new Expr\Assign(
                    new Expr\ArrayDimFetch($args[0]->value),
                    $args[1]->value,
                    $this->replacementAttributes($node->expr),
                );
            }
        }

        if (
            $node instanceof Stmt\Use_
            && $node->type === Stmt\Use_::TYPE_FUNCTION
            && $this->enabled('mb_str_functions')
        ) {
            foreach ($node->uses as $use) {
                if (
                    $use->alias === null
                    && ($replacement = $this->mbReplacement(strtolower($use->name->toString()), null)) !== null
                ) {
                    $use->name = new Name($replacement, $use->name->getAttributes());
                }
            }
        }

        if ($node instanceof Stmt\If_) {
            return $this->cleanBranches($node);
        }

        return null;
    }

    private function enabled(string $rule): bool
    {
        return isset($this->rules[$rule]) && $this->rules[$rule] !== false;
    }

    private function strictStrategy(): string
    {
        $options = $this->rules['declare_strict_types'] ?? true;

        return is_array($options)
            ? $options['strategy']
            ?? (! empty($options['preserve_existing_declaration']) ? 'add_when_missing' : 'enforce')
            : 'enforce';
    }

    /** @return array<string, mixed> */
    private function replacementAttributes(Node $node): array
    {
        $attributes = $node->getAttributes();
        unset($attributes['origNode']);

        return $attributes;
    }

    /** @param array<Node> $nodes */
    private function collectFunctions(array $nodes, string $namespace): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Stmt\Namespace_) {
                $this->collectFunctions($node->stmts, strtolower($node->name?->toString() ?? ''));
            } elseif ($node instanceof Stmt\Function_) {
                $this->functions[$namespace][strtolower($node->name->name)] = true;
                $this->collectFunctions($node->stmts, $namespace);
            } else {
                foreach ($node->getSubNodeNames() as $property) {
                    $children = $node->$property;
                    if (is_array($children)) {
                        $this->collectFunctions(
                            array_filter($children, fn ($child): bool => $child instanceof Node),
                            $namespace,
                        );
                    } elseif ($children instanceof Node) {
                        $this->collectFunctions([$children], $namespace);
                    }
                }
            }
        }
    }

    /** @param array<Node> $nodes */
    private function setScope(array $nodes, string $namespace): void
    {
        $this->namespace = strtolower($namespace);
        $this->imports = [];
        $this->dateTimeImported = false;
        $this->dateTimeShadowed = false;
        foreach ($nodes as $node) {
            if (! $node instanceof Stmt\Use_ && ! $node instanceof Stmt\GroupUse) {
                continue;
            }
            foreach ($node->uses as $use) {
                $name = strtolower(
                    ($node instanceof Stmt\GroupUse ? $node->prefix->toString().'\\' : '').$use->name->toString(),
                );
                $type = $use->type ?: $node->type;
                if ($type === Stmt\Use_::TYPE_FUNCTION) {
                    $this->imports[strtolower($use->getAlias()->name)] = $use->alias === null ? $name : '';
                } elseif ($type === Stmt\Use_::TYPE_NORMAL && $name === 'datetime' && $use->alias === null) {
                    $this->dateTimeImported = true;
                } elseif ($type === Stmt\Use_::TYPE_NORMAL && strtolower($use->getAlias()->name) === 'datetime') {
                    $this->dateTimeShadowed = true;
                }
            }
        }
    }

    private function globalFunction(Expr\FuncCall $call): ?string
    {
        if (
            ! $call->name instanceof Name
            || $call->isFirstClassCallable()
            || $call->name instanceof Name\Relative
            || count($call->name->getParts()) !== 1
        ) {
            return null;
        }
        $name = strtolower($call->name->toString());
        if ($call->name instanceof Name\FullyQualified) {
            return $name;
        }
        if (array_key_exists($name, $this->imports)) {
            return $this->imports[$name] === $name ? $name : null;
        }

        return isset($this->functions[$this->namespace][$name]) ? null : $name;
    }

    private function isDateTime(Name $name): bool
    {
        return (
            strtolower($name->toString()) === 'datetime'
            && ! $name instanceof Name\Relative
            && (
                $name instanceof Name\FullyQualified
                || ! $this->dateTimeShadowed
                && ($this->namespace === '' || $this->dateTimeImported)
            )
        );
    }

    private function immutableName(Name $name): Name
    {
        $attributes = $this->replacementAttributes($name);

        return $this->namespace !== '' || $name->isFullyQualified()
            ? new Name\FullyQualified('DateTimeImmutable', $attributes)
            : new Name('DateTimeImmutable', $attributes);
    }

    private function hasTrailingComma(Expr\FuncCall $call): bool
    {
        for ($index = $call->getEndTokenPos() - 1; $index >= 0 && isset($this->tokens[$index]); $index--) {
            $token = $this->tokens[$index];
            if (! in_array($token->id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token->text === ',';
            }
        }

        return false;
    }

    private function replacement(string $name, int $count): ?string
    {
        if ($this->enabled('date_time_immutable')) {
            if ($name === 'date_create') {
                return 'date_create_immutable';
            }
            if ($name === 'date_create_from_format') {
                return 'date_create_immutable_from_format';
            }
        }

        return $this->enabled('mb_str_functions') ? $this->mbReplacement($name, $count) : null;
    }

    private function mbReplacement(string $name, ?int $count): ?string
    {
        $counts = match ($name) {
            'str_split' => [1, 2, 3],
            'stripos', 'stristr', 'strpos', 'strripos', 'strrpos', 'strstr', 'substr' => [2, 3],
            'strlen', 'strtolower', 'strtoupper' => [1],
            'strrchr' => [2],
            'substr_count' => [2, 3, 4],
            // Pint's minimum host PHP is 8.4; availability below also excludes polyfills.
            'str_pad' => [1, 2, 3, 4],
            'trim', 'ltrim', 'rtrim' => [1, 2],
            default => [],
        };
        $replacement = 'mb_'.$name;

        return $counts !== []
        && ($count === null || in_array($count, $counts, true))
        && function_exists($replacement)
        && new ReflectionFunction($replacement)->isInternal()
            ? $replacement
            : null;
    }

    /** @return array<Stmt>|null */
    private function cleanBranches(Stmt\If_ $node): ?array
    {
        $result = [$node];
        if (
            $this->enabled('no_superfluous_elseif')
            && $this->terminates($node->stmts)
            && $node->elseifs !== []
            && ! $this->hasDeclaration([...$node->elseifs, ...($node->else->stmts ?? [])])
        ) {
            $branch = array_shift($node->elseifs);
            $next = new Stmt\If_(
                $branch->cond,
                ['stmts' => $branch->stmts, 'elseifs' => $node->elseifs, 'else' => $node->else],
                $this->replacementAttributes($branch),
            );
            $node->elseifs = [];
            $node->else = null;
            array_push($result, ...$this->cleanBranches($next) ?? [$next]);
        }
        if ($this->enabled('no_useless_else') && $node->else !== null) {
            $empty = array_filter($node->else->stmts, fn (Stmt $statement): bool => ! $statement instanceof Stmt\Nop)
            === [];
            $terminating = $this->terminates($node->stmts);
            foreach ($node->elseifs as $branch) {
                $terminating = $terminating && $this->terminates($branch->stmts);
            }
            $declaration = $this->hasDeclaration($node->else->stmts);
            if ($empty || $terminating && ! $declaration) {
                if ($node->else->getComments() !== []) {
                    $result[] = new Stmt\Nop(['comments' => $node->else->getComments()]);
                }
                array_push($result, ...$node->else->stmts);
                $node->else = null;
            }
        }

        return count($result) > 1 ? $result : null;
    }

    /** @param array<Node> $nodes */
    private function hasDeclaration(array $nodes): bool
    {
        return (
            new NodeFinder()->findFirst(
                $nodes,
                fn (Node $n): bool => (
                    $n instanceof Stmt\Function_
                    || $n instanceof Stmt\Interface_
                    || $n instanceof Stmt\Trait_
                    || $n instanceof Stmt\Enum_
                    || $n instanceof Stmt\Class_
                    && $n->name !== null
                ),
            ) !== null
        );
    }

    /** @param array<Stmt> $statements */
    private function terminates(array $statements): bool
    {
        $statements = array_values(array_filter($statements, fn (Stmt $node) => ! $node instanceof Stmt\Nop));
        $statement = $statements[array_key_last($statements)] ?? null;

        return (
            $statement instanceof Stmt\Return_
            || $statement instanceof Stmt\Break_
            || $statement instanceof Stmt\Continue_
            || $statement instanceof Stmt\Goto_
            || $statement instanceof Stmt\Expression
            && ($statement->expr instanceof Expr\Throw_ || $statement->expr instanceof Expr\Exit_)
        );
    }
}
