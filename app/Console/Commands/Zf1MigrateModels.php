<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use App\Services\Zf1\Parser\ModelParser;
use App\Services\Zf1\Transpiler\ModelTranspiler;
use Illuminate\Console\Command;

class Zf1MigrateModels extends Command
{
    protected $signature = 'zf1:migrate-models
        {path : Path to the ZF1 project root (source)}
        {--target= : Path to the Laravel project where files will be written (default: this tool)}
        {--app= : Specific app to migrate (gps, clinosweb, corazon)}
        {--module= : Specific module to migrate}
        {--force : Write files without confirmation}';

    protected $description = 'Migrate ZF1 models (Zend_Db_Table_Abstract) to Eloquent models';

    public function handle(
        Analyzer $analyzer,
        ModelParser $parser,
        ModelTranspiler $transpiler,
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
        $tablePrefix = $analysis['table_prefix'];
        $targetPath = $this->option('target') ? rtrim($this->option('target'), '/') : base_path();

        if ($appFilter = $this->option('app')) {
            if (!isset($apps[$appFilter])) {
                $this->error("App '{$appFilter}' not found in project");

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

                $models = $module['models'] ?? [];

                if (empty($models)) {
                    continue;
                }

                $prompt->newLine();
                $prompt->info("Processing models: {$appName}/{$moduleName} (" . count($models) . " found)");

                foreach ($models as $model) {
                    $parsed = $parser->parse($model['path']);

                    if ($parsed === null || !$parsed['is_db_table']) {
                        continue;
                    }

                    if ($parsed['table_name'] && $tablePrefix && !str_starts_with($parsed['table_name'], $tablePrefix)) {
                        $parsed['table_name'] = $tablePrefix . $parsed['table_name'];
                    }

                    $parsed['app_name'] = $appName;
                    $parsed['module_name'] = $moduleName;

                    $modelCode = $transpiler->transpile($parsed);

                    $prompt->newLine();
                    $prompt->info("  Model: {$parsed['class']}");
                    $prompt->line("  Table: {$parsed['table_name']}");

                    if (!$this->option('force')) {
                        $prompt->showDiff('Generated Model', $modelCode);

                        if (!$prompt->confirm("  Write this model?", true)) {
                            $prompt->warn("  Skipped");

                            continue;
                        }
                    }

                    $outputPath = "{$targetPath}/app/Models/{$appName}/{$moduleName}/{$parsed['class']}.php";

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($outputPath, $modelCode);
                    $prompt->line("  ✓ Written to: {$outputPath}");
                    $migratedCount++;
                }
            }
        }

        $prompt->newLine();
        $prompt->info("Migrated {$migratedCount} models.");

        return self::SUCCESS;
    }
}
