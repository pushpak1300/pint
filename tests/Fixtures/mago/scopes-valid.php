<?php

namespace ScopeChecks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ScopeFixtures\CustomRecord;
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
