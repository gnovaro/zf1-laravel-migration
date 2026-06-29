<?php

namespace App\Services\Zf1;

use Illuminate\Support\Facades\File;

class StubGenerator
{
    private string $stubPath;

    public function __construct(?string $stubPath = null)
    {
        $this->stubPath = $stubPath ?? base_path('stubs/zf1');
    }

    public function generate(string $stub, array $replacements): string
    {
        $stubContent = $this->getStub($stub);

        foreach ($replacements as $key => $value) {
            $stubContent = str_replace("{{ {$key} }}", $value, $stubContent);
        }

        return $stubContent;
    }

    public function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($path, $content);
    }

    private function getStub(string $name): string
    {
        $path = "{$this->stubPath}/{$name}.stub";

        if (!File::exists($path)) {
            throw new \RuntimeException("Stub not found: {$name} at {$path}");
        }

        return File::get($path);
    }
}
