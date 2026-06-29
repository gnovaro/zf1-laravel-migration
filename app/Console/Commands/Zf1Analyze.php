<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use Illuminate\Console\Command;

class Zf1Analyze extends Command
{
    protected $signature = 'zf1:analyze
        {path : Path to the ZF1 project root}
        {--json : Output analysis as JSON}
        {--output= : Save analysis to file}';

    protected $description = 'Analyze a Zend Framework 1 project structure';

    public function handle(Analyzer $analyzer): int
    {
        $path = $this->argument('path');

        $this->info("Analyzing ZF1 project at: {$path}");

        try {
            $analysis = $analyzer->analyze($path);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $json = json_encode($analysis, JSON_PRETTY_PRINT);

            if ($output = $this->option('output')) {
                file_put_contents($output, $json);
                $this->info("Analysis saved to: {$output}");
            } else {
                $this->line($json);
            }

            return self::SUCCESS;
        }

        $prompt = new InteractivePrompt($this);
        $this->displayAnalysis($analysis, $prompt);

        if ($output = $this->option('output')) {
            file_put_contents($output, json_encode($analysis, JSON_PRETTY_PRINT));
            $this->info("Analysis saved to: {$output}");
        }

        return self::SUCCESS;
    }

    private function displayAnalysis(array $analysis, InteractivePrompt $prompt): void
    {
        $prompt->newLine();
        $prompt->info('========================================');
        $prompt->info('   ZF1 Project Analysis Report');
        $prompt->info('========================================');
        $prompt->newLine();

        $prompt->line("Project Path: {$analysis['project_path']}");
        $prompt->line("Table Prefix: " . ($analysis['table_prefix'] ?: '(none detected)'));

        $prompt->newLine();
        $prompt->info('Apps Found: ' . count($analysis['apps']));

        foreach ($analysis['apps'] as $appName => $app) {
            $prompt->newLine();
            $prompt->info("  ┌─ App: {$appName}");

            $totalControllers = 0;
            $totalModels = 0;
            $totalViews = 0;

            foreach ($app['modules'] as $moduleName => $module) {
                $cCount = count($module['controllers'] ?? []);
                $mCount = count($module['models'] ?? []);
                $vCount = count($module['views'] ?? []);
                $totalControllers += $cCount;
                $totalModels += $mCount;
                $totalViews += $vCount;

                $prompt->line("    ├─ Module: {$moduleName}");
                $prompt->line("    │   Controllers: {$cCount} | Models: {$mCount} | Views: {$vCount}");
            }

            $prompt->line("    └─ Totals: {$totalControllers} controllers, {$totalModels} models, {$totalViews} views");
        }

        $prompt->newLine();
        $prompt->info('========================================');
    }
}
