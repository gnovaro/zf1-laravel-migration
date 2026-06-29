<?php

namespace App\Console\Commands;

use App\Services\Zf1\Analyzer;
use App\Services\Zf1\InteractivePrompt;
use App\Services\Zf1\Transpiler\RouteTranspiler;
use Illuminate\Console\Command;

class Zf1MigrateRoutes extends Command
{
    protected $signature = 'zf1:migrate-routes
        {path : Path to the ZF1 project root (source)}
        {--target= : Path to the Laravel project where files will be written (default: this tool)}
        {--app= : Specific app to generate routes for}
        {--basic : Generate basic routes only (module/controller/action)}
        {--force : Write files without confirmation}';

    protected $description = 'Generate Laravel routes from ZF1 project structure';

    public function handle(
        Analyzer $analyzer,
        RouteTranspiler $transpiler,
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

        foreach ($apps as $appName => $app) {
            $prompt->newLine();
            $prompt->info("Generating routes for: {$appName}");

            $fullRouteFile = "{$targetPath}/routes/{$appName}.php";
            $basicRouteFile = "{$targetPath}/routes/{$appName}.basic.php";

            if ($this->option('basic')) {
                $routeCode = $transpiler->generateBasicRoutes($analysis, $appName);
                $routeFile = $fullRouteFile;

                if (!$this->option('force')) {
                    $prompt->showDiff('Routes', $routeCode);
                    if (!$prompt->confirm("  Write routes file?", true)) {
                        $prompt->warn("  Skipped");

                        continue;
                    }
                }

                $dir = dirname($routeFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }

                file_put_contents($routeFile, $routeCode);
                $prompt->line("  ✓ Routes written to: {$routeFile}");
            } else {
                $results = $transpiler->transpileAll($analysis, $appName, $fullRouteFile);

                foreach ($results as $result) {
                    if (!$this->option('force')) {
                        $prompt->showDiff("Routes ({$result['type']})", $result['code']);
                        if (!$prompt->confirm("  Write {$result['type']} routes file?", true)) {
                            $prompt->warn("  Skipped");

                            continue;
                        }
                    }

                    $dir = dirname($result['destination']);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }

                    file_put_contents($result['destination'], $result['code']);
                    $prompt->line("  ✓ Written to: {$result['destination']}");
                }
            }
        }

        $prompt->newLine();
        $prompt->info("Route generation complete.");

        return self::SUCCESS;
    }
}
