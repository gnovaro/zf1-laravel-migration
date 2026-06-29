<?php

namespace App\Services\Zf1\Transpiler;

use App\Services\Zf1\Contracts\TranspilerInterface;

class RouteTranspiler implements TranspilerInterface
{
    public function transpile(array $parsedData): string
    {
        return ''; // Not used directly
    }

    public function generateRoutes(array $analysis, string $appName): string
    {
        if (!isset($analysis['apps'][$appName])) {
            return "<?php\n\n// No routes found for app: {$appName}\n";
        }

        $app = $analysis['apps'][$appName];
        $routeContent = "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n";
        $routeContent .= "/*\n * {$appName} Routes\n * Auto-generated from ZF1 project\n */\n\n";

        $appNameLower = strtolower($appName);

        foreach ($app['modules'] as $moduleName => $module) {
            if ($moduleName === '__app') {
                continue;
            }

            $prefix = "{$appNameLower}/{$moduleName}";
            $routeContent .= "Route::prefix('{$prefix}')->name('{$appNameLower}.{$moduleName}.')->group(function () {\n";

            foreach ($module['controllers'] as $controller) {
                $parsed = $this->parseSourceForActions($controller['path']);
                if ($parsed === null) {
                    continue;
                }

                $controllerClass = $parsed['class'];
                $controllerName = str_replace('Controller', '', $controllerClass);
                $controllerKebab = $this->toKebabCase($controllerName);
                $laravelController = "App\\Http\\Controllers\\{$appName}\\{$moduleName}\\{$controllerClass}";

                foreach ($parsed['actions'] as $action => $methods) {
                    $httpMethods = !empty($methods) ? implode('|', $methods) : 'GET';
                    $actionKebab = $this->toKebabCase($action);

                    $routeContent .= "    Route::match(['{$httpMethods}'], '{$controllerKebab}/{$actionKebab}', [{$laravelController}::class, '{$action}'])->name('{$controllerKebab}.{$actionKebab}');\n";
                }
            }

            $routeContent .= "});\n\n";
        }

        return $routeContent;
    }

    public function generateBasicRoutes(array $analysis, string $appName): string
    {
        if (!isset($analysis['apps'][$appName])) {
            return "<?php\n\n// No routes found for app: {$appName}\n";
        }

        $app = $analysis['apps'][$appName];
        $appNameLower = strtolower($appName);

        $routeContent = "<?php\n\nuse Illuminate\Support\Facades\Route;\n\n";
        $routeContent .= "/*\n * {$appName} Routes (basic mapping)\n * Uses default ZF1 module/controller/action pattern\n */\n\n";

        foreach ($app['modules'] as $moduleName => $module) {
            $prefix = "{$appNameLower}/{$moduleName}";
            $routeContent .= "Route::prefix('{$prefix}')->group(function () {\n";

            foreach ($module['controllers'] as $controller) {
                $parsed = $this->parseSourceForActions($controller['path']);
                if ($parsed === null) {
                    continue;
                }

                $controllerClass = $parsed['class'];
                $controllerName = str_replace('Controller', '', $controllerClass);
                $controllerKebab = $this->toKebabCase($controllerName);
                $laravelController = "App\\Http\\Controllers\\{$appName}\\{$moduleName}\\{$controllerClass}";

                // Default index
                $routeContent .= "    Route::get('{$controllerKebab}', [{$laravelController}::class, 'index'])->name('{$appNameLower}.{$moduleName}.{$controllerKebab}.index');\n";

                foreach ($parsed['actions'] as $action => $methods) {
                    if ($action === 'index') {
                        continue;
                    }
                    $actionKebab = $this->toKebabCase($action);
                    $routeContent .= "    Route::match(['GET', 'POST'], '{$controllerKebab}/{$actionKebab}', [{$laravelController}::class, '{$action}'])->name('{$appNameLower}.{$moduleName}.{$controllerKebab}.{$actionKebab}');\n";
                }
            }

            $routeContent .= "});\n\n";
        }

        return $routeContent;
    }

    private function parseSourceForActions(string $filePath): ?array
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);

        $className = null;
        if (preg_match('/^class\s+(\w+)/m', $content, $matches)) {
            $className = $matches[1];
        }

        $actions = [];
        preg_match_all('/function\s+(\w+)Action\s*\(/', $content, $matches);

        foreach ($matches[1] as $action) {
            $methods = [];

            if (preg_match('/isPost\(\)/', $content)) {
                $methods[] = 'POST';
            }

            if (preg_match('/' . preg_quote($action) . 'Action.*?\$this->getRequest/', $content)) {
                $methods[] = 'GET';
            }

            if (empty($methods)) {
                $methods[] = 'GET';
                $methods[] = 'POST';
            }

            $actions[$action] = array_unique($methods);
        }

        return [
            'class' => $className,
            'actions' => $actions,
        ];
    }

    private function toKebabCase(string $string): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $string));
    }

    public function transpileAll(array $analysis, string $appName, string $routeFilePath): array
    {
        $fullRoutes = $this->generateRoutes($analysis, $appName);
        $basicRoutes = $this->generateBasicRoutes($analysis, $appName);

        return [
            [
                'type' => 'full',
                'destination' => $routeFilePath,
                'code' => $fullRoutes,
            ],
            [
                'type' => 'basic',
                'destination' => str_replace('.php', '.basic.php', $routeFilePath),
                'code' => $basicRoutes,
            ],
        ];
    }
}
