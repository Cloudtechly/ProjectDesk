<?php

namespace App\Providers;

use App\Contracts\MalwareScanner;
use App\Models\Project;
use App\Security\CommandMalwareScanner;
use App\Security\NullMalwareScanner;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MalwareScanner::class, function ($app): MalwareScanner {
            $driver = (string) config('project-desk.uploads.malware_scanner.driver', 'none');

            if ($driver === 'command') {
                $arguments = config('project-desk.uploads.malware_scanner.arguments', ['--no-summary']);

                return new CommandMalwareScanner(
                    (string) config('project-desk.uploads.malware_scanner.executable', ''),
                    is_array($arguments) ? array_values(array_map('strval', $arguments)) : [],
                    (int) config('project-desk.uploads.malware_scanner.timeout_seconds', 30),
                );
            }

            if ($driver === 'callback') {
                $callback = (string) config('project-desk.uploads.malware_scanner.callback', '');
                $scanner = $callback === '' ? null : $app->make($callback);
                if (! $scanner instanceof MalwareScanner) {
                    throw new LogicException('The configured malware scanner callback must implement MalwareScanner.');
                }

                return $scanner;
            }

            return new NullMalwareScanner;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );

        RateLimiter::for('project-files', function (Request $request): Limit {
            $routeProject = $request->route('project');
            $project = $routeProject instanceof Project
                ? $routeProject
                : (is_numeric($routeProject) ? Project::query()->find((int) $routeProject) : null);
            $projectId = $project instanceof Project ? $project->id : (string) $routeProject;

            return Limit::perMinute((int) config('project-desk.uploads.rate_limit_per_minute', 20))
                ->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$projectId)
                ->response(function (Request $request, array $headers) use ($project) {
                    if ($project instanceof Project) {
                        app(ActivityLogger::class)->record(
                            $project,
                            'project_file.upload_rejected_rate_limit',
                            $request->user(),
                            after: ['reason' => 'rate_limit'],
                            request: $request,
                        );
                    }

                    return response()->json(['message' => 'تم تجاوز معدل رفع الملفات المسموح.'], 429, $headers);
                });

        });

        RateLimiter::for('backup-restore', fn (Request $request): Limit => Limit::perMinutes(
            10,
            (int) config('project-desk.data_center.restore_attempts_per_ten_minutes', 3),
        )->by(($request->user()?->getAuthIdentifier() ?? $request->ip()).'|'.$request->ip()));
    }
}
