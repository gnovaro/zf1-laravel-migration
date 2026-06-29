<?php

namespace App\Services\Zf1\Parser;

use App\Services\Zf1\Contracts\ParserInterface;
use Illuminate\Support\Facades\File;

class ViewParser implements ParserInterface
{
    public function parse(string $filePath): ?array
    {
        if (!File::exists($filePath)) {
            return null;
        }

        $content = File::get($filePath);

        return [
            'file_path' => $filePath,
            'content' => $content,
            'helpers' => $this->detectHelpers($content),
            'echo_statements' => $this->detectEchos($content),
            'control_structures' => $this->detectControlStructures($content),
            'partials' => $this->detectPartials($content),
            'translations' => $this->detectTranslations($content),
            'has_form' => str_contains($content, '<form') || str_contains($content, 'Zend_Form'),
        ];
    }

    private function detectHelpers(string $content): array
    {
        $helpers = [];

        $patterns = [
            '/\$this->escape\(([^)]+)\)/',
            '/\$this->url\(([^)]*)\)/',
            '/\$this->baseUrl\(\)/',
            '/\$this->doctype\(\)/',
            '/\$this->headTitle\(\)/',
            '/\$this->headScript\(\)/',
            '/\$this->headLink\(\)/',
            '/\$this->headMeta\(\)/',
            '/\$this->partial\(([^)]+)\)/',
            '/\$this->form\w+\(/',
            '/\$this->translate\(([^)]+)\)/',
            '/\$this->currency\(([^)]+)\)/',
            '/\$this->date\(([^)]+)\)/',
            '/\$this->partialLoop\(([^)]+)\)/',
            '/\$this->action\(([^)]+)\)/',
            '/\$this->layout\(\)/',
            '/\$this->json\(([^)]+)\)/',
            '/\$this->serverUrl\(\)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                $helpers = array_merge($helpers, $matches[0]);
            }
        }

        return array_unique($helpers);
    }

    private function detectEchos(string $content): array
    {
        $echos = [];

        // Standard PHP echo
        preg_match_all('/<\?=\s*(.+?)\s*\?>/', $content, $shortMatches);
        foreach ($shortMatches[1] ?? [] as $m) {
            $echos[] = ['type' => 'short_echo', 'expression' => trim($m)];
        }

        // Long echo
        preg_match_all('/<\?php\s+echo\s+(.+?);\s*\?>/', $content, $longMatches);
        foreach ($longMatches[1] ?? [] as $m) {
            $echos[] = ['type' => 'echo', 'expression' => trim($m)];
        }

        preg_match_all('/<\?=\s*\$this->escape\((.+?)\)\s*\?>/', $content, $escapedMatches);
        foreach ($escapedMatches[1] ?? [] as $m) {
            $echos[] = ['type' => 'escaped', 'expression' => trim($m)];
        }

        return $echos;
    }

    private function detectControlStructures(string $content): array
    {
        $structures = [];

        $patterns = [
            'foreach' => '/<\?php\s+foreach\s*\((.+?)\)\s*:\s*\?>/',
            'for' => '/<\?php\s+for\s*\((.+?)\)\s*:\s*\?>/',
            'if' => '/<\?php\s+if\s*\((.+?)\)\s*:\s*\?>/',
            'elseif' => '/<\?php\s+elseif\s*\((.+?)\)\s*:\s*\?>/',
            'else' => '/<\?php\s+else\s*:\s*\?>/',
            'endforeach' => '/<\?php\s+endforeach\s*;\s*\?>/',
            'endfor' => '/<\?php\s+endfor\s*;\s*\?>/',
            'endif' => '/<\?php\s+endif\s*;\s*\?>/',
            'endwhile' => '/<\?php\s+endwhile\s*;\s*\?>/',
            'while' => '/<\?php\s+while\s*\((.+?)\)\s*:\s*\?>/',
        ];

        foreach ($patterns as $type => $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $structures[] = [
                    'type' => $type,
                    'expression' => $match[1] ?? '',
                    'full_match' => $match[0],
                ];
            }
        }

        return $structures;
    }

    private function detectPartials(string $content): array
    {
        $partials = [];

        preg_match_all('/\$this->partial\(\s*[\'"]([^\'"]+)[\'"]\s*(?:,\s*(.+?))?\)/', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $partials[] = [
                'template' => $match[1],
                'data' => $match[2] ?? null,
            ];
        }

        return $partials;
    }

    private function detectTranslations(string $content): array
    {
        $translations = [];

        preg_match_all('/\$this->translate\(\s*[\'"]([^\'"]+)[\'"](?:\s*,\s*(.+?))?\)/', $content, $matches);

        foreach ($matches[1] ?? [] as $m) {
            $translations[] = $m;
        }

        return $translations;
    }
}
