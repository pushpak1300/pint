<?php

namespace CastFixtures;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

enum State: string
{
    case Open = 'open';
}

class Amount implements Castable
{
    public static function castUsing(array $arguments): AmountCast
    {
        return new AmountCast;
    }
}

/** @implements CastsAttributes<Amount, numeric-string> */
class AmountCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): Amount
    {
        return new Amount;
    }

    /** @param numeric-string $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }
}

class HashCast implements CastsInboundAttributes
{
    /** @param string $value */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return hash('sha256', $value);
    }
}

class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'state' => State::class,
            'amount' => Amount::class,
            'cash' => AmountCast::class,
            'secret' => HashCast::class,
            'label' => AsStringable::class,
            'items' => AsCollection::class,
            'options' => AsArrayObject::class,
            'enabled' => 'boolean',
            'price' => 'decimal:2',
            'paid_at' => 'immutable_datetime',
            'payload' => 'encrypted:array',
        ];
    }

    /** @return Attribute<int, string> */
    protected function score(): Attribute
    {
        return Attribute::make(get: fn (): int => 42, set: fn (string $value): int => strlen($value));
    }

    public function getReferenceAttribute(): int
    {
        return 42;
    }

    public function setReferenceAttribute(string $value): void
    {
        $this->attributes['reference'] = strlen($value);
    }
}
