<?php

namespace App\Support;

class PhpFragmentFormatter
{
    /**
     * Format embedded PHP without removing its imports or closing tag.
     */
    public function format(string $code, bool $fragment = false): string
    {
        return resolve(Mago::class)->formatFragment($code);
    }
}
