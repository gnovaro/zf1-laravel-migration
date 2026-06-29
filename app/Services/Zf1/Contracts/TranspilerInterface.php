<?php

namespace App\Services\Zf1\Contracts;

interface TranspilerInterface
{
    public function transpile(array $parsedData): string;
}
