<?php

namespace App\Services\Zf1\Transpiler;

use App\Services\Zf1\Contracts\TranspilerInterface;

class ViewTranspiler implements TranspilerInterface
{
    private array $replacements = [];

    public function transpile(array $parsedData): string
    {
        $content = $parsedData['content'];

        // Order matters: helpers -> $this-> -> controls -> echos -> cleanup
        $content = $this->convertHelpers($content);
        $content = $this->convertTranslations($content);
        $content = $this->convertViewObjectReferences($content);
        $content = $this->convertControlStructures($content);
        $content = $this->convertEchos($content);
        $content = $this->cleanupPhpTags($content);

        return $content;
    }

    private function convertViewObjectReferences(string $content): string
    {
        // Replace $this->xxx with just $xxx in view context
        // (ZF1 views reference assigned vars via $this, Laravel uses them directly)
        // Exclude things like $this->url(), $this->escape() etc.
        $helperMethods = 'escape|escapeHtml|url|baseUrl|translate|__|partial|partialLoop|headTitle|headScript|headLink|headMeta|doctype|form|formText|formSelect|formSubmit|formHidden|formPassword|formCheckbox|formRadio|formTextarea|formLabel|formReset|formButton|formNote|formDescription|formErrors|formFile|formHash|formImage|action|layout|json|serverUrl|getHelper|getLayout|getRequest|getResponse|getView|getModuleName|getControllerName|getActionName|getParam|getAllParams|getQuery|getPost|getCookie|getRaw|getServer|getEnv|redirect|__call|quoteEscape|getChildHtml|getBlockHtml|getMessagesBlock|getChildGroup|flashMessenger|getUrl';

        $content = preg_replace(
            '/\$this->(?!(' . $helperMethods . ')\()([a-zA-Z_]\w*)/',
            '\$$2',
            $content
        );

        return $content;
    }

    private function convertEchos(string $content): string
    {
        // $this->escape($var) => {{ $var }}
        $content = preg_replace(
            '/<\?=\s*\$this->escape\((.+?)\)\s*\?>/',
            '{{ $1 }}',
            $content
        );

        $content = preg_replace(
            '/<\?php\s+echo\s+\$this->escape\((.+?)\);\s*\?>/',
            '{{ $1 }}',
            $content
        );

        // $this->escapeHtml($var) => {{ $var }}
        $content = preg_replace(
            '/<\?=\s*\$this->escapeHtml\((.+?)\)\s*\?>/',
            '{{ $1 }}',
            $content
        );

        // Short echo vars (non-escaped) => {!! !!} for raw, {{ }} for escaped
        // We default to {{ }} for safety
        $content = preg_replace(
            '/<\?=\s*(.+?)\s*\?>/',
            '{{ $1 }}',
            $content
        );

        // Long echo statements
        $content = preg_replace(
            '/<\?php\s+echo\s+(.+?);\s*\?>/',
            '{{ $1 }}',
            $content
        );

        return $content;
    }

    private function convertControlStructures(string $content): string
    {
        $replacements = [
            '/<\?php\s+foreach\s*\((.+?)\)\s*:\s*\?>/' => '@foreach($1)',
            '/<\?php\s+endforeach\s*;\s*\?>/' => '@endforeach',
            '/<\?php\s+for\s*\((.+?)\)\s*:\s*\?>/' => '@for($1)',
            '/<\?php\s+endfor\s*;\s*\?>/' => '@endfor',
            '/<\?php\s+while\s*\((.+?)\)\s*:\s*\?>/' => '@while($1)',
            '/<\?php\s+endwhile\s*;\s*\?>/' => '@endwhile',
            '/<\?php\s+if\s*\((.+?)\)\s*:\s*\?>/' => '@if($1)',
            '/<\?php\s+elseif\s*\((.+?)\)\s*:\s*\?>/' => '@elseif($1)',
            '/<\?php\s+else\s*:\s*\?>/' => '@else',
            '/<\?php\s+endif\s*;\s*\?>/' => '@endif',
        ];

        return preg_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    private function convertHelpers(string $content): string
    {
        $helpers = [
            // $this->escape($var) => $var
            '/\$this->escape\((.+?)\)/' => '$1',
            '/\$this->escapeHtml\((.+?)\)/' => '$1',
            '/\$this->quoteEscape\((.+?)\)/' => 'addslashes($1)',

            // $this->url(array(...)) => route('...') - basic
            '/\$this->url\(\[([^\]]*)\]\)/' => function ($m) {
                return $this->convertUrlCall($m[1]);
            },

            // $this->baseUrl() => url('')
            '/\$this->baseUrl\(\)/' => "url('')",
            '/\$this->baseUrl\((.+?)\)/' => 'url($1)',

            // $this->serverUrl() => url('')
            '/\$this->serverUrl\(\)/' => "url('')",

            // $this->translate('text') => __('text')
            '/\$this->translate\([\'"]([^\'"]+)[\'"]\)/' => "__('$1')",

            // $this->__('text') => __('text')
            '/\$this->__\([\'"]([^\'"]+)[\'"]\)/' => "__('$1')",

            // $this->headTitle() => @section('title')
            '/\$this->headTitle\(\)/' => "@section('title')",
            '/\$this->headScript\(\)/' => "@push('scripts')",
            '/\$this->headLink\(\)/' => "@push('styles')",
            '/\$this->headMeta\(\)/' => '',
            '/\$this->doctype\(\)/' => '',

            // $this->partial($template) => @include($1)
            '/\$this->partial\((.+?)\)/' => '@include($1)',
            '/\$this->partialLoop\((.+?)\)/' => '@each($1)',

            // $this->action(...) => TODO
            '/\$this->action\((.+?)\)/' => '// @action($1) TODO',

            // $this->layout()
            '/\$this->layout\(\)/' => '',

            // $this->getChildHtml('name')
            '/\$this->getChildHtml\([\'"]([^\'"]+)[\'"]\)/' => "@include('$1') // TODO",
            '/\$this->getBlockHtml\([\'"]([^\'"]+)[\'"]\)/' => "// TODO: getBlockHtml '$1'",

            // $this->flashMessenger()
            '/\$this->flashMessenger\(\)/' => "session()->get('flash') // TODO",

            // $this->form*() => Zend_Form (complex, mark as TODO)
            '/\$this->form\w+\(/' => "{{-- TODO: Zend_Form --}}",
        ];

        foreach ($helpers as $pattern => $replacement) {
            if (is_callable($replacement)) {
                $content = preg_replace_callback($pattern, $replacement, $content);
            } else {
                $content = preg_replace($pattern, $replacement, $content);
            }
        }

        return $content;
    }

    private function convertTranslations(string $content): string
    {
        return preg_replace(
            '/\$this->translate\([\'"]([^\'"]+)[\'"]\)/',
            "__('$1')",
            $content
        );
    }

    private function convertUrlCall(string $params): string
    {
        // Try to extract controller/action from array
        if (preg_match("/'controller'\s*=>\s*'([^']+)'/", $params, $m)) {
            return "route('...') // TODO: map controller '{$m[1]}' action";
        }

        return "url('/') // TODO: convert ZF1 route to Laravel route";
    }

    private function cleanupPhpTags(string $content): string
    {
        $openTag = '<' . '?php';
        $closeTag = '?' . '>';

        $content = preg_replace('/' . preg_quote($openTag, '/') . '\s*' . preg_quote($closeTag, '/') . '/', '', $content);

        $content = str_replace($closeTag, '', $content);

        $pattern = '/' . preg_quote($openTag, '/') . '\s+(.+?)\s*' . preg_quote($closeTag, '/') . '/s';
        $content = preg_replace($pattern, '@php($1) @endphp', $content);

        // Fix any double spaces
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);

        return trim($content);
    }

    public function transpileAll(array $parsedViews, string $appName, string $moduleName): array
    {
        $results = [];

        foreach ($parsedViews as $parsed) {
            $bladeContent = $this->transpile($parsed);

            $outputPath = $this->getOutputPath($parsed, $appName, $moduleName);

            $results[] = [
                'source' => $parsed['file_path'],
                'destination' => $outputPath,
                'code' => $bladeContent,
            ];
        }

        return $results;
    }

    private function getOutputPath(array $parsed, string $appName, string $moduleName): string
    {
        $relative = $parsed['relative_path'] ?? '';
        $relative = preg_replace('/\.phtml$/', '.blade.php', $relative);

        return resource_path("views/{$appName}/{$moduleName}/{$relative}");
    }
}
