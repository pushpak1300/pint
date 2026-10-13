<?php

namespace ScopeChecks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ScopeFixtures\CustomRecord;
use ScopeFixtures\NonForwardingRecord;
use ScopeFixtures\OtherRecord;
use ScopeFixtures\Record;
use ScopeFixtures\RecordBuilder;

function model(Record $record): ?Record
{
    return $record->matching('label', 2)->tagged('tag')->get()->first();
}

function statically(): ?Record
{
    return Record::matching(minimum: 2, label: 'label')->fluent(2)->get()->first();
}

/** @param Builder<Record> $query */
function builder(Builder $query): ?Record
{
    return $query
        ->matching(label: 'label', minimum: 2, enabled: false)
        ->tagged(limit: 2, tag: 'tag')
        ->fluent(2)
        ->get()
        ->first();
}

/**
 * @param HasMany<Record, OtherRecord> $relation
 * @return HasMany<Record, OtherRecord>
 */
function relation(HasMany $relation): HasMany
{
    return $relation->matching('label', 2)->tagged('tag')->fluent(2);
}

/** @param HasMany<Record, OtherRecord> $relation */
function elements(HasMany $relation): ?Record
{
    return $relation->matching('label', 2)->get()->first();
}

function metadata(Record $record, int &$value): void
{
    $record->labels();
    $record->labels('one', 'two');
    $record->referenced($value);
}

function ordinary(Record $record): string
{
    return $record->ordinary('not a scope');
}

/** @return RecordBuilder<CustomRecord> */
function custom(): RecordBuilder
{
    return CustomRecord::matching('label', 2)->fluent(2);
}

function returns(Record $record): int
{
    return $record->total(2);
}

function otherElements(Record $record): ?OtherRecord
{
    return $record->others()->get()->first();
}

function nativeQueries(): ?Record
{
    return Record::where('active', true)->whereIn('id', [1, 4])->get()->first();
}

function nativeFinder(int $id): Record
{
    return Record::findOrFail($id);
}

/** @return Collection<int, Record> */
function nativeMultipleFinder(): Collection
{
    return Record::findOrFail([1, 4]);
}

function nativeCreate(): Record
{
    return Record::create(['active' => true]);
}

function nativePassthrough(): bool
{
    return Record::exists();
}

/** @return Builder<Record> */
function nativeClosure(): Builder
{
    return Record::where(function (Builder $query): void {
        $query->where('active', true);
    });
}

function nativeNamedFinder(): Record
{
    return Record::findOrFail(columns: ['id'], id: 9);
}

/** @return RecordBuilder<CustomRecord> */
function customPassthrough(): RecordBuilder
{
    return CustomRecord::exists();
}

function customCount(): int
{
    return CustomRecord::count();
}

/** @return Builder<Record> */
function conditional(bool $enabled): Builder
{
    return Record::query()->when($enabled, function (Builder $query): void {
        $query->where('active', true);
    })->unless($enabled, function (Builder $query): null {
        $query->where('active', false);

        return null;
    });
}

function nativeEach(): bool
{
    return Record::each(function (Record $record): void {
        $record->ordinary('label');
    });
}

function overridden(NonForwardingRecord $record): string
{
    return $record->where('active');
}
