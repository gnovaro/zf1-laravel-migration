<?php

namespace App\Services\Zf1\Transpiler;

use App\Services\Zf1\Contracts\TranspilerInterface;

class ControllerTranspiler implements TranspilerInterface
{
    private const HELPER_MAP = [
        '_getParam' => "\$request->input('{param}')",
        '_getAllParams' => "\$request->all()",
        '_hasParam' => "\$request->has('{param}')",
        '_redirect' => "return redirect()->to('{url}')",
        '_forward' => "return \$this->{action}()",
        'getRequest' => "\$request",
        'isPost' => "\$request->isMethod('post')",
        'getPost' => "\$request->input('{param}')",
    ];

    public function transpile(array $parsedData): string
    {
        $namespace = $this->buildNamespace($parsedData);
        $uses = $this->buildUses($parsedData);
        $className = $parsedData['class'];
        $methods = $this->buildMethods($parsedData);

        $controllerName = str_replace('Controller', '', $className);

        return <<<PHP
<?php

namespace {$namespace};

use Illuminate\Http\Request;
{$uses}

class {$className} extends Controller
{
{$methods}
}
PHP;
    }

    private function buildNamespace(array $data): string
    {
        if (!empty($data['app_name'])) {
            return "App\\Http\\Controllers\\{$data['app_name']}\\{$data['module_name']}";
        }

        return "App\\Http\\Controllers";
    }

    private function buildUses(array $data): string
    {
        $uses = [];

        if (!empty($data['uses'])) {
            foreach ($data['uses'] as $use) {
                if (!str_contains($use, 'Zend_') && $use !== 'Illuminate\Http\Request') {
                    $uses[] = "use {$use};";
                }
            }
        }

        return implode("\n", $uses);
    }

    private function buildMethods(array $data): string
    {
        $methods = [];

        foreach ($data['methods'] as $actionName => $actionInfo) {
            if (in_array($actionName, ['init', 'preDispatch', 'postDispatch'])) {
                $method = $this->buildSpecialMethod($actionName, $actionInfo, $data);
            } else {
                $method = $this->buildActionMethod($actionName, $actionInfo, $data);
            }

            $methods[] = $method;
        }

        return implode("\n\n", $methods);
    }

    private function buildActionMethod(string $actionName, array $actionInfo, array $data): string
    {
        $camelAction = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $actionName))));

        $viewVars = '';
        if (!empty($actionInfo['view_assignments'])) {
            $viewVars = "\n        \$data = [];\n";
            foreach ($actionInfo['view_assignments'] as $assignment) {
                if (is_string($assignment)) {
                    $viewVars .= "        \$data['{$assignment}'] = \${$assignment} ?? null;\n";
                } elseif (is_array($assignment)) {
                    $var = $assignment['var'] ?? '?';
                    $value = $assignment['value'] ?? 'null';
                    $viewVars .= "        \$data['{$var}'] = {$value};\n";
                }
            }
        }

        $paramExtractions = '';
        if (!empty($actionInfo['params'])) {
            foreach (array_unique($actionInfo['params']) as $param) {
                $paramExtractions .= "        \${$param} = \$request->input('{$param}');\n";
            }
        }

        $helperExtractions = $this->buildHelperLogic($actionInfo);

        $redirectLogic = '';
        if (!empty($actionInfo['redirects'])) {
            $redirectLogic = "\n        return redirect()->to('{$actionInfo['redirects'][0]}');\n";
        }

        $forwardLogic = '';
        if (!empty($actionInfo['forwards'])) {
            $forwardLogic = "\n        // TODO: Handle forward to: {$actionInfo['forwards'][0]}\n";
        }

        $returnStatement = '';
        if (empty($redirectLogic) && empty($forwardLogic)) {
            $viewName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $actionName));
            $appName = strtolower($data['app_name'] ?? 'default');
            $moduleName = strtolower($data['module_name'] ?? 'default');
            $controllerName = $this->getControllerName($data['class']);
            $returnStatement = "\n        return view('{$appName}.{$moduleName}.{$controllerName}.{$viewName}', \$data ?? []);\n";
        }

        return <<<PHP
    public function {$camelAction}(Request \$request)
    {
{$paramExtractions}{$helperExtractions}{$viewVars}{$redirectLogic}{$forwardLogic}{$returnStatement}
    }
PHP;
    }

    private function buildSpecialMethod(string $name, array $actionInfo, array $data): string
    {
        $comment = match ($name) {
            'init' => '// TODO: init() was called during construction in ZF1. Consider moving to constructor or middleware.',
            'preDispatch' => '// TODO: preDispatch() ran before every action. Consider converting to middleware.',
            'postDispatch' => '// TODO: postDispatch() ran after every action. Consider converting to middleware.',
            default => '',
        };

        $paramExtractions = '';
        if (!empty($actionInfo['params'])) {
            foreach (array_unique($actionInfo['params']) as $param) {
                $paramExtractions .= "        \${$param} = \$request->input('{$param}');\n";
            }
        }

        return <<<PHP
    public function {$name}(Request \$request)
    {
        {$comment}
{$paramExtractions}
    }
PHP;
    }

    private function buildHelperLogic(array $actionInfo): string
    {
        $logic = '';
        $seen = [];

        foreach ($actionInfo['helpers'] ?? [] as $helper) {
            $key = "helper:{$helper}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $logic .= "        // TODO: ZF1 helper '{$helper}' - implement Laravel equivalent\n";
            }
        }

        foreach ($actionInfo['method_calls'] ?? [] as $call) {
            $key = "call:{$call}";
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $logic .= "        // TODO: ZF1 call '{$call}' - implement Laravel equivalent\n";
            }
        }

        return $logic;
    }

    private function getControllerName(string $className): string
    {
        return str_replace(['Controller', '_'], '', $className);
    }

    public function transpileAll(array $parsedControllers, string $appName, string $moduleName): array
    {
        $results = [];

        foreach ($parsedControllers as $parsed) {
            $parsed['app_name'] = $appName;
            $parsed['module_name'] = $moduleName;
            $code = $this->transpile($parsed);

            $outputPath = $this->getOutputPath($parsed, $appName);

            $results[] = [
                'source' => $parsed['file_path'],
                'destination' => $outputPath,
                'code' => $code,
                'class' => $parsed['class'],
            ];
        }

        return $results;
    }

    private function getOutputPath(array $parsed, string $appName): string
    {
        $moduleName = $parsed['module_name'] ?? 'Default';

        return app_path("Http/Controllers/{$appName}/{$moduleName}/{$parsed['class']}.php");
    }
}
