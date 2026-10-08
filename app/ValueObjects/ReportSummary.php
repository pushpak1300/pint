<?php

namespace App\ValueObjects;

final class ReportSummary
{
    /** @param array<string, array{appliedFixers: list<string>, diff: string}> $changed */
    public function __construct(
        private array $changed,
        private int $filesCount,
        private int $time,
        private int $memory,
        private bool $addAppliedFixers,
        private bool $isDryRun,
        private bool $isDecoratedOutput,
    ) {}

    /** @return array<string, array{appliedFixers: list<string>, diff: string}> */
    public function getChanged(): array
    {
        return $this->changed;
    }

    public function getFilesCount(): int
    {
        return $this->filesCount;
    }

    public function getTime(): int
    {
        return $this->time;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function shouldAddAppliedFixers(): bool
    {
        return $this->addAppliedFixers;
    }

    public function isDryRun(): bool
    {
        return $this->isDryRun;
    }

    public function isDecoratedOutput(): bool
    {
        return $this->isDecoratedOutput;
    }
}
