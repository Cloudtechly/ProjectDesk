<?php

namespace App\Http\Requests;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

abstract class ProjectResourceRequest extends FormRequest
{
    protected function routeProject(): ?Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : null;
    }

    protected function isActiveProjectMember(Project $project, int $userId): bool
    {
        $isActiveUser = User::query()
            ->whereKey($userId)
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->exists();

        if (! $isActiveUser) {
            return false;
        }

        return $project->manager_id === $userId
            || $project->members()
                ->whereKey($userId)
                ->wherePivot('status', 'active')
                ->exists();
    }

    /** @param array<int, string> $fields */
    protected function normalizeBusinessDates(array $fields): void
    {
        $timezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');
        $normalized = [];

        foreach ($fields as $field) {
            $value = $this->input($field);
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                $normalized[$field] = CarbonImmutable::parse($value, $timezone)
                    ->utc()
                    ->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // Leave malformed input intact so Laravel's date rule can report it.
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
