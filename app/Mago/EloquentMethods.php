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
use Mago\Sdk\Analyzer\Type\ArrayItem;
use Mago\Sdk\Analyzer\Type\CallableParameter;
use Mago\Sdk\Analyzer\Type\CallableSignature;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\ConditionalType;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\GenericParentKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\VariableType;
use Mago\Sdk\Analyzer\Type\Visibility;

final class EloquentMethods implements MethodReturnTypeProvider, CallableSignatureProvider
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const BUILDER = 'Illuminate\\Database\\Eloquent\\Builder';

    private const QUERY = 'Illuminate\\Database\\Query\\Builder';

    public function getTargets(): array
    {
        return [MethodTarget::allMethods(self::MODEL)];
    }

    public function getCallableSignature(CallableSignatureProviderContext $context): ?EffectiveCallableSignature
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$method, $model, $builder] = $resolved;

        return $this->signature($method, $model, $builder, $context->codebase);
    }

    private function signature(
        FunctionLikeMetadata $method,
        Type $model,
        Type $builder,
        Codebase $codebase,
    ): ?EffectiveCallableSignature {
        $parameters = [];
        foreach ($method->parameters as $parameter) {
            $types = [];
            foreach ([
                $parameter->type ?? $parameter->declaredType,
                $parameter->closureThisType,
                $parameter->outType,
            ] as $metadata) {
                $type = $metadata === null ? null : $this->concrete($metadata->type, $model, $builder, $codebase);
                if ($type === null && $metadata !== null) {
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

        return new EffectiveCallableSignature($parameters, displayName: $method->originalName);
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $resolved = $this->resolve($context->codebase, $context->invocation);
        if ($resolved === null) {
            return null;
        }
        [$method, $model, $builder, $query] = $resolved;
        if ($this->signature($method, $model, $builder, $context->codebase) === null) {
            return null;
        }
        if ($query) {
            $passthrough = $this->passthrough($context->codebase, $builder, $context->invocation->name);
            if ($passthrough === null) {
                return null;
            }
            if (! $passthrough) {
                return $builder;
            }
        }
        $type = ($method->returnType ?? $method->declaredReturnType)?->type;
        if ($type === null) {
            return null;
        }

        $arguments = [];
        foreach ($method->parameters as $index => $parameter) {
            $arguments[ltrim($parameter->name, '$')] = $context->invocation->getArgument(
                $index,
                ltrim($parameter->name, '$'),
            )?->type;
        }

        return $this->concrete(
            $type,
            $model,
            $query ? Type::namedObject(self::QUERY) : $builder,
            $context->codebase,
            $context,
            $arguments,
        );
    }

    /** @return array{FunctionLikeMetadata, Type, Type, bool}|null */
    private function resolve(Codebase $codebase, Invocation $invocation): ?array
    {
        $receiver = $invocation->receiverType;
        $atomic = count($receiver->atomicTypes ?? []) === 1 ? $receiver->atomicTypes[0] : null;
        if (
            ! $atomic instanceof NamedObjectType
            || $atomic->intersections !== null
            || ! $this->isA($codebase, $atomic->name, self::MODEL)
            || $codebase->getDeclaringMethod($atomic->name, $invocation->name) !== null
        ) {
            return null;
        }
        $metadata = $codebase->getClassLike($atomic->name);
        if (in_array(strtolower($invocation->name), array_map(strtolower(...), [
            ...($metadata->pseudoMethods ?? []),
            ...($metadata->staticPseudoMethods ?? []),
        ]), true)) {
            return null;
        }
        foreach (['__call', '__callStatic', 'newQuery', 'newModelQuery'] as $name) {
            $declared = $codebase->getDeclaringMethod($atomic->name, $name);
            if ($declared !== null && strcasecmp($declared->identifier->class ?? '', self::MODEL) !== 0) {
                return null;
            }
        }

        $model = Type::namedObject($atomic->name);
        $builder = Type::namedObject(self::BUILDER, $model);
        $factory = $codebase->getDeclaringMethod($atomic->name, 'newEloquentBuilder');
        if (strcasecmp($factory?->identifier->class ?? '', self::MODEL) === 0) {
            $property = $codebase->getDeclaringProperty($atomic->name, '$builder')?->defaultType?->type;
            if ($property !== null && strcasecmp($property->getLiteralClassString() ?? '', self::BUILDER) !== 0) {
                return null;
            }
            foreach ($metadata->attributes ?? [] as $attribute) {
                if (
                    strcasecmp($attribute->name, 'Illuminate\\Database\\Eloquent\\Attributes\\UseEloquentBuilder') === 0
                ) {
                    return null;
                }
            }
        }
        if ($factory !== null && strcasecmp($factory->identifier->class ?? '', self::MODEL) !== 0) {
            $custom = ($factory->returnType ?? $factory->declaredReturnType)?->type;
            $custom = $custom === null ? null : $this->concrete($custom, $model, $builder, $codebase);
            $customAtomic = count($custom->atomicTypes ?? []) === 1 ? $custom->atomicTypes[0] : null;
            if (
                ! $customAtomic instanceof NamedObjectType
                || ! $this->isA($codebase, $customAtomic->name, self::BUILDER)
            ) {
                return null;
            }
            $builder = $custom;
        }

        $builderClass = $builder->atomicTypes[0];
        if (! $builderClass instanceof NamedObjectType) {
            return null;
        }
        $dispatch = $codebase->getDeclaringMethod($builderClass->name, '__call');
        if ($dispatch !== null && strcasecmp($dispatch->identifier->class ?? '', self::BUILDER) !== 0) {
            return null;
        }
        $method = $codebase->getDeclaringMethod($builderClass->name, $invocation->name);
        $query = $method === null;
        $method ??= $codebase->getDeclaringMethod(self::QUERY, $invocation->name);
        if ($method === null || $method->visibility !== Visibility::Public || $method->templates !== []) {
            return null;
        }

        return [$method, $model, $builder, $query];
    }

    private function passthrough(Codebase $codebase, Type $builder, string $method): ?bool
    {
        $class = $builder->atomicTypes[0];
        if (! $class instanceof NamedObjectType) {
            return null;
        }
        $type = $codebase->getDeclaringProperty($class->name, '$passthru')?->defaultType?->type;
        $list = count($type->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        if (! $list instanceof ListType || $list->knownElements === null) {
            return null;
        }
        foreach ($list->knownElements as $element) {
            $name = $element->type->getLiteralString();
            if ($name === null) {
                return null;
            }
            if (strcasecmp($name, $method) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, Type|null> $arguments */
    private function concrete(
        Type $type,
        Type $model,
        Type $builder,
        Codebase $codebase,
        ?ReturnTypeProviderContext $context = null,
        array $arguments = [],
    ): ?Type {
        $replace = fn (Type $type): ?Type => $this->concrete($type, $model, $builder, $codebase, $context, $arguments);
        $atomics = [];
        foreach ($type->atomicTypes as $atomic) {
            if ($atomic instanceof GenericParameterType) {
                if (
                    $atomic->definingEntity->kind !== GenericParentKind::ClassLike
                    || ! (
                        $atomic->name === 'TModel'
                        && $this->isA($codebase, $atomic->definingEntity->name, self::BUILDER)
                        || $atomic->name === 'TValue'
                        && strcasecmp($atomic->definingEntity->name, 'Illuminate\\Database\\Concerns\\BuildsQueries')
                        === 0
                    )
                    || $atomic->intersections !== null
                ) {
                    return null;
                }
                array_push($atomics, ...$model->atomicTypes);
            } elseif ($atomic instanceof NamedObjectType) {
                if ($atomic->intersections !== null) {
                    return null;
                }
                if ($atomic->name === '$this' && $atomic->isThis) {
                    array_push($atomics, ...$builder->atomicTypes);

                    continue;
                }
                if ($atomic->static || $atomic->isThis) {
                    $replacement = $this->isA($codebase, $atomic->name, self::BUILDER) ? $builder : $model;
                    if (
                        ! $this->isA($codebase, $atomic->name, self::BUILDER)
                        && ! $this->isA($codebase, $atomic->name, self::MODEL)
                    ) {
                        return null;
                    }
                    array_push($atomics, ...$replacement->atomicTypes);

                    continue;
                }
                $parameters = [];
                foreach ($atomic->parameters ?? [] as $parameter) {
                    $parameter = $replace($parameter);
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
            } elseif ($atomic instanceof CallableType && $atomic->signature !== null) {
                $signature = $atomic->signature;
                $parameters = [];
                foreach ($signature->parameters as $parameter) {
                    $types = [];
                    foreach ([$parameter->type, $parameter->closureThisType, $parameter->outType] as $parameterType) {
                        $replacement = $parameterType === null ? null : $replace($parameterType);
                        if ($replacement === null && $parameterType !== null) {
                            return null;
                        }
                        $types[] = $replacement;
                    }
                    $parameters[] = new CallableParameter(
                        name: $parameter->name,
                        type: $types[0],
                        closureThisType: $types[1],
                        byReference: $parameter->byReference,
                        variadic: $parameter->variadic,
                        hasDefault: $parameter->hasDefault,
                        outType: $types[2],
                    );
                }
                $return = $signature->returnType === null ? null : $replace($signature->returnType);
                if ($return === null && $signature->returnType !== null || $signature->constraints !== []) {
                    return null;
                }
                $atomics[] = new CallableType(new CallableSignature(
                    $signature->pure,
                    $signature->closure,
                    $parameters,
                    $return,
                    $signature->source,
                    [],
                ), null);
            } elseif ($atomic instanceof KeyedArrayType) {
                $items = [];
                foreach ($atomic->knownItems ?? [] as $item) {
                    $value = $replace($item->type);
                    if ($value === null) {
                        return null;
                    }
                    $items[] = new ArrayItem($item->key, $item->optional, $value);
                }
                $key = $atomic->keyType === null ? null : $replace($atomic->keyType);
                $value = $atomic->valueType === null ? null : $replace($atomic->valueType);
                if ($key === null && $atomic->keyType !== null || $value === null && $atomic->valueType !== null) {
                    return null;
                }
                $atomics[] = new KeyedArrayType(
                    $atomic->knownItems === null ? null : $items,
                    $key,
                    $value,
                    $atomic->nonEmpty,
                );
            } elseif ($atomic instanceof ConditionalType) {
                $variable = count($atomic->subject->atomicTypes) === 1 ? $atomic->subject->atomicTypes[0] : null;
                $input = $variable instanceof VariableType ? $arguments[ltrim($variable->name, '$')] ?? null : null;
                $target = $replace($atomic->target);
                $then = $replace($atomic->then);
                $otherwise = $replace($atomic->otherwise);
                if ($context === null || $input === null || $target === null || $then === null || $otherwise === null) {
                    return null;
                }
                $matches = $context->types->isContainedBy($input, $target);
                $overlaps = $context->types->canBeIdentical($input, $target);
                $result = $matches ? $then : ($overlaps ? Type::union($then, $otherwise) : $otherwise);
                if ($atomic->negated) {
                    $result = $matches ? $otherwise : ($overlaps ? Type::union($then, $otherwise) : $then);
                }
                array_push($atomics, ...$result->atomicTypes);
            } elseif ($this->hasTemplate($atomic)) {
                return null;
            } else {
                $atomics[] = $atomic;
            }
        }

        return Type::fromAtomics(...$atomics)->withFlags($type->flags);
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

    private function hasTemplate(mixed $value): bool
    {
        if ($value instanceof GenericParameterType || $value instanceof VariableType) {
            return true;
        }
        foreach (is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : []) as $child) {
            if ($this->hasTemplate($child)) {
                return true;
            }
        }

        return false;
    }
}
