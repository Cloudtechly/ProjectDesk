<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaClient
{
    private const BASE_URL = 'http://127.0.0.1:11434';

    /** @return array{available: bool, models: list<string>, error: string|null} */
    public function health(): array
    {
        try {
            $response = Http::baseUrl(self::BASE_URL)->connectTimeout(2)->timeout(5)->get('/api/tags');
            if (! $response->successful()) {
                return ['available' => false, 'models' => [], 'error' => 'http_'.$response->status()];
            }
            $models = [];
            $rawModels = $response->json('models');
            if (is_array($rawModels)) {
                foreach ($rawModels as $rawModel) {
                    if (is_array($rawModel) && is_string($rawModel['name'] ?? null)) {
                        $models[] = $rawModel['name'];
                    }
                }
            }

            return [
                'available' => true,
                'models' => $models,
                'error' => null,
            ];
        } catch (ConnectionException $exception) {
            return ['available' => false, 'models' => [], 'error' => 'connection_refused'];
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function structured(string $model, string $system, string $content, array $schema, int $contextSize): array
    {
        $response = Http::baseUrl(self::BASE_URL)
            ->connectTimeout(5)->timeout(600)->retry(2, 1500, throw: false)
            ->post('/api/chat', [
                'model' => $model,
                'stream' => false,
                'think' => false,
                'keep_alive' => (string) config('local-ai.keep_alive', '5m'),
                'format' => $schema,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $content],
                ],
                'options' => ['num_ctx' => $contextSize, 'temperature' => 0.1, 'seed' => 42],
            ]);
        if (! $response->successful()) {
            throw new RuntimeException('Ollama request failed with HTTP '.$response->status().'.');
        }
        $json = json_decode((string) $response->json('message.content'), true);
        if (! is_array($json)) {
            throw new RuntimeException('Ollama returned invalid structured JSON.');
        }

        return $json;
    }
}
