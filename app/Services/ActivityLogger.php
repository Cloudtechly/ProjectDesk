<?php

namespace App\Services;

use App\Models\FileObject;
use App\Models\Meeting;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\RequirementBookVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityLogger
{
    private const INSERT_CHUNK_SIZE = 50;

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(
        Model $subject,
        string $action,
        ?User $actor,
        array $before = [],
        array $after = [],
        ?Request $request = null,
    ): void {
        $this->recordMany([[
            'subject' => $subject,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]], $actor, $request);
    }

    /**
     * @param  list<array{
     *   subject: Model,
     *   action: string,
     *   before?: array<string, mixed>,
     *   after?: array<string, mixed>
     * }>  $entries
     */
    public function recordMany(array $entries, ?User $actor, ?Request $request = null): void
    {
        if ($entries === []) {
            return;
        }

        $requestId = $this->requestIdentifier($request, 'project_desk.request_id', 'X-Request-Id')
            ?? (string) Str::uuid();
        $correlationId = $this->requestIdentifier($request, 'project_desk.correlation_id', 'X-Correlation-Id')
            ?? $requestId;
        $createdAt = now();
        $rows = [];
        foreach ($entries as $entry) {
            $subject = $entry['subject'];
            $before = $entry['before'] ?? [];
            $after = $entry['after'] ?? [];
            $rows[] = [
                'actor_id' => $actor?->id,
                'project_id' => $this->projectId($subject),
                'action' => $entry['action'],
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'before' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
                'after' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'created_at' => $createdAt,
            ];
        }

        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }
    }

    private function projectId(Model $subject): ?int
    {
        if ($subject instanceof Project) {
            return (int) $subject->getKey();
        }

        $projectId = $subject->getAttribute('project_id');
        if (is_numeric($projectId)) {
            return (int) $projectId;
        }

        if ($subject instanceof FileObject) {
            $linkedProject = $subject->attachmentLinks()->value('project_id');

            return is_numeric($linkedProject) ? (int) $linkedProject : null;
        }

        if ($subject instanceof Meeting) {
            $linkedProject = $subject->timelineEntry()->value('project_id');

            return is_numeric($linkedProject) ? (int) $linkedProject : null;
        }

        if ($subject instanceof MeetingMinutes) {
            $linkedProject = $subject->meeting()
                ->join('timeline_entries', 'timeline_entries.id', '=', 'meetings.timeline_entry_id')
                ->value('timeline_entries.project_id');

            return is_numeric($linkedProject) ? (int) $linkedProject : null;
        }

        if ($subject instanceof RequirementBookVersion) {
            $linkedProject = $subject->requirementBook()->value('project_id');

            return is_numeric($linkedProject) ? (int) $linkedProject : null;
        }

        return null;
    }

    private function requestIdentifier(?Request $request, string $attribute, string $header): ?string
    {
        if ($request === null) {
            return null;
        }

        $value = $request->attributes->get($attribute) ?? $request->header($header);

        return is_string($value)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/', $value) === 1
                ? $value
                : null;
    }
}
