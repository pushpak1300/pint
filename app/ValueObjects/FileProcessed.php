<?php

namespace App\ValueObjects;

final class FileProcessed
{
    public const NAME = 'pint.file_processed';

    public const STATUS_INVALID = 1;

    public const STATUS_SKIPPED = 2;

    public const STATUS_NO_CHANGES = 3;

    public const STATUS_FIXED = 4;

    public const STATUS_EXCEPTION = 5;

    public const STATUS_LINT = 6;

    public function __construct(
        private int $status,
    ) {}

    public function getStatus(): int
    {
        return $this->status;
    }
}
