<?php

namespace ScopeFixtures;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ParentRecord extends Model
{
    /** @param Builder<static> $query */
    public function scopeMatching(Builder $query, string $label, int $minimum, bool $enabled = true): void {}

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeFluent(Builder $query, int $minimum): Builder
    {
        return $query;
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function tagged(Builder $query, string $tag, int $limit = 1): void {}

    /** @param Builder<static> $query */
    public function scopeTagged(Builder $query, int $tag): void {}

    /** @param Builder<static> $query */
    public function scopeLabels(Builder $query, string ...$labels): void {}

    /** @param Builder<static> $query */
    public function scopeReferenced(Builder $query, int &$value): void {}

    /** @param Builder<static> $query */
    public function scopeOrdinary(Builder $query, int $value): void {}

    public function ordinary(string $value): string
    {
        return $value;
    }

    /** @param Builder<static> $query */
    public function scopeTotal(Builder $query, int $value): int
    {
        return $value;
    }

    /**
     * @param Builder<static> $query
     * @return Builder<OtherRecord>
     */
    public function scopeOthers(Builder $query): Builder
    {
        return OtherRecord::query();
    }

    protected function hidden(Builder $query, int $value): void {}
}

class Record extends ParentRecord {}

class OtherRecord extends Model {}

/** @method string where(string $column) */
class NonForwardingRecord extends Model
{
    public function __call($method, $parameters): string
    {
        return 'custom';
    }
}

/**
 * @template T of Model
 * @extends Builder<T>
 */
class RecordBuilder extends Builder
{
    protected $passthru = ['count'];
}

class CustomRecord extends ParentRecord
{
    /** @return RecordBuilder<static> */
    public function newEloquentBuilder($query): RecordBuilder
    {
        return new RecordBuilder($query);
    }
}
