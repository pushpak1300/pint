<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;
use Mago\Sdk\Extension;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;
use Mago\Sdk\Worker;

final class Laravel implements CodebaseScanHook, Plugin
{
    /** @var array<string, array<int, array<string, string>>> */
    public array $casts = [];

    private ConfigTypes $configs;

    public static function work(): void
    {
        new Worker(new Extension(
            identifier: 'pint/laravel',
            name: 'Pint Laravel analysis',
            version: '1',
            analyzerPlugins: [new self],
        ))->run();
    }

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition(
            'pint/laravel',
            'Laravel',
            'Infer Eloquent properties, local scopes, and static configuration types.',
        );
    }

    public function register(PluginRegistry $registry): void
    {
        $this->configs = new ConfigTypes;
        $registry->registerCodebaseScanHook($this);
        $registry->registerPropertyTypeProvider(new ModelProperties($this));
        $registry->registerMethodReturnTypeProvider(new ScopeMethods);
        $registry->registerFunctionReturnTypeProvider($this->configs);
        $registry->registerMethodReturnTypeProvider(new ConfigMethods($this->configs));

        // Call providers use only invocation types and the frozen codebase/config scan.
        $registry->enableProviderMemoization();
    }

    public function getTargets(): array
    {
        return ['**/*.php'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        $this->configs->scan($context);
        if ($context->firstBatch) {
            $this->casts = [];
        }

        foreach ($context->files as $file) {
            foreach ($file->getNodes(NodeKind::Method) as $method) {
                $name = $file->getFirstDescendant($method, NodeKind::LocalIdentifier);
                if ($name === null || strcasecmp($file->getText($name), 'casts') !== 0) {
                    continue;
                }

                $body = $file->getFirstDescendant($method, NodeKind::Block);
                $statements = $body === null ? [] : $file->getChildren($body);
                if (count($statements) !== 1) {
                    continue;
                }

                $statement = $file->getChildren($statements[0])[0] ?? null;
                if ($statement?->kind !== NodeKind::Return) {
                    continue;
                }

                $expression = $file->getFirstDescendant($statement, NodeKind::Expression);
                $array = $expression === null ? null : $file->getChildren($expression)[0] ?? null;
                if ($array === null || ! in_array($array->kind, [NodeKind::Array, NodeKind::LegacyArray], true)) {
                    continue;
                }

                $casts = $this->literalCasts($file, $array);
                if ($casts !== null) {
                    $this->casts[$file->path][$method->span->start] = $casts;
                }
            }
        }
    }

    /** @return array<string, string>|null */
    private function literalCasts(SourceFile $file, Node $array): ?array
    {
        $casts = [];
        foreach ($file->getChildren($array) as $element) {
            $pair = $file->getChildren($element)[0] ?? null;
            if ($pair?->kind !== NodeKind::KeyValueArrayElement) {
                return null;
            }

            $expressions = $file->getChildren($pair);
            $values = [];
            foreach ($expressions as $expression) {
                $literal = $file->getFirstDescendant($expression, NodeKind::LiteralString);
                if (
                    $literal !== null
                    && $literal->span->start === $expression->span->start
                    && $literal->span->end === $expression->span->end
                ) {
                    $values[] = $file->getLiteralString($literal);

                    continue;
                }

                $constant = $file->getFirstDescendant($expression, NodeKind::ClassConstantAccess);
                $children = $constant === null ? [] : $file->getChildren($constant);
                if (
                    $constant === null
                    || $constant->span->start !== $expression->span->start
                    || $constant->span->end !== $expression->span->end
                    || count($children) !== 2
                    || strtolower($file->getText($children[1])) !== 'class'
                ) {
                    return null;
                }
                $name = $file->getResolvedName($children[0])?->name;
                if ($name === null || in_array(strtolower($name), ['self', 'static', 'parent'], true)) {
                    return null;
                }
                $values[] = ltrim($name, '\\');
            }
            if (count($values) !== 2 || $values[0] === null || $values[1] === null) {
                return null;
            }
            $casts[$values[0]] = $values[1];
        }

        return $casts;
    }
}
