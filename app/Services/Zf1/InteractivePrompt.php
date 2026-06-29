<?php

namespace App\Services\Zf1;

use Illuminate\Console\Command;

class InteractivePrompt
{
    public function __construct(
        private readonly Command $command,
    ) {}

    public function confirm(string $question, bool $default = true): bool
    {
        return $this->command->confirm($question, $default);
    }

    public function info(string $message): void
    {
        $this->command->info($message);
    }

    public function warn(string $message): void
    {
        $this->command->warn($message);
    }

    public function error(string $message): void
    {
        $this->command->error($message);
    }

    public function line(string $message): void
    {
        $this->command->line($message);
    }

    public function table(array $headers, array $rows): void
    {
        $this->command->table($headers, $rows);
    }

    public function choice(string $question, array $options, string $default = null): string
    {
        return $this->command->choice($question, $options, $default);
    }

    public function ask(string $question, string $default = null): string
    {
        return $this->command->ask($question, $default);
    }

    public function newLine(int $count = 1): void
    {
        $this->command->newLine($count);
    }

    public function showDiff(string $title, string $content): void
    {
        $this->newLine();
        $this->info("--- $title ---");
        $this->line($content);
        $this->newLine();
    }

    public function progressBar(int $total): \Symfony\Component\Console\Helper\ProgressBar
    {
        return $this->command->getOutput()->createProgressBar($total);
    }
}
