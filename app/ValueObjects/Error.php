<?php

namespace App\ValueObjects;

use Throwable;

final class Error
{
    public const TYPE_INVALID = 1;

    public const TYPE_EXCEPTION = 2;

    public const TYPE_LINT = 3;

    public function __construct(
        private int $type,
        private string $filePath,
        private ?Throwable $source = null,
        private ?int $sourceLine = null,
    ) {}

    public function getType(): int
    {
        return $this->type;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getSource(): ?Throwable
    {
        return $this->source;
    }

    public function getSourceLine(): ?int
    {
        return $this->sourceLine;
    }
}
