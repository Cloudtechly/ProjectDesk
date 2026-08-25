<?php

namespace App\Services;

class LocalAiSettings
{
    public function __construct(private readonly SystemSettingsService $settings) {}

    /** @return array{enabled: bool, auto_analyze: bool, model: string, context_size: int, max_pages: int} */
    public function all(): array
    {
        $values = $this->settings->group('local_ai');

        return [
            'enabled' => (bool) ($values['enabled'] ?? false),
            'auto_analyze' => (bool) ($values['auto_analyze'] ?? false),
            'model' => (string) ($values['model'] ?? config('local-ai.default_model')),
            'context_size' => (int) ($values['context_size'] ?? config('local-ai.context_size')),
            'max_pages' => (int) ($values['max_pages'] ?? config('local-ai.max_pages')),
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->all()['enabled'];
    }

    public function autoAnalyze(): bool
    {
        return (bool) $this->all()['auto_analyze'];
    }

    public function model(): string
    {
        return (string) $this->all()['model'];
    }

    public function contextSize(): int
    {
        return (int) $this->all()['context_size'];
    }

    public function maxPages(): int
    {
        return min((int) $this->all()['max_pages'], (int) config('local-ai.max_pages', 300));
    }
}
