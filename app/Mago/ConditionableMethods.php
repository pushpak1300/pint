<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\CallableType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicType;
use Mago\Sdk\Analyzer\Type\SimpleAtomicTypeKind;

final class ConditionableMethods implements MethodReturnTypeProvider
{
    public function getTargets(): array
    {
        return [MethodTarget::anyClass('when'), MethodTarget::anyClass('unless')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;
        $receiver = count($invocation->receiverType->atomicTypes) === 1
            ? $invocation->receiverType->atomicTypes[0]
            : null;
        if (! $receiver instanceof NamedObjectType) {
            return null;
        }
        $method = $context->codebase->getDeclaringMethod($receiver->name, $invocation->name);
        if (strcasecmp($method?->identifier->class ?? '', 'Illuminate\\Support\\Traits\\Conditionable') !== 0) {
            return null;
        }
        foreach ([1 => 'callback', 2 => 'default'] as $index => $name) {
            $argument = $invocation->getArgument($index, $name);
            if ($index === 2 && $argument === null) {
                continue;
            }
            if ($argument === null || $argument->unpacked || $argument->placeholder || $argument->type === null) {
                return null;
            }
            foreach ($argument->type->atomicTypes as $atomic) {
                if (
                    $index === 2
                    && $atomic instanceof SimpleAtomicType
                    && $atomic->kind === SimpleAtomicTypeKind::Null
                ) {
                    continue;
                }
                if (! $atomic instanceof CallableType || $atomic->signature?->returnType === null) {
                    return null;
                }
                foreach ($atomic->signature->returnType->atomicTypes as $return) {
                    if (
                        ! $return instanceof SimpleAtomicType
                        || ! in_array($return->kind, [SimpleAtomicTypeKind::Null, SimpleAtomicTypeKind::Void], true)
                    ) {
                        return null;
                    }
                }
            }
        }

        return $invocation->receiverType;
    }
}
