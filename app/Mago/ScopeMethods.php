<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\CallableSignatureProvider;
use Mago\Sdk\Analyzer\CallableSignatureProviderContext;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\EffectiveCallableSignature;
use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\Metadata\FunctionLikeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\Visibility;

final class ScopeMethods implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    private const RELATION = 'Illuminate\\Database\\Eloquent\\Relations\\Relation';

    public function getTargets(): array
    {
        return array_map(MethodTarget::allMethods(...), [self::MODEL, self::BUILDER, self::RELATION]);
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$scope, , $model] = $resolved;
        $parameters = [];
        foreach (array_slice($scope->parameters, 1) as $parameter) {
            // The native template substitution pipeline does not run on provider types.
            $types = [];
            foreach ([
                $parameter->type ?? $parameter->declaredType,
                $parameter->closureThisType,
                $parameter->outType,
            ] as $metadata) {
                $type = $metadata === null ? null : $this->concrete($metadata->type, $model, $context->codebase);
                if ($metadata !== null && $type === null) {
                    return null;
                }
                $types[] = $type;
            }
            $parameters[] = new CallableParameter(
                name: $parameter->name,
                type: $types[0],
                closureThisType: $types[1],
                byReference: $parameter->flags->contains(MetadataFlags::BY_REFERENCE),
                variadic: $parameter->flags->contains(MetadataFlags::VARIADIC),
                hasDefault: $parameter->flags->contains(MetadataFlags::HAS_DEFAULT),
                outType: $types[2],
            );
        }

        return new EffectiveCallableSignature($parameters, displayName: $context->invocation->name);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$scope, $fluent, $model] = $resolved;
        $type = ($scope->returnType ?? $scope->declaredReturnType)?->type;
        if ($type === null || in_array((string) $type, ['void', 'null'], true)) {
            return $fluent;
        }
        $type = $this->concrete($type, $model, $context->codebase);
        if ($type === null) {
            return null;
        }
        $atomic = count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        $parameter = $atomic instanceof NamedObjectType ? $atomic->parameters[0] ?? null : null;
        $parameterAtomic = count($parameter->atomicTypes ?? []) === 1 ? $parameter->atomicTypes[0] : null;
        if (
            $atomic instanceof NamedObjectType
            && $this->isA($context->codebase, $atomic->name, self::BUILDER)
            && (
                $atomic->parameters === null
                || $parameterAtomic instanceof NamedObjectType
                && strcasecmp($parameterAtomic->name, $model) === 0
            )
        ) {
            // Laravel keeps the outer relation when its forwarded builder returns itself.
            return $fluent;
        }

        return $type;
    }

    /** @return array{FunctionLikeMetadata, Type, string}|null */
    private function resolve(Codebase $codebase, Invocation $invocation): ?array
    {
        $receiver = $invocation->receiverType;
        $atomic = count($receiver->atomicTypes ?? []) === 1 ? $receiver->atomicTypes[0] : null;
        if (! $atomic instanceof NamedObjectType || $atomic->intersections !== null) {
            return null;
        }
        // Keep native declarations, including accessible calls inside a model, intact.
        // Mago 1.56 rejects direct protected #[Scope] model calls even with a signature override.
        if ($codebase->getDeclaringMethod($atomic->name, $invocation->name) !== null) {
            return null;
        }
        if ($this->isA($codebase, $atomic->name, self::MODEL)) {
            if ($atomic->parameters !== null && $atomic->parameters !== []) {
                return null;
            }
            $model = $atomic->name;
            $fluent = Type::namedObject(self::BUILDER, Type::namedObject($model));
            $factory = $codebase->getDeclaringMethod($model, 'newEloquentBuilder');
            if ($factory !== null && strcasecmp($factory->identifier->class ?? '', self::MODEL) !== 0) {
                $builder = ($factory->returnType ?? $factory->declaredReturnType)?->type;
                $builder = $builder === null ? null : $this->concrete($builder, $model, $codebase);
                $builderAtomic = count($builder->atomicTypes ?? []) === 1 ? $builder->atomicTypes[0] : null;
                if (
                    ! $builderAtomic instanceof NamedObjectType
                    || ! $this->isA($codebase, $builderAtomic->name, self::BUILDER)
                ) {
                    return null;
                }
                if (
                    (
                        $builderAtomic->parameters === null
                        || $builderAtomic->parameters === []
                    )
                    && count($codebase->getClassLike($builderAtomic->name)->templates ?? []) !== 1
                ) {
                    return null;
                }
                $fluent =
                    $builderAtomic->parameters === null || $builderAtomic->parameters === []
                        ? Type::namedObject($builderAtomic->name, Type::namedObject($model))
                        : $builder;
            }
        } elseif (
            $this->isA($codebase, $atomic->name, self::BUILDER)
            || $this->isA($codebase, $atomic->name, self::RELATION)
        ) {
            $modelType = $atomic->parameters[0] ?? null;
            $modelAtomic = count($modelType->atomicTypes ?? []) === 1 ? $modelType->atomicTypes[0] : null;
            if (! $modelAtomic instanceof NamedObjectType || $this->hasTemplate($receiver)) {
                return null;
            }
            $model = $modelAtomic->name;
            $fluent = $receiver;
        } else {
            return null;
        }
        if (! $this->isA($codebase, $model, self::MODEL)) {
            return null;
        }

        $scope = $codebase->getDeclaringMethod($model, $invocation->name);
        if ($scope === null || ! $this->isAttributeScope($scope)) {
            $scope = $codebase->getDeclaringMethod($model, 'scope'.ucfirst($invocation->name));
            if ($scope === null || $scope->visibility === Visibility::Private) {
                return null;
            }
        }
        if ($scope->parameters === [] || $scope->templates !== []) {
            return null;
        }

        return [$scope, $fluent, $model];
    }

    private function isAttributeScope(FunctionLikeMetadata $method): bool
    {
        if ($method->visibility !== Visibility::Protected) {
            return false;
        }
        foreach ($method->attributes as $attribute) {
            if (strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\Scope') === 0) {
                return true;
            }
        }

        return false;
    }

    private function isA(Codebase $codebase, string $class, string $parent): bool
    {
        return (
            strcasecmp($class, $parent) === 0
            || in_array(
                strtolower($parent),
                array_map(strtolower(...), $codebase->getClassLike($class)->parentClasses ?? []),
                true,
            )
        );
    }

    private function concrete(Type $type, string $model, Codebase $codebase): ?Type
    {
        $atomics = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof NamedObjectType) {
                if ($atomic->intersections !== null) {
                    return null;
                }
                if ($atomic->static || $atomic->isThis) {
                    if (! $this->isA($codebase, $model, $atomic->name)) {
                        return null;
                    }
                    $atomics[] = Type::namedObject($model)->atomicTypes[0];

                    continue;
                }
                $parameters = [];
                foreach ($atomic->parameters ?? [] as $parameter) {
                    $parameter = $this->concrete($parameter, $model, $codebase);
                    if ($parameter === null) {
                        return null;
                    }
                    $parameters[] = $parameter;
                }
                $atomics[] = new NamedObjectType(
                    $atomic->name,
                    $atomic->parameters === null ? null : $parameters,
                    $atomic->variances,
                    false,
                    false,
                    null,
                    $atomic->remappedParameters,
                );
            } elseif ($this->hasTemplate($atomic)) {
                return null;
            } else {
                $atomics[] = $atomic;
            }
        }

        return Type::fromAtomics(...$atomics)->withFlags($type->flags);
    }

    private function hasTemplate(mixed $value): bool
    {
        if (
            $value instanceof GenericParameterType
            || $value instanceof NamedObjectType
            && (
                $value->static
                || $value->isThis
            )
        ) {
            return true;
        }
        // Walk SDK semantic values, not application classes or source code.
        foreach (is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : []) as $child) {
            if ($this->hasTemplate($child)) {
                return true;
            }
        }

        return false;
    }
}
