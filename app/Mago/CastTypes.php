<?php

namespace App\Mago;

use Mago\Sdk\Analyzer\Metadata\ClassLikeKind;
use Mago\Sdk\Analyzer\PropertyAccessKind;
use Mago\Sdk\Analyzer\PropertyType;
use Mago\Sdk\Analyzer\PropertyTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\GenericParameterType;
use Mago\Sdk\Analyzer\Type\NamedObjectType;
use Mago\Sdk\Analyzer\Type\ScalarType;
use Mago\Sdk\Analyzer\Type\ScalarTypeKind;
use Mago\Sdk\Analyzer\Type\StringCasing;
use Mago\Sdk\Analyzer\Type\StringLiteralKind;
use Mago\Sdk\Analyzer\Type\StringType;

final class CastTypes
{
    public static function resolve(string $cast, PropertyTypeProviderContext $context): ?PropertyType
    {
        $cast = trim($cast);
        if (str_starts_with(strtolower($cast), 'encrypted:')) {
            $cast = substr($cast, 10);
        }
        $name = strtolower(explode(':', $cast, 2)[0]);
        $key = Type::union(Type::int(), Type::string());
        $read = match ($name) {
            'int', 'integer', 'timestamp' => Type::int(),
            'real', 'float', 'double' => Type::float(),
            'string', 'encrypted' => Type::string(),
            'decimal' => Type::fromAtomic(new ScalarType(ScalarTypeKind::String, new StringType(
                StringLiteralKind::General,
                null,
                true,
                false,
                false,
                false,
                StringCasing::Unspecified,
            ))),
            'bool', 'boolean' => Type::bool(),
            'array', 'json' => Type::array($key, Type::mixed()),
            'object' => Type::namedObject('stdClass'),
            'immutable_date', 'immutable_datetime' => Type::namedObject('Carbon\CarbonImmutable'),
            'collection',
            'illuminate\database\eloquent\casts\ascollection',
            'illuminate\database\eloquent\casts\asencryptedcollection',
                => Type::namedObject(
                'Illuminate\Support\Collection',
                $key,
                Type::mixed(),
            ),
            'illuminate\database\eloquent\casts\asarrayobject',
            'illuminate\database\eloquent\casts\asencryptedarrayobject',
                => Type::namedObject(
                'Illuminate\Database\Eloquent\Casts\ArrayObject',
            ),
            'illuminate\database\eloquent\casts\asstringable' => Type::namedObject('Illuminate\Support\Stringable'),
            default => null,
        };
        if ($read !== null) {
            $write = match ($name) {
                'bool', 'boolean' => Type::union(Type::bool(), Type::literalInt(0), Type::literalInt(1)),
                // Numeric inputs are accepted at runtime; never reproduce an impossible intersection type.
                'decimal' => Type::union($read, Type::int(), Type::float()),
                'immutable_date', 'immutable_datetime' => Type::union($read, Type::string()),
                'illuminate\database\eloquent\casts\ascollection',
                'illuminate\database\eloquent\casts\asencryptedcollection',
                'illuminate\database\eloquent\casts\asarrayobject',
                'illuminate\database\eloquent\casts\asencryptedarrayobject',
                    => Type::mixed(),
                'illuminate\database\eloquent\casts\asstringable' => Type::union(
                    Type::string(),
                    Type::namedObject('Stringable'),
                ),
                default => $read,
            };

            return new PropertyType(Type::union($read, Type::null()), Type::union($write, Type::null()));
        }

        $codebase = $context->codebase;
        $class = $codebase->getClassLike(explode(':', $cast, 2)[0]);
        if ($class === null || $class->hasIncompleteHierarchy()) {
            return null;
        }
        if ($class->kind === ClassLikeKind::Enum) {
            $type = Type::union(Type::namedObject($class->name), Type::null());

            return new PropertyType($type, $type);
        }
        $contracts = array_map(strtolower(...), [$class->name, ...$class->parentInterfaces]);
        if (in_array('illuminate\contracts\database\eloquent\castable', $contracts, true)) {
            $factory = $codebase->getDeclaringMethod($class->name, 'castUsing');
            $return = ($factory->returnType ?? $factory?->declaredReturnType)?->type;
            $atomic = count($return->atomicTypes ?? []) === 1 ? $return->atomicTypes[0] : null;
            if (! $atomic instanceof NamedObjectType || $atomic->parameters !== null) {
                return null;
            }
            $class = $codebase->getClassLike($atomic->name);
            if ($class === null || $class->hasIncompleteHierarchy()) {
                return null;
            }
            $contracts = array_map(strtolower(...), [$class->name, ...$class->parentInterfaces]);
        }
        $read = null;
        if (in_array('illuminate\contracts\database\eloquent\castsattributes', $contracts, true)) {
            $getter = $codebase->getDeclaringMethod($class->name, 'get');
            $read = ($getter->returnType ?? $getter?->declaredReturnType)?->type;
        } elseif (! in_array('illuminate\contracts\database\eloquent\castsinboundattributes', $contracts, true)) {
            return null;
        }
        $setter = $codebase->getDeclaringMethod($class->name, 'set');
        $write = null;
        foreach ($setter->parameters ?? [] as $parameter) {
            if ($parameter->name === '$value') {
                $write = ($parameter->type ?? $parameter->declaredType)?->type;
            }
        }
        $type = $context->access->kind === PropertyAccessKind::Read ? $read : $write;
        if ($type === null || self::hasTemplate($type)) {
            return null;
        }

        // A custom caster receives null too; its declared contract decides nullability.
        return $context->access->kind === PropertyAccessKind::Read
            ? new PropertyType(readType: $type)
            : new PropertyType(writeType: $type);
    }

    private static function hasTemplate(mixed $value): bool
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
        foreach (is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : []) as $child) {
            if (self::hasTemplate($child)) {
                return true;
            }
        }

        return false;
    }
}
