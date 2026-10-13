<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\FunctionReturnTypeProvider;
use Mago\Sdk\Analyzer\FunctionTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Syntax\CallExpression;
use Mago\Sdk\Syntax\NodeKind;

final class FrameworkHelpers implements FunctionReturnTypeProvider
{
    private const BINDINGS = [
        'app' => ['app', 'Illuminate\\Contracts\\Foundation\\Application', 'Illuminate\\Foundation\\Application'],
        'auth' => ['auth', 'Illuminate\\Contracts\\Auth\\Factory', 'Illuminate\\Auth\\AuthManager'],
        'view' => ['view', 'Illuminate\\Contracts\\View\\Factory', 'Illuminate\\View\\Factory'],
    ];

    /** @var array<string, bool> */
    private array $overridden = [];

    public function getTargets(): array
    {
        return array_map(FunctionTarget::exact(...), array_keys(self::BINDINGS));
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->overridden = [];
        }
        foreach ($context->files as $file) {
            if (str_contains('/'.ltrim(str_replace('\\', '/', $file->path), '/'), '/vendor/')) {
                continue;
            }
            foreach ($file->getNodes(NodeKind::PropertyItem) as $property) {
                $variable = $file->getFirstDescendant($property, NodeKind::DirectVariable);
                if ($variable !== null && in_array($file->getText($variable), ['$bindings', '$singletons'], true)) {
                    $this->override(null);
                }
            }
            foreach ([NodeKind::MethodCall, NodeKind::StaticMethodCall] as $kind) {
                foreach ($file->getNodes($kind) as $node) {
                    $call = CallExpression::fromNode($file, $node);
                    $name = strtolower($call->getName($file) ?? '');
                    if (in_array($name, ['setinstance', 'withbindings', 'withsingletons'], true)) {
                        $this->override(null);
                    }
                    if (
                        $call->arguments === []
                        || ! in_array(
                            $name,
                            ['bind', 'bindif', 'singleton', 'singletonif', 'scoped', 'scopedif', 'instance'],
                            true,
                        )
                    ) {
                        continue;
                    }
                    $argument = null;
                    foreach ($call->arguments as $candidate) {
                        if ($candidate->name === 'abstract' || $candidate->index === 0 && $candidate->name === null) {
                            $argument = $candidate;
                            break;
                        }
                    }
                    $value = $argument?->value;
                    while (
                        $value !== null
                        && in_array(
                            $value->kind,
                            [NodeKind::Expression, NodeKind::Literal, NodeKind::Parenthesized, NodeKind::Access],
                            true,
                        )
                    ) {
                        $children = $file->getChildren($value);
                        $value = count($children) === 1 ? $children[0] : null;
                    }
                    $binding = $value?->kind === NodeKind::LiteralString ? $file->getLiteralString($value) : null;
                    if ($value?->kind === NodeKind::ClassConstantAccess) {
                        $children = $file->getChildren($value);
                        if (count($children) === 2 && strtolower($file->getText($children[1])) === 'class') {
                            $binding = $file->getResolvedName($children[0])?->name;
                        }
                    }
                    $this->override($argument?->unpacked ? null : $binding);
                }
            }
        }
    }

    private function override(?string $binding): void
    {
        foreach (self::BINDINGS as $helper => $bindings) {
            if (
                $binding === null
                || in_array(strtolower(ltrim($binding, '\\')), array_map(strtolower(...), $bindings), true)
            ) {
                $this->overridden[$helper] = true;
            }
        }
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $helper = strtolower($context->invocation->name);
        $file = $context->codebase->getFunction($helper)?->location->file;
        if (
            isset($this->overridden[$helper])
            || $file === null
            || ! str_ends_with(str_replace('\\', '/', $file), '/src/Illuminate/Foundation/helpers.php')
        ) {
            return null;
        }
        $argument = $context->invocation->getArgument(
            0,
            $helper === 'app' ? 'abstract' : ($helper === 'auth' ? 'guard' : 'view'),
        );
        if ($argument !== null && ($argument->unpacked || $argument->placeholder || $argument->type === null)) {
            return null;
        }
        if ($helper === 'view') {
            $class = $argument === null ? 'Illuminate\\View\\Factory' : 'Illuminate\\View\\View';
            if ($argument !== null && ! $context->types->isContainedBy($argument->type, Type::string())) {
                return null;
            }
        } else {
            if ($argument !== null && ! $context->types->isContainedBy($argument->type, Type::null())) {
                return null;
            }
            $class = $helper === 'app' ? 'Illuminate\\Foundation\\Application' : 'Illuminate\\Auth\\AuthManager';
        }

        return $context->codebase->classExists($class) ? Type::namedObject($class) : null;
    }
}
