<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use App\Services\Zf1\Parser\ControllerParser;
use App\Services\Zf1\Transpiler\ControllerTranspiler;
use Illuminate\Console\Command;

class Zf1MigrateControllers extends Command
{
    protected $signature = 'zf1:migrate-controllers
        {path : Path to the ZF1 project root (source)}
        {--target= : Path to the Laravel project where files will be written (default: this tool)}
        {--app= : Specific app to migrate}
        {--module= : Specific module to migrate}
        {--force : Write files without confirmation}';

    protected $description = 'Migrate ZF1 controllers to Laravel controllers';

    public function handle(
        Analyzer $analyzer,
        ControllerParser $parser,
        ControllerTranspiler $transpiler,
    ): int {
        $path = $this->argument('path');
        $prompt = new InteractivePrompt($this);

        try {
            $analysis = $analyzer->analyze($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $apps = $analysis['apps'];
        $targetPath = $this->option('target') ? rtrim($this->option('target'), '/') : base_path();

        if ($appFilter = $this->option('app')) {
            if (!isset($apps[$appFilter])) {
                $this->error("App '{$appFilter}' not found");

                return self::FAILURE;
            }
            $apps = [$appFilter => $apps[$appFilter]];
        }

        $migratedCount = 0;

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

                $controllers = $module['controllers'] ?? [];

                if (empty($controllers)) {
                    continue;
                }

                $prompt->newLine();
                $prompt->info("Processing controllers: {$appName}/{$moduleName} (" . count($controllers) . " found)");

                foreach ($controllers as $controller) {
                    $parsed = $parser->parse($controller['path']);

                    if ($parsed === null) {
                        $prompt->warn("  Could not parse: {$controller['filename']}");

                        continue;
                    }

                    $parsed['app_name'] = $appName;
                    $parsed['module_name'] = $moduleName;

                    $controllerCode = $transpiler->transpile($parsed);

                    $actions = array_keys($parsed['methods']);
                    $actionList = implode(', ', array_filter($actions, fn ($a) => !in_array($a, ['init', 'preDispatch', 'postDispatch'])));

                    $prompt->newLine();
                    $prompt->info("  Controller: {$parsed['class']}");
                    $prompt->line("  Actions: {$actionList}");

                    if (!$this->option('force')) {
                        $prompt->showDiff('Generated Controller', $controllerCode);

                        if (!$prompt->confirm("  Write this controller?", true)) {
                            $prompt->warn("  Skipped");

                            continue;
                        }
                    }

                    $outputPath = "{$targetPath}/app/Http/Controllers/{$appName}/{$moduleName}/{$parsed['class']}.php";

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($outputPath, $controllerCode);
                    $prompt->line("  ✓ Written to: {$outputPath}");
                    $migratedCount++;
                }
            }
        }

        $prompt->newLine();
        $prompt->info("Migrated {$migratedCount} controllers.");

        return self::SUCCESS;
    }
}
