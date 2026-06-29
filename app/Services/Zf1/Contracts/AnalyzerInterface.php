<?php

namespace App\Services\Zf1\Contracts;

interface AnalyzerInterface
{
    public function analyze(string $path): array;
}
