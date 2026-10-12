<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyTarget;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProvider;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\ArrayKeyKind;
use Mago\Sdk\Analyzer\Type\KeyedArrayType;
use Mago\Sdk\Analyzer\Type\ListType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

final class ModelProperties implements PropertyTypeProvider
{
    public function __construct(
        private Laravel $laravel,
    ) {}

    public function getTargets(): array
    {
        return [PropertyTarget::allProperties('Illuminate\Database\Eloquent\Model')];
    }

    public function getPropertyType(PropertyTypeProviderContext $context): ?PropertyType
    {
        $class = $context->access->class;
        $property = $context->access->property;
        $codebase = $context->codebase;
        if (
            $codebase->propertyExists($class, '$'.$property)
            || $codebase->magicPropertyExists($class, '$'.$property)
        ) {
            return null;
        }

        $studly = str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $property)));
        if ($context->access->kind === PropertyAccessKind::Write) {
            $setter = $codebase->getDeclaringMethod($class, 'set'.$studly.'Attribute');
            if ($setter !== null) {
                $parameter = $setter->parameters[0] ?? null;
                $type = ($parameter->type ?? $parameter?->declaredType)?->type;

                return $type === null ? null : new PropertyType(writeType: $type);
            }
        }
        $getter = $codebase->getDeclaringMethod($class, 'get'.$studly.'Attribute');
        if ($getter !== null && $context->access->kind === PropertyAccessKind::Read) {
            $type = ($getter->returnType ?? $getter->declaredReturnType)?->type;

            return $type === null ? null : new PropertyType(readType: $type);
        }

        $accessor = $codebase->getDeclaringMethod($class, lcfirst($studly));
        $type = ($accessor->returnType ?? $accessor?->declaredReturnType)?->type;
        $atomic = count($type->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        $declared = $accessor?->declaredReturnType?->type->atomicTypes[0] ?? null;
        if (
            $atomic instanceof NamedObjectType
            && strcasecmp($atomic->name, 'Illuminate\Database\Eloquent\Casts\Attribute') === 0
            && $declared instanceof NamedObjectType
            && strcasecmp($declared->name, 'Illuminate\Database\Eloquent\Casts\Attribute') === 0
        ) {
            $type = $atomic->parameters[$context->access->kind === PropertyAccessKind::Read ? 0 : 1] ?? null;
            if ($type === null || $context->access->kind === PropertyAccessKind::Read && (string) $type === 'never') {
                return null;
            }

            return $context->access->kind === PropertyAccessKind::Read
                ? new PropertyType(readType: $type)
                : new PropertyType(writeType: $type);
        }

        $castsMethod = $codebase->getDeclaringMethod($class, 'casts');
        $methodCasts = $castsMethod === null
            ? []
            : $this->laravel->casts[$castsMethod->location->file ?? ''][$castsMethod->location->span->start] ?? $this->literalCasts(
                ($castsMethod->returnType ?? $castsMethod->declaredReturnType)
                    ?->type,
            );
        if (
            $methodCasts === null
            && in_array(
                strtolower($castsMethod?->identifier->class ?? ''),
                [
                    'illuminate\database\eloquent\model',
                    'illuminate\database\eloquent\concerns\hasattributes',
                ],
                true,
            )
        ) {
            $methodCasts = [];
        }

        // An unknown casts() may override any property cast or shadow a relationship.
        if ($methodCasts === null) {
            return null;
        }
        $propertyCasts = $this->literalCasts($codebase->getDeclaringProperty($class, '$casts')?->defaultType?->type);
        if ($propertyCasts === null && ! isset($methodCasts[$property])) {
            return null;
        }
        $casts = array_replace($propertyCasts ?? [], $methodCasts);
        if (isset($casts[$property])) {
            return CastTypes::resolve($casts[$property], $context);
        }

        $relation = $codebase->getDeclaringMethod($class, $property);
        $type = ($relation->returnType ?? $relation?->declaredReturnType)?->type;
        $atomic = count($type->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        if (! $atomic instanceof NamedObjectType || ! isset($atomic->parameters[0]) || $relation?->static) {
            return null;
        }

        return match (strtolower($atomic->name)) {
            'illuminate\database\eloquent\relations\hasmany',
            'illuminate\database\eloquent\relations\belongstomany',
            'illuminate\database\eloquent\relations\morphmany',
            'illuminate\database\eloquent\relations\morphtomany',
            'illuminate\database\eloquent\relations\hasmanythrough',
                => new PropertyType(readType: Type::namedObject(
                'Illuminate\Database\Eloquent\Collection',
                Type::int(),
                $atomic->parameters[0],
            )),
            'illuminate\database\eloquent\relations\belongsto',
            'illuminate\database\eloquent\relations\hasone',
            'illuminate\database\eloquent\relations\morphone',
            'illuminate\database\eloquent\relations\hasonethrough',
                => new PropertyType(
                readType: Type::union($atomic->parameters[0], Type::null()),
            ),
            default => null,
        };
    }

    /** @return array<string, string>|null */
    private function literalCasts(?Type $type): ?array
    {
        $array = count($type->atomicTypes ?? []) === 1 ? $type->atomicTypes[0] : null;
        if ($array instanceof ListType && $array->knownCount === 0) {
            return [];
        }
        if (! $array instanceof KeyedArrayType || $array->keyType !== null) {
            return null;
        }

        $casts = [];
        foreach ($array->knownItems ?? [] as $item) {
            $value = $item->type->getLiteralString();
            if ($item->optional || $item->key->kind !== ArrayKeyKind::String || $value === null) {
                return null;
            }
            $casts[(string) $item->key->value] = $value;
        }

        return $casts;
    }
}
