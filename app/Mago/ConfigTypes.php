<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\ArrayKey;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringType;
use Mago\Sdk\Analyzer\Type\ClassLikeStringVariant;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

final class ConfigTypes implements FunctionReturnTypeProvider
{
    // Only ordinary source facts survive scan/provider requests, never SDK Type handles.
    /** @var array<string, array<string, mixed>> */
    private array $configs = [];

    public function getTargets(): array
    {
        return [FunctionTarget::exact('config')];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->configs = [];
        }

        $root = str_replace('\\', '/', getcwd()).'/';
        foreach ($context->files as $file) {
            $path = str_replace('\\', '/', $file->path);
            if (str_starts_with($path, $root)) {
                $path = substr($path, strlen($root));
            }
            if (! preg_match('~^config/([^/]+)\.php$~D', $path, $match)) {
                continue;
            }

            $fact = ['kind' => 'unknown'];
            $returns = [];
            $safe = true;
            foreach ($file->getNodes(NodeKind::Statement) as $statement) {
                if ($file->getParent($statement)?->kind !== NodeKind::Program) {
                    continue;
                }
                $node = $file->getChildren($statement)[0] ?? null;
                if ($node?->kind === NodeKind::Return) {
                    $returns[] = $node;
                } elseif (
                    $node?->kind === NodeKind::Declare
                    && $file->getFirstDescendant($node, NodeKind::Statement) !== null
                ) {
                    $safe = false;
                } elseif (! in_array($node?->kind, [NodeKind::OpeningTag, NodeKind::Use, NodeKind::Declare], true)) {
                    $safe = false;
                }
            }
            if ($safe && count($returns) === 1) {
                $expression = $file->getFirstDescendant($returns[0], NodeKind::Expression);
                if ($expression !== null) {
                    $fact = $this->literal($file, $expression);
                }
            }
            $this->configs[$match[1]] = $fact;
        }
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $argument = $context->invocation->getArgument(0, 'key');
        $key = $argument?->type?->getLiteralString();
        if ($key === null || $argument->unpacked || $argument->placeholder) {
            return null;
        }

        if (array_key_exists($key, $this->configs)) {
            return $this->type($this->configs[$key]);
        }
        // Framework defaults and service providers can supply files absent from config/.
        if (! array_key_exists(explode('.', $key, 2)[0], $this->configs)) {
            return null;
        }
        $fact = ['kind' => 'array', 'items' => $this->configs];
        $missing = false;
        foreach (explode('.', $key) as $part) {
            if ($fact['kind'] === 'unknown') {
                return null;
            }
            if ($fact['kind'] !== 'array' || ! array_key_exists($part, $fact['items'])) {
                $missing = true;
                break;
            }
            $fact = $fact['items'][$part];
        }
        if (! $missing) {
            return $this->type($fact);
        }

        $default = $context->invocation->getArgument(1, 'default');
        if ($default === null) {
            return Type::null();
        }
        if ($default->unpacked || $default->placeholder || $default->type === null) {
            return null;
        }
        foreach ($default->type->atomicTypes as $atomic) {
            if ($atomic instanceof CallableType && $atomic->signature?->closure) {
                return count($default->type->atomicTypes) === 1 ? $atomic->signature->returnType : null;
            }
            if ($atomic instanceof NamedObjectType && strcasecmp($atomic->name, 'Closure') === 0) {
                return null;
            }
        }

        return $default->type;
    }

    /** @return array<string, mixed> */
    private function literal(SourceFile $file, Node $node): array
    {
        while (in_array(
            $node->kind,
            [NodeKind::Expression, NodeKind::Literal, NodeKind::Parenthesized, NodeKind::Access],
            true,
        )) {
            $children = $file->getChildren($node);
            if (count($children) !== 1) {
                return ['kind' => 'unknown'];
            }
            $node = $children[0];
        }
        if ($node->kind === NodeKind::LiteralString) {
            $value = $file->getLiteralString($node);

            return $value === null ? ['kind' => 'unknown'] : ['kind' => 'string', 'value' => $value];
        }
        if ($node->kind === NodeKind::LiteralInteger) {
            $text = str_replace('_', '', $file->getText($node));
            $value = intval(preg_replace('/^0o/i', '0', $text), 0);
            if ($value === PHP_INT_MAX && $text !== (string) PHP_INT_MAX) {
                return ['kind' => 'unknown'];
            }

            return ['kind' => 'int', 'value' => $value];
        }
        if ($node->kind === NodeKind::UnaryPrefix) {
            $children = $file->getChildren($node);
            $operator = $children[0] ?? null;
            $value = isset($children[1]) ? $this->literal($file, $children[1]) : ['kind' => 'unknown'];
            if (
                $value['kind'] === 'int'
                && $operator !== null
                && in_array($file->getText($operator), ['-', '+'], true)
            ) {
                $value['value'] *= $file->getText($operator) === '-' ? -1 : 1;

                return $value;
            }
        }
        if (in_array($node->kind, [NodeKind::Keyword, NodeKind::ConstantAccess], true)) {
            $text = strtolower($file->getText($node));
            if (in_array($text, ['true', 'false', 'null'], true)) {
                return ['kind' => $text];
            }
        }
        if ($node->kind === NodeKind::ClassConstantAccess) {
            $children = $file->getChildren($node);
            $class = $children[0] ?? null;
            $selector = $children[1] ?? null;
            if ($class !== null && $selector !== null && strtolower($file->getText($selector)) === 'class') {
                $name = $file->getResolvedName($class)?->name;
                if ($name !== null && ! in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                    return ['kind' => 'class', 'value' => ltrim($name, '\\')];
                }
            }
        }
        if (in_array($node->kind, [NodeKind::Array, NodeKind::LegacyArray], true)) {
            $items = [];
            foreach ($file->getChildren($node) as $element) {
                $pair = $file->getChildren($element)[0] ?? null;
                $children = $pair === null ? [] : $file->getChildren($pair);
                if ($pair?->kind === NodeKind::KeyValueArrayElement && count($children) === 2) {
                    $key = $this->literal($file, $children[0]);
                    $key = match ($key['kind']) {
                        'string', 'int', 'class' => $key['value'],
                        'true' => 1,
                        'false' => 0,
                        'null' => '',
                        default => null,
                    };
                    if ($key === null) {
                        return ['kind' => 'unknown'];
                    }
                    $items[$key] = $this->literal($file, $children[1]);
                } elseif ($pair?->kind === NodeKind::ValueArrayElement && count($children) === 1) {
                    if (array_key_exists(PHP_INT_MAX, $items)) {
                        return ['kind' => 'unknown'];
                    }
                    $items[] = $this->literal($file, $children[0]);
                } else {
                    // Dynamic keys/spreads can overwrite any sibling; unknown values cannot.
                    return ['kind' => 'unknown'];
                }
            }

            return ['kind' => 'array', 'items' => $items];
        }

        return ['kind' => 'unknown'];
    }

    /** @param array<string, mixed> $fact */
    private function type(array $fact): ?Type
    {
        if ($fact['kind'] === 'array') {
            $items = [];
            foreach ($fact['items'] as $key => $value) {
                $items[] = new ArrayItem(
                    new ArrayKey(is_int($key) ? ArrayKeyKind::Integer : ArrayKeyKind::String, $key),
                    false,
                    $this->type($value) ?? Type::mixed(),
                );
            }

            return Type::fromAtomic(new KeyedArrayType($items, null, null, $items !== []));
        }

        return match ($fact['kind']) {
            'string' => Type::literalString($fact['value']),
            'int' => Type::literalInt($fact['value']),
            'true' => Type::true(),
            'false' => Type::false(),
            'null' => Type::null(),
            'class' => Type::fromAtomic(
                new ScalarType(
                    ScalarTypeKind::ClassLikeString,
                    new ClassLikeStringType(ClassLikeStringVariant::Literal, literal: $fact['value']),
                ),
            ),
            default => null,
        };
    }
}
