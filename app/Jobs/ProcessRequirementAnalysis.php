<?php

namespace App\Jobs;

use App\Models\RequirementAnalysisRun;
use App\Services\OllamaClient;
use App\Services\RequirementAnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessRequirementAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 7200;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('local-ai');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('ollama-requirements'))->releaseAfter(60)->expireAfter(7300)->shared()];
    }

    public function handle(OllamaClient $ollama, RequirementAnalysisPipeline $pipeline): void
    {
        $run = RequirementAnalysisRun::query()->findOrFail($this->runId);
        if ($run->cancel_requested) {
            $run->update(['status' => 'cancelled', 'finished_at' => now()]);

            return;
        }
        if (! in_array($run->status, ['queued', 'waiting_for_engine', 'extracting', 'analyzing', 'merging'], true)) {
            return;
        }
        $health = $ollama->health();
        if (! $health['available'] || ! in_array($run->model, $health['models'], true)) {
            $run->update([
                'status' => 'waiting_for_engine',
                'error_code' => ! $health['available'] ? 'ollama_unavailable' : 'model_missing',
                'error_message' => ! $health['available'] ? 'Ollama is not running.' : 'The configured model is not installed.',
            ]);
            self::dispatch($run->id)->delay(now()->addMinute());

            return;
        }
        $run->increment('attempt_count');
        $pipeline->execute($run);
    }

    public function failed(?Throwable $exception): void
    {
        RequirementAnalysisRun::query()->whereKey($this->runId)->update([
            'status' => 'failed', 'error_code' => 'analysis_failed',
            'error_message' => mb_substr((string) $exception?->getMessage(), 0, 2000), 'finished_at' => now(),
        ]);
    }
}
