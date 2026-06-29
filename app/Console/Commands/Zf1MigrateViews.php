<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use App\Services\Zf1\Parser\ViewParser;
use App\Services\Zf1\Transpiler\ViewTranspiler;
use Illuminate\Console\Command;

class Zf1MigrateViews extends Command
{
    protected $signature = 'zf1:migrate-views
        {path : Path to the ZF1 project root (source)}
        {--target= : Path to the Laravel project where files will be written (default: this tool)}
        {--app= : Specific app to migrate}
        {--module= : Specific module to migrate}
        {--force : Write files without confirmation}';

    protected $description = 'Migrate ZF1 .phtml views to Blade templates';

    public function handle(
        Analyzer $analyzer,
        ViewParser $parser,
        ViewTranspiler $transpiler,
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

                $views = $module['views'] ?? [];

                if (empty($views)) {
                    continue;
                }

                $prompt->newLine();
                $prompt->info("Processing views: {$appName}/{$moduleName} (" . count($views) . " found)");

                $bar = $prompt->progressBar(count($views));

                foreach ($views as $view) {
                    $parsed = $parser->parse($view['path']);

                    if ($parsed === null) {
                        $bar->advance();

                        continue;
                    }

                    $bladeContent = $transpiler->transpile($parsed);

                    $relative = $view['relative_path'] ?? basename($view['path']);
                    $bladeRelative = preg_replace('/\.phtml$/', '.blade.php', $relative);
                    $outputPath = "{$targetPath}/resources/views/{$appName}/{$moduleName}/{$bladeRelative}";

                    if (!$this->option('force')) {
                        $prompt->showDiff("Converting: {$relative}", $bladeContent);

                        if (!$prompt->confirm("  Write this view?", true)) {
                            $prompt->warn("  Skipped");
                            $bar->advance();

                            continue;
                        }
                    }

                    $dir = dirname($outputPath);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($outputPath, $bladeContent);
                    $migratedCount++;
                    $bar->advance();
                }

                $bar->finish();
                $prompt->newLine();
            }
        }

        $prompt->newLine();
        $prompt->info("Migrated {$migratedCount} views.");

        return self::SUCCESS;
    }
}
