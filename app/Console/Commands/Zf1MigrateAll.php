<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use App\Services\Zf1\Parser\ControllerParser;
use App\Services\Zf1\Parser\ModelParser;
use App\Services\Zf1\Parser\ViewParser;
use App\Services\Zf1\Transpiler\ControllerTranspiler;
use App\Services\Zf1\Transpiler\ModelTranspiler;
use App\Services\Zf1\Transpiler\RouteTranspiler;
use App\Services\Zf1\Transpiler\ViewTranspiler;
use Illuminate\Console\Command;

class Zf1MigrateAll extends Command
{
    protected $signature = 'zf1:migrate-all
        {path : Path to the ZF1 project root (source)}
        {--target= : Path to the Laravel project where files will be written (required)}
        {--app= : Specific app to migrate}
        {--module= : Specific module to migrate}
        {--force : Write files without confirmation (if not set, each file is confirmed)}';

    protected $description = 'Run the full ZF1 to Laravel migration wizard';

    public function handle(
        Analyzer $analyzer,
        ModelParser $modelParser,
        ModelTranspiler $modelTranspiler,
        ControllerParser $controllerParser,
        ControllerTranspiler $controllerTranspiler,
        ViewParser $viewParser,
        ViewTranspiler $viewTranspiler,
        RouteTranspiler $routeTranspiler,
    ): int {
        $path = $this->argument('path');
        $prompt = new InteractivePrompt($this);
        $force = $this->option('force');

        $prompt->info('========================================');
        $prompt->info('   ZF1 → Laravel Migration Wizard');
        $prompt->info('========================================');
        $prompt->newLine();

        $targetPath = $this->option('target');
        if (!$targetPath) {
            $this->error('--target is required. Specify where to write the migrated Laravel files.');
            $this->line('Example: php artisan zf1:migrate-all /ruta/zf1 --target=/ruta/nuevo-laravel');

            return self::FAILURE;
        }
        $targetPath = rtrim($targetPath, '/');

        if (!is_dir($targetPath)) {
            $this->info("Creating target directory: {$targetPath}");
            mkdir($targetPath, 0755, true);
        }

        // Step 1: Analyze
        $prompt->info('Step 1: Analyzing ZF1 project...');

        try {
            $analysis = $analyzer->analyze($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $apps = $analysis['apps'];

        if ($appFilter = $this->option('app')) {
            if (!isset($apps[$appFilter])) {
                $this->error("App '{$appFilter}' not found");

                return self::FAILURE;
            }
            $apps = [$appFilter => $apps[$appFilter]];
        }

        // Show summary
        $prompt->newLine();
        $prompt->info('Analysis complete. Summary:');

        foreach ($apps as $appName => $app) {
            $totalC = 0;
            $totalM = 0;
            $totalV = 0;

            foreach ($app['modules'] as $module) {
                $totalC += count($module['controllers'] ?? []);
                $totalM += count($module['models'] ?? []);
                $totalV += count($module['views'] ?? []);
            }

            $prompt->line("  {$appName}: {$totalC} controllers, {$totalM} models, {$totalV} views");
        }

        $prompt->newLine();

        if (!$force && !$prompt->confirm('Continue with migration?', true)) {
            $prompt->warn('Migration cancelled.');

            return self::SUCCESS;
        }

        // Step 2: Migrate Models
        $prompt->newLine();
        $prompt->info('Step 2: Migrating Models (Zend_Db_Table → Eloquent)');

        foreach ($apps as $appName => $app) {
            foreach ($app['modules'] as $moduleName => $module) {
                if ($moduleName === '__app') {
                    continue;
                }

                if ($moduleFilter = $this->option('module')) {
                    if ($moduleName !== $moduleFilter) {
                        continue;
                    }
                }

                foreach ($module['models'] ?? [] as $model) {
                    $parsed = $modelParser->parse($model['path']);

                    if ($parsed === null || !$parsed['is_db_table']) {
                        continue;
                    }

                    $parsed['app_name'] = $appName;
                    $parsed['module_name'] = $moduleName;

                    if ($analysis['table_prefix'] && $parsed['table_name'] && !str_starts_with($parsed['table_name'], $analysis['table_prefix'])) {
                        $parsed['table_name'] = $analysis['table_prefix'] . $parsed['table_name'];
                    }

                    $code = $modelTranspiler->transpile($parsed);
                    $outputPath = "{$targetPath}/app/Models/{$appName}/{$moduleName}/{$parsed['class']}.php";

                    $prompt->info("  Model: {$parsed['class']} → {$parsed['table_name']}");

                    if (!$force) {
                        $prompt->showDiff('Model', $code);
                        if (!$prompt->confirm('Write model?', true)) {
                            $prompt->warn('    Skipped');

                            continue;
                        }
                    }

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($outputPath, $code);
                }
            }
        }

        // Step 3: Migrate Views
        $prompt->newLine();
        $prompt->info('Step 3: Migrating Views (.phtml → Blade)');

        foreach ($apps as $appName => $app) {
            foreach ($app['modules'] as $moduleName => $module) {
                if ($moduleName === '__app') {
                    continue;
                }

                if ($moduleFilter = $this->option('module')) {
                    if ($moduleName !== $moduleFilter) {
                        continue;
                    }
                }

                foreach ($module['views'] ?? [] as $view) {
                    $parsed = $viewParser->parse($view['path']);

                    if ($parsed === null) {
                        continue;
                    }

                    $bladeContent = $viewTranspiler->transpile($parsed);

                    $relative = $view['relative_path'] ?? basename($view['path']);
                    $bladeRelative = preg_replace('/\.phtml$/', '.blade.php', $relative);
                    $outputPath = "{$targetPath}/resources/views/{$appName}/{$moduleName}/{$bladeRelative}";

                    $prompt->info("  View: {$relative}");

                    if (!$force) {
                        $prompt->showDiff('Blade', $bladeContent);
                        if (!$prompt->confirm('Write view?', true)) {
                            $prompt->warn('    Skipped');

                            continue;
                        }
                    }

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($outputPath, $bladeContent);
                }
            }
        }

        // Step 4: Migrate Controllers
        $prompt->newLine();
        $prompt->info('Step 4: Migrating Controllers (Zend_Controller_Action → Laravel)');

        foreach ($apps as $appName => $app) {
            foreach ($app['modules'] as $moduleName => $module) {
                if ($moduleName === '__app') {
                    continue;
                }

                if ($moduleFilter = $this->option('module')) {
                    if ($moduleName !== $moduleFilter) {
                        continue;
                    }
                }

                foreach ($module['controllers'] ?? [] as $controller) {
                    $parsed = $controllerParser->parse($controller['path']);

                    if ($parsed === null) {
                        continue;
                    }

                    $parsed['app_name'] = $appName;
                    $parsed['module_name'] = $moduleName;

                    $code = $controllerTranspiler->transpile($parsed);
                    $outputPath = "{$targetPath}/app/Http/Controllers/{$appName}/{$moduleName}/{$parsed['class']}.php";

                    $actions = array_keys($parsed['methods']);
                    $actionList = implode(', ', array_filter($actions, fn ($a) => !in_array($a, ['init', 'preDispatch', 'postDispatch'])));
                    $prompt->info("  Controller: {$parsed['class']} ({$actionList})");

                    if (!$force) {
                        $prompt->showDiff('Controller', $code);
                        if (!$prompt->confirm('Write controller?', true)) {
                            $prompt->warn('    Skipped');

                            continue;
                        }
                    }

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    file_put_contents($outputPath, $code);
                }
            }
        }

        // Step 5: Routes
        $prompt->newLine();
        $prompt->info('Step 5: Generating Routes');

        foreach ($apps as $appName => $app) {
            $prompt->info("  App: {$appName}");
            $routeFile = "{$targetPath}/routes/{$appName}.php";

            $routeCode = $routeTranspiler->generateBasicRoutes($analysis, $appName);

            if (!$force) {
                $prompt->showDiff('Routes', $routeCode);
                if (!$prompt->confirm('Write routes?', true)) {
                    $prompt->warn('    Skipped');

                    continue;
                }
            }

            $dir = dirname($routeFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($routeFile, $routeCode);
        }

        // Summary
        $prompt->newLine(2);
        $prompt->info('========================================');
        $prompt->info('   Migration Complete!');
        $prompt->info('========================================');
        $prompt->newLine();
        $prompt->line('Next steps:');
        $prompt->line("  1. Review generated files in: {$targetPath}");
        $prompt->line('  2. Run php artisan route:clear');
        $prompt->line('  3. Load routes in bootstrap/app.php:');
        $prompt->newLine();

        foreach ($apps as $appName => $app) {
            $prompt->line("     require base_path('routes/{$appName}.php');");
        }
        $prompt->newLine();
        $prompt->line('     (run those commands from the target Laravel project, not this tool)');

        $prompt->newLine();
        $prompt->warn('Note: This is a starting point. Manual review and adjustments are expected.');

        return self::SUCCESS;
    }
}
