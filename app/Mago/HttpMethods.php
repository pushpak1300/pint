<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\Invocation;
use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

final class HttpMethods implements MethodReturnTypeProvider
{
    private const REQUEST = 'Illuminate\\Http\\Client\\PendingRequest';

    public function getTargets(): array
    {
        return array_map(
            MethodTarget::allMethods(...),
            [
                self::REQUEST,
                'Illuminate\\Http\\Client\\Factory',
                'Illuminate\\Support\\Facades\\Http',
            ],
        );
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        $invocation = $context->invocation;
        $receiver = count($invocation->receiverType->atomicTypes) === 1
            ? $invocation->receiverType->atomicTypes[0]
            : null;
        if (! $receiver instanceof NamedObjectType || $receiver->intersections !== null) {
            return null;
        }
        if (strcasecmp($receiver->name, self::REQUEST) === 0) {
            return strtolower($invocation->name) === 'async' ? $this->async($invocation) : null;
        }
        if (! in_array(
            $receiver->name,
            ['Illuminate\\Http\\Client\\Factory', 'Illuminate\\Support\\Facades\\Http'],
            true,
        )) {
            return null;
        }
        if ($context->codebase->getDeclaringMethod('Illuminate\\Http\\Client\\Factory', $invocation->name) !== null) {
            return null;
        }
        if (strtolower($invocation->name) === 'async') {
            return $this->async($invocation);
        }

        $method = $context->codebase->getDeclaringMethod(self::REQUEST, $invocation->name);
        if ($method === null) {
            return null;
        }
        $type = ($method->returnType ?? $method->declaredReturnType)?->type;
        if ($type === null) {
            return null;
        }
        if (in_array(strtolower($invocation->name), ['get', 'head', 'post', 'patch', 'put', 'delete', 'send'], true)) {
            return Type::namedObject('Illuminate\\Http\\Client\\Response');
        }
        $atomic = count($type->atomicTypes) === 1 ? $type->atomicTypes[0] : null;
        if (
            $atomic instanceof NamedObjectType
            && (
                $atomic->name === self::REQUEST
                || $atomic->name === '$this'
                && $atomic->isThis
            )
        ) {
            return Type::namedObject(self::REQUEST, Type::false());
        }

        return null;
    }

    private function async(Invocation $invocation): ?Type
    {
        $argument = $invocation->getArgument(0, 'async');
        if ($argument !== null && ($argument->unpacked || $argument->placeholder || $argument->type === null)) {
            return null;
        }
        $value = $argument?->type?->getLiteralBool();
        $state =
            $argument === null || $value === true ? Type::true() : ($value === false ? Type::false() : Type::bool());

        return Type::namedObject(self::REQUEST, $state);
    }
}
