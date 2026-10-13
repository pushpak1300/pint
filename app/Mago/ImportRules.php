<?php

namespace App\Mago;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class ImportRules extends NodeVisitorAbstract
{
    /**
     * @param  array<string, mixed>  $rules
     */
    public function __construct(
        private array $rules,
    ) {}

    /**
     * Process complete scopes before rewriting: later uses can shadow earlier references.
     *
     * @param  array<Stmt>  $nodes
     * @return array<Stmt>
     */
    public function beforeTraverse(array $nodes): array
    {
        $namespaces = array_filter($nodes, fn (Node $node) => $node instanceof Stmt\Namespace_);
        $monolithic = count($namespaces) === 1;
        foreach ($nodes as $node) {
            if (
                $node instanceof Stmt\InlineHTML
                || ! $node instanceof Stmt\Namespace_
                && ! $node instanceof Stmt\Declare_
            ) {
                $monolithic = false;
            }
        }
        $this->walk($nodes, function (Node $node) use (&$monolithic): void {
            if ($node instanceof Stmt\InlineHTML) {
                $monolithic = false;
            }
        });

        if ($namespaces === []) {
            return $this->scope($nodes, '', false);
        }

        foreach ($namespaces as $namespace) {
            $namespace->stmts = $this->scope(
                $namespace->stmts,
                $namespace->name?->toString() ?? '',
                $monolithic && $namespace->name !== null,
            );
        }

        return $nodes;
    }

    /**
     * @param  array<Stmt>  $nodes
     * @return array<Stmt>
     */
    private function scope(array $nodes, string $namespace, bool $globalAllowed): array
    {
        $global = $this->rules['global_namespace_import'] ?? false;
        $qualified = $this->rules['fully_qualified_strict_types'] ?? false;
        $options = is_array($global) ? $global : [];
        $modes = [
            Stmt\Use_::TYPE_NORMAL => $options['import_classes'] ?? true,
            Stmt\Use_::TYPE_FUNCTION => $options['import_functions'] ?? null,
            Stmt\Use_::TYPE_CONSTANT => $options['import_constants'] ?? null,
        ];
        // Null is an explicit instruction to ignore a category.
        foreach (['import_classes', 'import_functions', 'import_constants'] as $index => $key) {
            if (array_key_exists($key, $options)) {
                $modes[$index + 1] = $options[$key];
            }
        }
        $fqOptions = is_array($qualified) ? $qualified : [];
        $imports = $blocked = $candidates = $added = [];
        $templates = [];

        foreach ($nodes as $node) {
            if (! $node instanceof Stmt\Use_ && ! $node instanceof Stmt\GroupUse) {
                continue;
            }
            foreach ($node->uses as $use) {
                $kind = $use->type ?: $node->type;
                $target = ($node instanceof Stmt\GroupUse ? $node->prefix->toString().'\\' : '').$use->name->toString();
                $alias = $use->getAlias()->toString();
                $imports[$kind][$this->key($alias, $kind)] = [$target, $alias, $use->alias !== null];
            }
        }

        $this->walk($nodes, function (Node $node) use (&$blocked, &$templates): void {
            if ($node instanceof Stmt\ClassLike && $node->name !== null) {
                $blocked[Stmt\Use_::TYPE_NORMAL][strtolower($node->name->toString())] = true;
            } elseif ($node instanceof Stmt\Function_) {
                $blocked[Stmt\Use_::TYPE_FUNCTION][strtolower($node->name->toString())] = true;
            } elseif ($node instanceof Stmt\Const_) {
                foreach ($node->consts as $const) {
                    $blocked[Stmt\Use_::TYPE_CONSTANT][$const->name->toString()] = true;
                }
            }
            foreach ($node->getComments() as $comment) {
                if ($comment instanceof Doc) {
                    preg_match_all(
                        '/@(?:phpstan-|psalm-)?template(?:-covariant|-contravariant)?\s+([A-Za-z_][A-Za-z0-9_]*)/',
                        $comment->getText(),
                        $matches,
                    );
                    foreach ($matches[1] as $template) {
                        $templates[strtolower($template)] = true;
                    }
                }
            }
        });
        // An unsupported PHPDoc expression must not acquire a new binding through an import.
        $this->walk($nodes, function (Node $node) use (&$blocked): void {
            foreach ($node->getComments() as $comment) {
                if ($comment instanceof Doc) {
                    preg_match_all(
                        '/(?:\/\*\*|\n[\t ]*\*)[\t ]*@([a-z-]+)[\t ]+([^\r\n]*)/',
                        $comment->getText(),
                        $annotations,
                        PREG_SET_ORDER,
                    );
                    foreach ($annotations as $annotation) {
                        if (
                            ! preg_match('/[<{(]|\s[|&]\s/', $annotation[2])
                            && ! preg_match('/(?:extends|implements|mixin|method|template|type)/', $annotation[1])
                        ) {
                            continue;
                        }
                        // ponytail: reserve uncertain annotation names; a PHPDoc parser could narrow this.
                        preg_match_all('/(?<![\\\\A-Za-z0-9_])[A-Za-z_][A-Za-z0-9_]*/', $annotation[2], $matches);
                        foreach ($matches[0] as $word) {
                            $blocked[Stmt\Use_::TYPE_NORMAL][strtolower($word)] = true;
                        }
                    }
                }
            }
        });
        foreach ($templates as $template => $_) {
            $blocked[Stmt\Use_::TYPE_NORMAL][$template] = true;
        }

        $collect = function (Name $name, int $kind) use (&$blocked, &$candidates, $imports): Name {
            $first = $this->key($name->getFirst(), $kind);
            if (! $name->isFullyQualified() && ! $name->isRelative() && ! isset($imports[$kind][$first])) {
                $blocked[$kind][$first] = true;
            }
            // Qualified functions and constants resolve their prefix through normal imports.
            if ($name->isQualified() && ! isset($imports[Stmt\Use_::TYPE_NORMAL][strtolower($name->getFirst())])) {
                $blocked[Stmt\Use_::TYPE_NORMAL][strtolower($name->getFirst())] = true;
            }
            if ($name->isFullyQualified()) {
                $candidates[$kind][$this->key($name->getLast(), $kind)][$this->key($name->toString(), $kind)] = true;
            }

            return $name;
        };
        $this->names($nodes, $collect);
        $this->docs($nodes, $collect, $templates, $fqOptions);

        $rewrite = function (Name $name, int $kind) use (
            $namespace,
            $global,
            $globalAllowed,
            $modes,
            $qualified,
            $fqOptions,
            &$imports,
            $blocked,
            $candidates,
            &$added,
        ): Name {
            $text = $name->toString();
            $key = $this->key($text, $kind);
            $replacement = null;
            $attributes = $name->getAttributes();
            // The format-preserving printer requires origNode to have the same concrete class.
            unset($attributes['origNode']);
            $globalMode = $globalAllowed && $global !== false ? $modes[$kind] : null;
            if ($globalMode === false && $name->isUnqualified() && isset($imports[$kind][$key])) {
                [$target, , $aliased] = $imports[$kind][$key];
                if (! $aliased && ! str_contains($target, '\\')) {
                    return new Name\FullyQualified($target, $attributes);
                }
            }
            if ($globalMode === false && $name->isFullyQualified() && ! str_contains($text, '\\')) {
                return $name;
            }
            if ($globalMode === true && $name->isFullyQualified() && ! str_contains($text, '\\')) {
                foreach ($imports[$kind] ?? [] as [$target, $alias]) {
                    if ($this->key($target, $kind) === $key) {
                        $replacement = $alias;
                        break;
                    }
                }
                if ($replacement === null && ! isset($blocked[$kind][$key]) && ! isset($imports[$kind][$key])) {
                    $replacement = $text;
                    $imports[$kind][$key] = [$text, $text, false];
                    $added[$kind][$key] = $text;
                }
            }
            if ($replacement === null && $kind === Stmt\Use_::TYPE_NORMAL && $qualified !== false) {
                if ($name->isUnqualified() && in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
                    return $name;
                }
                $first = strtolower($name->getFirst());
                $target = $name->isFullyQualified()
                    ? $text
                    : (
                        $name->isRelative() || ! isset($imports[$kind][$first])
                            ? ($namespace === '' ? '' : $namespace.'\\').$text
                            : $imports[$kind][$first][0].substr($text, strlen($name->getFirst()))
                    );
                foreach ($imports[$kind] ?? [] as [$import, $alias]) {
                    if (
                        strcasecmp($target, $import) === 0
                        || str_starts_with(strtolower($target), strtolower($import).'\\')
                    ) {
                        $short = $alias.substr($target, strlen($import));
                        if ($replacement === null || strlen($short) < strlen($replacement)) {
                            $replacement = $short;
                        }
                    }
                }
                if ($namespace !== '' && str_starts_with($target, $namespace.'\\')) {
                    $short = substr($target, strlen($namespace) + 1);
                    if (
                        ! isset($imports[$kind][strtolower(explode('\\', $short)[0])])
                        && (
                            $replacement === null
                            || strlen($short) < strlen($replacement)
                        )
                    ) {
                        $replacement = $short;
                    }
                }
                if ($replacement === null && ($fqOptions['import_symbols'] ?? false) && $name->isFullyQualified()) {
                    $last = strtolower($name->getLast());
                    if (
                        ! isset($blocked[$kind][$last])
                        && ! isset($imports[$kind][$last])
                        && count($candidates[$kind][$last] ?? []) === 1
                        && (
                            $namespace !== ''
                            || str_contains($target, '\\')
                        )
                    ) {
                        $replacement = $name->getLast();
                        $imports[$kind][$last] = [$target, $replacement, false];
                        $added[$kind][$last] = $target;
                    }
                }
                if ($replacement === null) {
                    $replacement =
                        (
                            $namespace !== ''
                            || ($fqOptions['leading_backslash_in_global_namespace'] ?? false)
                            || isset($imports[$kind][strtolower(explode('\\', $target)[0])])
                                ? '\\'
                                : ''
                        ).$target;
                }
            }
            if (
                $replacement === null
                || $replacement
                    === ($name->isFullyQualified() ? '\\' : ($name->isRelative() ? 'namespace\\' : '')).$text
            ) {
                return $name;
            }

            return str_starts_with($replacement, '\\')
                ? new Name\FullyQualified(substr($replacement, 1), $attributes)
                : new Name($replacement, $attributes);
        };
        $this->names($nodes, $rewrite);
        $this->docs($nodes, $rewrite, $templates, $fqOptions);
        $uses = [];
        foreach ($added as $kind => $targets) {
            foreach ($targets as $target) {
                $type = match ($kind) {
                    Stmt\Use_::TYPE_FUNCTION => Stmt\Use_::TYPE_FUNCTION,
                    Stmt\Use_::TYPE_CONSTANT => Stmt\Use_::TYPE_CONSTANT,
                    default => Stmt\Use_::TYPE_NORMAL,
                };
                $uses[] = new Stmt\Use_([new Node\UseItem(new Name($target))], $type);
            }
        }
        $position = 0;
        foreach ($nodes as $index => $node) {
            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse || $node instanceof Stmt\Declare_) {
                $position = $index + 1;
            }
        }
        array_splice($nodes, $position, 0, $uses);

        return $nodes;
    }

    private function key(string $name, int $kind): string
    {
        return $kind === Stmt\Use_::TYPE_CONSTANT ? $name : strtolower($name);
    }

    /**
     * @param  array<Node>  $nodes
     * @param  callable(Node): void  $callback
     */
    private function walk(array $nodes, callable $callback): void
    {
        foreach ($nodes as $node) {
            $callback($node);
            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                continue;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $value = $node->$field;
                if ($value instanceof Node) {
                    $this->walk([$value], $callback);
                } elseif (is_array($value)) {
                    $this->walk(array_filter($value, fn ($item) => $item instanceof Node), $callback);
                }
            }
        }
    }

    /**
     * @param  array<Node>  $nodes
     * @param  callable(Name, int): Name  $callback
     */
    private function names(array $nodes, callable $callback): void
    {
        $this->walk($nodes, function (Node $node) use ($callback): void {
            if ($node instanceof Stmt\Use_ || $node instanceof Stmt\GroupUse) {
                return;
            }
            foreach ($node->getSubNodeNames() as $field) {
                $kind =
                    $node instanceof Expr\FuncCall && $field === 'name'
                        ? Stmt\Use_::TYPE_FUNCTION
                        : ($node instanceof Expr\ConstFetch ? Stmt\Use_::TYPE_CONSTANT : Stmt\Use_::TYPE_NORMAL);
                $value = $node->$field;
                if ($value instanceof Name) {
                    $node->$field = $callback($value, $kind);
                } elseif (is_array($value)) {
                    foreach ($value as $index => $item) {
                        if ($item instanceof Name) {
                            $node->{$field}[$index] = $callback($item, $kind);
                        }
                    }
                }
            }
        });
    }

    /**
     * Only a validated type field is rewritten, never prose, shape keys or template names.
     * Complex generic/callable/shape expressions are deliberately left intact.
     *
     * @param  array<Node>  $nodes
     * @param  callable(Name, int): Name  $callback
     * @param  array<string, true>  $templates
     * @param  array<string, mixed>  $options
     */
    private function docs(array $nodes, callable $callback, array $templates, array $options): void
    {
        $tags = $options['phpdoc_tags'] ?? [
            'param',
            'return',
            'var',
            'throws',
            'see',
            'property',
            'property-read',
            'property-write',
        ];
        $this->walk($nodes, function (Node $node) use ($callback, $templates, $tags): void {
            $comments = $node->getComments();
            foreach ($comments as $index => $comment) {
                if (! $comment instanceof Doc) {
                    continue;
                }
                $text = preg_replace_callback(
                    '/((?:\/\*\*|\n[\t ]*\*)[\t ]*@([a-z-]+)[\t ]+)([^\s*]+)([^\r\n]*)/',
                    function (array $match) use ($callback, $templates, $tags): string {
                        $tag = preg_replace('/^(phpstan-|psalm-)/', '', $match[2]);
                        if (! in_array($match[2], $tags, true) && ! in_array($tag, $tags, true)) {
                            return $match[0];
                        }
                        $type = $match[3];
                        if (preg_match('/^[\t ]*[|&]/', $match[4])) {
                            return $match[0];
                        }
                        $member = '';
                        if ($tag === 'see' && preg_match('/^(.*)(::[A-Za-z_][A-Za-z0-9_]*(?:\(\))?)$/D', $type, $see)) {
                            $type = $see[1];
                            $member = $see[2];
                        }
                        if (! preg_match(
                            '/^\??[\\\\A-Za-z_][\\\\A-Za-z_0-9]*(?:\[\])*(?:[|&]\??[\\\\A-Za-z_][\\\\A-Za-z_0-9]*(?:\[\])*)*$/D',
                            $type,
                        )) {
                            return $match[0];
                        }
                        $type = preg_replace_callback(
                            '/[\\\\A-Za-z_][\\\\A-Za-z_0-9]*/',
                            function (array $part) use ($callback, $templates): string {
                                $text = $part[0];
                                if (
                                    isset($templates[strtolower($text)])
                                    || in_array(
                                        strtolower($text),
                                        [
                                            'int',
                                            'string',
                                            'bool',
                                            'float',
                                            'array',
                                            'object',
                                            'mixed',
                                            'void',
                                            'never',
                                            'null',
                                            'false',
                                            'true',
                                            'callable',
                                            'iterable',
                                            'resource',
                                            'self',
                                            'static',
                                            'parent',
                                        ],
                                        true,
                                    )
                                ) {
                                    return $text;
                                }
                                $name = str_starts_with($text, '\\')
                                    ? new Name\FullyQualified(substr($text, 1))
                                    : (
                                        str_starts_with($text, 'namespace\\')
                                            ? new Name\Relative(substr($text, 10))
                                            : new Name($text)
                                    );
                                $new = $callback($name, Stmt\Use_::TYPE_NORMAL);

                                return (
                                    ($new->isFullyQualified() ? '\\' : ($new->isRelative() ? 'namespace\\' : ''))
                                    .$new->toString()
                                );
                            },
                            $type,
                        );

                        return $match[1].$type.$member.$match[4];
                    },
                    $comment->getText(),
                );
                if ($text !== $comment->getText()) {
                    $comments[$index] = new Doc(
                        $text ?? $comment->getText(),
                        $comment->getStartLine(),
                        $comment->getStartFilePos(),
                        $comment->getStartTokenPos(),
                        $comment->getEndLine(),
                        $comment->getEndFilePos(),
                        $comment->getEndTokenPos(),
                    );
                }
            }
            $node->setAttribute('comments', $comments);
        });
    }
}
