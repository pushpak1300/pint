<?php

namespace App\Mago;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

final class ClassRules extends NodeVisitorAbstract
{
    /**
     * The enclosing scopes, including namespace declarations.
     *
     * @var list<Node>
     */
    private array $scopes = [];

    private const EXCLUSIONS = [
        'final',
        'Entity',
        'ORM\\Entity',
        'ORM\\Mapping\\Entity',
        'Mapping\\Entity',
        'Document',
        'ODM\\Document',
    ];

    private const MAGIC = [
        '__construct',
        '__destruct',
        '__call',
        '__callstatic',
        '__get',
        '__set',
        '__isset',
        '__unset',
        '__sleep',
        '__wakeup',
        '__tostring',
        '__invoke',
        '__set_state',
        '__clone',
        '__debuginfo',
    ];

    private const PHPUNIT = [
        'setupbeforeclass',
        'dosetupbeforeclass',
        'teardownafterclass',
        'doteardownafterclass',
        'setup',
        'dosetup',
        'assertpreconditions',
        'assertpostconditions',
        'teardown',
        'doteardown',
    ];

    /**
     * Create a visitor for the enabled Pint-owned class transformations.
     *
     * @param array<string, mixed> $rules
     */
    public function __construct(
        private array $rules,
    ) {}

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Stmt\ClassLike) {
            $this->cleanClass($node);
        }

        $class = null;
        $namespace = '';
        $closure = false;
        $arrow = false;
        foreach ($this->scopes as $scope) {
            if ($scope instanceof Stmt\Namespace_) {
                $namespace = $scope->name?->toString() ?? '';
            } elseif ($scope instanceof Stmt\ClassLike) {
                $class = $scope;
                $closure = $arrow = false;
            } elseif ($scope instanceof Expr\Closure) {
                $closure = true;
            } elseif ($scope instanceof Expr\ArrowFunction) {
                $arrow = true;
            }
        }

        if ($class !== null && ! $closure) {
            if ($node instanceof Stmt\ClassMethod && $this->enabled('self_accessor') && ! $arrow) {
                $node->returnType = $this->selfType($node->returnType, $class, $namespace);
                foreach ($node->params as $parameter) {
                    $parameter->type = $this->selfType($parameter->type, $class, $namespace);
                }
            }
            if (
                (
                    $node instanceof Expr\New_
                    || $node instanceof Expr\Instanceof_
                    || $node instanceof Expr\StaticCall
                    || $node instanceof Expr\StaticPropertyFetch
                    || $node instanceof Expr\ClassConstFetch
                )
                && $node->class instanceof Name
            ) {
                $name = $node->class;
                if (
                    $this->enabled('self_static_accessor')
                    && strtolower($name->toString()) === 'static'
                    && (
                        $class instanceof Stmt\Enum_
                        || $class instanceof Stmt\Class_
                        && (
                            $class->isFinal()
                            || $class->isAnonymous()
                        )
                    )
                ) {
                    $node->class = new Name('self', $name->getAttributes());
                } elseif ($this->enabled('self_accessor') && ! $arrow) {
                    $node->class = $this->selfName($name, $class, $namespace);
                }
            }
        }

        $this->scopes[] = $node;

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        array_pop($this->scopes);

        return null;
    }

    private function enabled(string $rule): bool
    {
        return isset($this->rules[$rule]) && $this->rules[$rule] !== false;
    }

    /**
     * Get configured options without treating a boolean rule as an option array.
     *
     * @return array<string, mixed>
     */
    private function options(string $rule): array
    {
        return is_array($this->rules[$rule] ?? null) ? $this->rules[$rule] : [];
    }

    /**
     * Replace class names in nullable, union and intersection method signature types.
     */
    private function selfType(
        Node\ComplexType|Node\Identifier|Name|null $type,
        Stmt\ClassLike $class,
        string $namespace,
    ): Node\ComplexType|Node\Identifier|Name|null {
        if ($type instanceof Name) {
            return $this->selfName($type, $class, $namespace);
        }
        if ($type instanceof Node\NullableType && $type->type instanceof Name) {
            $type->type = $this->selfName($type->type, $class, $namespace);
        } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            foreach ($type->types as &$part) {
                if ($part instanceof Name) {
                    $part = $this->selfName($part, $class, $namespace);
                } elseif ($part instanceof Node\IntersectionType) {
                    $this->selfType($part, $class, $namespace);
                }
            }
        }

        return $type;
    }

    /**
     * Replace only literal class names, never imported aliases or relative names.
     */
    private function selfName(Name $type, Stmt\ClassLike $class, string $namespace): Name
    {
        if (! ($class instanceof Stmt\Class_ || $class instanceof Stmt\Interface_) || $class->name === null) {
            return $type;
        }
        if (! $type instanceof Name\Relative) {
            $expected = $type instanceof Name\FullyQualified
                ? ($namespace === '' ? '' : $namespace.'\\').$class->name
                : (string) $class->name;
            if (
                (
                    $type instanceof Name\FullyQualified
                    || $type->isUnqualified()
                )
                && strcasecmp($type->toString(), $expected) === 0
            ) {
                return new Name('self', $type->getAttributes());
            }
        }

        return $type;
    }

    /**
     * Apply modifiers before ordering, so groups reflect the resulting visibility.
     */
    private function cleanClass(Stmt\ClassLike $class): void
    {
        if ($class instanceof Stmt\Class_ && ! $class->isAnonymous() && ! $class->isAbstract()) {
            foreach (['final_class', 'final_internal_class'] as $rule) {
                if (! $this->enabled($rule)) {
                    continue;
                }
                $options = $this->options($rule);
                $excluded = $options['exclude'] ?? $options['annotation_exclude'] ?? self::EXCLUSIONS;
                $included = $options['include'] ?? $options['annotation_include'] ?? ['internal'];
                if (
                    ! $this->hasTag($class, $excluded)
                    && (
                        $rule === 'final_class'
                        || $included === []
                        || $this->hasTag($class, $included)
                        || ($options['consider_absent_docblock_as_internal_class'] ?? false)
                        && $class->getDocComment() === null
                    )
                ) {
                    $class->flags |= Stmt\Class_::MODIFIER_FINAL;
                }
            }
        }

        $private =
            $this->enabled('protected_to_private')
            && (
                $class instanceof Stmt\Enum_
                || $class instanceof Stmt\Class_
                && ! $class->isAnonymous()
                && $class->isFinal()
                && $class->extends === null
            );
        foreach ($class->stmts as $member) {
            if ($member instanceof Stmt\TraitUse) {
                $private = false;
            }
        }
        $visibilityRule = $this->enabled('visibility_required') ? 'visibility_required' : 'modifier_keywords';
        $elements = $this->options($visibilityRule)['elements'] ?? ['const', 'method', 'property'];
        foreach ($class->stmts as $member) {
            $kind = match (true) {
                $member instanceof Stmt\ClassMethod => 'method',
                $member instanceof Stmt\ClassConst => 'const',
                $member instanceof Stmt\Property => 'property',
                default => null,
            };
            if ($kind === null) {
                continue;
            }
            if ($this->enabled($visibilityRule) && in_array($kind, $elements, true) && ($member->flags & 7) === 0) {
                $member->flags |= Stmt\Class_::MODIFIER_PUBLIC;
            }
            if ($private && $member->isProtected() && ! ($member instanceof Stmt\ClassConst && $member->isFinal())) {
                $member->flags = ($member->flags & ~Stmt\Class_::MODIFIER_PROTECTED) | Stmt\Class_::MODIFIER_PRIVATE;
            }
            if ($member instanceof Stmt\ClassMethod) {
                foreach ($member->params as $parameter) {
                    if ($private && $parameter->flags & Stmt\Class_::MODIFIER_PROTECTED) {
                        $parameter->flags =
                            ($parameter->flags & ~Stmt\Class_::MODIFIER_PROTECTED) | Stmt\Class_::MODIFIER_PRIVATE;
                    }
                }
                if (
                    $this->enabled('final_public_method_for_abstract_class')
                    && $class instanceof Stmt\Class_
                    && $class->isAbstract()
                    && $member->flags & Stmt\Class_::MODIFIER_PUBLIC
                    && ! $member->isAbstract()
                    && ! in_array(strtolower($member->name->toString()), self::MAGIC, true)
                ) {
                    $member->flags |= Stmt\Class_::MODIFIER_FINAL;
                }
            }
        }
        if ($this->enabled('ordered_interfaces')) {
            if ($class instanceof Stmt\Interface_) {
                $this->sortNames($class->extends, 'ordered_interfaces');
            } elseif ($class instanceof Stmt\Class_ || $class instanceof Stmt\Enum_) {
                $this->sortNames($class->implements, 'ordered_interfaces');
            }
        }
        if ($this->enabled('ordered_traits')) {
            $start = 0;
            while ($start < count($class->stmts)) {
                if (! $class->stmts[$start] instanceof Stmt\TraitUse) {
                    $start++;
                    continue;
                }
                $end = $start;
                while (isset($class->stmts[$end]) && $class->stmts[$end] instanceof Stmt\TraitUse) {
                    $this->sortNames($class->stmts[$end]->traits, 'ordered_traits');
                    $end++;
                }
                $group = [];
                for ($index = $start; $index < $end; $index++) {
                    $statement = $class->stmts[$index];
                    if ($statement instanceof Stmt\TraitUse) {
                        $group[] = $statement;
                    }
                }
                usort($group, fn (Stmt\TraitUse $a, Stmt\TraitUse $b): int => $this->compareNames(
                    $a->traits[0]->toString(),
                    $b->traits[0]->toString(),
                    'ordered_traits',
                ));
                array_splice($class->stmts, $start, $end - $start, $group);
                $start = $end;
            }
        }
        if ($this->enabled('ordered_class_elements')) {
            $options = $this->options('ordered_class_elements');
            $order = $options['order'] ?? [
                'use_trait',
                'case',
                'constant_public',
                'constant_protected',
                'constant_private',
                'property_public',
                'property_protected',
                'property_private',
                'construct',
                'destruct',
                'magic',
                'phpunit',
                'method_public',
                'method_protected',
                'method_private',
            ];
            usort($class->stmts, function (Node $a, Node $b) use ($order, $options): int {
                [$rankA, $nameA] = $this->memberRank($a, $order);
                [$rankB, $nameB] = $this->memberRank($b, $order);

                return (
                    $rankA <=> $rankB
                    ?: (
                        ($options['sort_algorithm'] ?? 'none') === 'alpha'
                            ? $this->compareNames($nameA, $nameB, 'ordered_class_elements')
                            : 0
                    )
                );
            });
        }
    }

    /** @param list<string> $tags */
    private function hasTag(Stmt\ClassLike $class, array $tags): bool
    {
        $names = [];
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $names[] = strtolower(ltrim($attribute->name->toString(), '\\'));
            }
        }
        preg_match_all('/@([\\w\\\\]+)/', $class->getDocComment()?->getText() ?? '', $matches);
        $names = array_merge($names, array_map('strtolower', $matches[1]));

        foreach ($tags as $tag) {
            if (in_array(strtolower(ltrim($tag, '@\\')), $names, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Name> $names */
    private function sortNames(array &$names, string $rule): void
    {
        usort($names, fn (Name $a, Name $b): int => $this->compareNames($a->toCodeString(), $b->toCodeString(), $rule));
    }

    private function compareNames(string $a, string $b, string $rule): int
    {
        $options = $this->options($rule);
        if ($rule === 'ordered_interfaces') {
            $a = str_replace('\\', ' ', $a);
            $b = str_replace('\\', ' ', $b);
        }
        $score = ($options['order'] ?? 'alpha') === 'length'
            ? strlen($a) <=> strlen($b)
            : ($options['case_sensitive'] ?? false ? strcmp($a, $b) : strcasecmp($a, $b));

        return ($options['direction'] ?? 'ascend') === 'descend' ? -$score : $score;
    }

    /**
     * Resolve exact groups first, then the upstream subtype's ordered parents.
     *
     * @param list<string> $order
     * @return array{int, string}
     */
    private function memberRank(Node $member, array $order): array
    {
        $visibility = method_exists($member, 'isPrivate') && $member->isPrivate()
            ? 'private'
            : (method_exists($member, 'isProtected') && $member->isProtected() ? 'protected' : 'public');
        $type = match (true) {
            $member instanceof Stmt\TraitUse => 'use_trait',
            $member instanceof Stmt\EnumCase => 'case',
            $member instanceof Stmt\ClassConst => 'constant',
            $member instanceof Stmt\Property => 'property',
            $member instanceof Stmt\ClassMethod => 'method',
            default => '',
        };
        $name = match (true) {
            $member instanceof Stmt\ClassMethod, $member instanceof Stmt\EnumCase => $member->name->toString(),
            $member instanceof Stmt\ClassConst => $member->consts[0]->name->toString(),
            $member instanceof Stmt\Property => $member->props[0]->name->toString(),
            $member instanceof Stmt\TraitUse => $member->traits[0]->toString(),
            default => '',
        };
        $lower = strtolower($name);
        $groups = [];
        if ($type === 'method') {
            if (! in_array($lower, ['__construct', '__destruct'], true)) {
                $groups[] = 'method:'.$lower;
            }
            $special = match (true) {
                $lower === '__construct' => 'construct',
                $lower === '__destruct' => 'destruct',
                str_starts_with($lower, '__') => 'magic',
                in_array($lower, self::PHPUNIT, true) => 'phpunit',
                default => null,
            };
            if ($special !== null) {
                $groups[] = $special;
            }
        }
        $suffixes = [];
        if (method_exists($member, 'isAbstract') && $member->isAbstract()) {
            $suffixes[] = 'abstract';
        }
        if (method_exists($member, 'isStatic') && $member->isStatic()) {
            $suffixes[] = 'static';
        }
        if ($member instanceof Stmt\Property && $member->isReadonly()) {
            $suffixes[] = 'readonly';
        }
        foreach ($groups as $group) {
            $position = array_search($group, array_map('strtolower', $order), true);
            if ($position !== false) {
                return [
                    ($position * 10) + ($group === 'phpunit' ? array_search($lower, self::PHPUNIT, true) + 1 : 0),
                    $name,
                ];
            }
        }

        $group = in_array($type, ['constant', 'property', 'method'], true)
            ? $type.'_'.$visibility.($suffixes === [] ? '' : '_'.implode('_', $suffixes))
            : $type;

        return [($this->groupPosition($group, $order) ?? count($order)) * 10, $name];
    }

    /** @param list<string> $order */
    private function groupPosition(string $group, array $order): ?int
    {
        $position = array_search($group, $order, true);
        if ($position !== false) {
            return $position;
        }
        $parts = explode('_', $group);
        $type = $parts[0];
        $parents = [];
        if ($group === 'case') {
            $parents = ['public'];
        } elseif (count($parts) >= 3) {
            foreach (array_slice($parts, 2) as $suffix) {
                $parents[] = $type.'_'.$suffix;
            }
            $parents[] = $type.'_'.$parts[1];
        } elseif (count($parts) === 2 && in_array($type, ['constant', 'property', 'method'], true)) {
            $parents[] = $type;
            if (in_array($parts[1], ['public', 'protected', 'private'], true)) {
                $parents[] = $parts[1];
            }
        }
        foreach ($parents as $parent) {
            $position = $this->groupPosition($parent, $order);
            if ($position !== null) {
                return $position;
            }
        }

        return null;
    }
}
