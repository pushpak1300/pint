<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;

final class ConfigMethods implements MethodReturnTypeProvider
{
    public function __construct(
        private ConfigTypes $configs,
    ) {}

    public function getTargets(): array
    {
        return [
            MethodTarget::exact('Illuminate\Contracts\Config\Repository', 'get'),
            MethodTarget::exact('Illuminate\Config\Repository', 'get'),
            MethodTarget::exact('Illuminate\Support\Facades\Config', 'get'),
        ];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        return $this->configs->getReturnType($context);
    }
}
