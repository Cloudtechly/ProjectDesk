<?php

namespace App\Console\Commands;

use App\Services\ApplicationKeyEnsurer;
use Illuminate\Console\Command;
use Throwable;

class EnsureApplicationKey extends Command
{
    protected $signature = 'project-desk:ensure-app-key';

    protected $description = 'Generate APP_KEY only when the environment file does not already contain one';

    public function handle(ApplicationKeyEnsurer $ensurer): int
    {
        try {
            $generated = $ensurer->ensure(
                $this->laravel->environmentFilePath(),
                (string) config('app.cipher'),
            );
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($generated) {
            $this->components->info('Application key generated successfully.');
        } else {
            $this->components->info('Application key is already configured; it was left unchanged.');
        }

        return self::SUCCESS;
    }
}
