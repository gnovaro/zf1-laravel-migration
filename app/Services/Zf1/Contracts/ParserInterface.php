<?php

namespace App\Services\Zf1\Contracts;

interface ParserInterface
{
    public function parse(string $filePath): ?array;
}
