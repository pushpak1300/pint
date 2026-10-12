<?php

namespace ScopeChecks;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ScopeFixtures\OtherRecord;
use ScopeFixtures\Record;

/** @param Builder<Record> $query */
function arguments(Record $record, Builder $query): void
{
    $record->matching(2, 'label');
    Record::matching(label: 'label', minimum: 'two');
    $query->matching('label');
    $query->tagged(tag: 2);
    $query->matching(label: 'label', minimum: 2, typo: true);
    $record->labels('valid', 2);
    $record->referenced(2);
    $record->ordinary(2);
    $record->notAScope('label', 2);
    $query->matchign('label', 2);
    $query->hidden(2);
}

/** @param Builder<Record> $query */
function wrongElement(Builder $query): ?OtherRecord
{
    return $query->matching('label', 2)->fluent(2)->get()->first();
}

/** @param HasMany<Record, OtherRecord> $relation */
function wrongRelationElement(HasMany $relation): ?OtherRecord
{
    return $relation->tagged('tag')->get()->first();
}
