<?php

namespace App\ValueObjects;

final class ErrorsManager
{
    /** @var list<Error> */
    private array $errors = [];

    public function report(Error $error): void
    {
        $this->errors[] = $error;
    }

    /** @return list<Error> */
    public function getInvalidErrors(): array
    {
        return $this->ofType(Error::TYPE_INVALID);
    }

    /** @return list<Error> */
    public function getExceptionErrors(): array
    {
        return $this->ofType(Error::TYPE_EXCEPTION);
    }

    /** @return list<Error> */
    public function getLintErrors(): array
    {
        return $this->ofType(Error::TYPE_LINT);
    }

    /** @return list<Error> */
    private function ofType(int $type): array
    {
        return array_values(array_filter($this->errors, fn (Error $error) => $error->getType() === $type));
    }
}
