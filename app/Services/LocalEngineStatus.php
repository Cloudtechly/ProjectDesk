<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

class LocalEngineStatus
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly LocalDocumentExtractor $extractor,
        private readonly LocalAiSettings $settings,
    ) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $ollama = $this->ollama->health();

        return [
            'enabled' => $this->settings->enabled(),
            'ollama' => $ollama,
            'configured_model' => $this->settings->model(),
            'model_installed' => in_array($this->settings->model(), $ollama['models'], true),
            'gpu' => $this->gpu(),
            'extractors' => $this->extractor->dependencyStatus(),
            'privacy' => ['endpoint' => '127.0.0.1:11434', 'cloud_enabled' => false],
        ];
    }

    /** @return array{available: bool, name?: string, memory_total_mb?: int|null, memory_free_mb?: int|null} */
    private function gpu(): array
    {
        try {
            $result = Process::timeout(10)->run(['nvidia-smi', '--query-gpu=name,memory.total,memory.free', '--format=csv,noheader,nounits']);
            if (! $result->successful()) {
                return ['available' => false];
            }
            $parts = array_map('trim', explode(',', trim($result->output())));

            return ['available' => true, 'name' => $parts[0], 'memory_total_mb' => isset($parts[1]) ? (int) $parts[1] : null, 'memory_free_mb' => isset($parts[2]) ? (int) $parts[2] : null];
        } catch (\Throwable) {
            return ['available' => false];
        }
    }
}
