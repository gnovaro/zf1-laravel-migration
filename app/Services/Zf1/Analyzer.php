<?php

namespace App\Services\Zf1;

use Illuminate\Support\Facades\File;

class Analyzer
{
    private const ZF1_APPS_DIR = 'application';
    private const CONTROLLERS_DIR = 'controllers';
    private const MODELS_DIR = 'models';
    private const VIEWS_DIR = 'views/scripts';
    private const MODULES_DIR = 'modules';

    public function analyze(string $path): array
    {
        $zf1Path = rtrim($path, '/');

        if (!is_dir($zf1Path)) {
            throw new \InvalidArgumentException("Path does not exist: $zf1Path");
        }

        $apps = [];
        $appsDir = "$zf1Path/" . self::ZF1_APPS_DIR;

        if (!is_dir($appsDir)) {
            throw new \InvalidArgumentException("No 'application/' directory found in: $zf1Path");
        }

        $appEntries = File::directories($appsDir);

        foreach ($appEntries as $appDir) {
            $appName = basename($appDir);

            // Skip standard ZF1 config dirs
            if (in_array($appName, ['configs', 'layouts', 'local.xml', 'Bootstrap.php'])) {
                continue;
            }

            $modulesDir = "$appDir/" . self::MODULES_DIR;
            $modules = [];

            if (is_dir($modulesDir)) {
                $moduleEntries = File::directories($modulesDir);

                foreach ($moduleEntries as $moduleDir) {
                    $moduleName = basename($moduleDir);
                    $modules[$moduleName] = $this->analyzeModule($moduleDir);
                }
            }

            // Check for app-level controllers (no modules)
            $appControllersDir = "$appDir/" . self::CONTROLLERS_DIR;
            if (is_dir($appControllersDir)) {
                $modules['__app'] = $this->analyzeControllersInDir($appControllersDir);
                $modules['__app']['models'] = $this->findModels("$appDir/" . self::MODELS_DIR);
                $modules['__app']['views'] = $this->findViews("$appDir/" . self::VIEWS_DIR);
            }

            $apps[$appName] = [
                'name' => $appName,
                'path' => $appDir,
                'modules' => $modules,
            ];
        }

        return [
            'project_path' => $zf1Path,
            'table_prefix' => $this->detectTablePrefix($zf1Path),
            'apps' => $apps,
        ];
    }

    private function analyzeModule(string $moduleDir): array
    {
        $moduleName = basename($moduleDir);

        $controllers = $this->analyzeControllersInDir("$moduleDir/" . self::CONTROLLERS_DIR);
        $models = $this->findModels("$moduleDir/" . self::MODELS_DIR);
        $views = $this->findViews("$moduleDir/" . self::VIEWS_DIR);

        return [
            'name' => $moduleName,
            'path' => $moduleDir,
            'controllers' => $controllers,
            'models' => $models,
            'views' => $views,
        ];
    }

    private function analyzeControllersInDir(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $controllers = [];
        $files = File::files($dir);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $controllers[] = [
                'path' => $file->getPathname(),
                'filename' => $file->getFilename(),
                'class' => $this->guessClassName($file->getPathname()),
            ];
        }

        return $controllers;
    }

    private function findModels(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $models = [];
        $this->scanForModels($dir, $models);

        return $models;
    }

    private function scanForModels(string $dir, array &$models): void
    {
        $items = File::files($dir);

        foreach ($items as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $models[] = [
                'path' => $file->getPathname(),
                'filename' => $file->getFilename(),
            ];
        }

        foreach (File::directories($dir) as $subDir) {
            $this->scanForModels($subDir, $models);
        }
    }

    private function findViews(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $views = [];
        $this->scanForViews($dir, $views);

        return $views;
    }

    private function scanForViews(string $dir, array &$views): void
    {
        $items = File::allFiles($dir);

        foreach ($items as $file) {
            if ($file->getExtension() !== 'phtml') {
                continue;
            }

            $views[] = [
                'path' => $file->getPathname(),
                'relative_path' => $file->getRelativePathname(),
            ];
        }
    }

    private function guessClassName(string $filePath): string
    {
        $content = File::get($filePath);

        if (preg_match('/^class\s+(\w+)/m', $content, $matches)) {
            return $matches[1];
        }

        return pathinfo($filePath, PATHINFO_FILENAME);
    }

    private function detectTablePrefix(string $projectPath): string
    {
        $modelsDirs = [
            "$projectPath/application/gps/modules",
            "$projectPath/application/clinosweb/modules",
            "$projectPath/application/corazon/modules",
        ];

        $prefixes = [];

        foreach ($modelsDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = File::allFiles($dir);

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = File::get($file->getPathname());

                if (preg_match('/protected\s+\$_name\s*=\s*[\'"]([a-zA-Z0-9_]+?)(?:_|$)/', $content, $matches)) {
                    $parts = explode('_', $matches[1]);
                    if (count($parts) > 1) {
                        $prefixes[] = $parts[0];
                    }
                }
            }
        }

        $counts = array_count_values($prefixes);
        arsort($counts);

        return $counts ? (string) array_key_first($counts) . '_' : '';
    }
}
