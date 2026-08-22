<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DataJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CsvExportService
{
    public function __construct(
        private readonly CsvDataService $csv,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function template(string $resource): StreamedResponse
    {
        return $this->stream(
            "project-desk-{$resource}-template.csv",
            $this->csv->headers($resource),
            static function (): Generator {
                yield from [];
            },
        );
    }

    public function export(string $resource, User $actor): StreamedResponse
    {
        $count = match ($resource) {
            'clients' => Client::query()->whereNull('archived_at')->count(),
            'projects' => Project::query()->whereNull('archived_at')->count(),
            'tasks' => Task::query()->whereNull('archived_at')->count(),
            default => 0,
        };
        $job = DataJob::query()->create([
            'type' => 'export',
            'resource_type' => $resource,
            'format' => 'csv',
            'status' => 'processing',
            'created_by' => $actor->id,
            'summary' => ['row_count' => $count],
            'started_at' => now(),
        ]);

        return $this->stream(
            'project-desk-'.$resource.'-'.now()->format('Ymd-His').'.csv',
            $this->csv->headers($resource),
            fn (): Generator => $this->rows($resource),
            $job,
            $actor,
        );
    }

    /** @return Generator<int, list<string>> */
    private function rows(string $resource): Generator
    {
        $timezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');

        if ($resource === 'clients') {
            foreach (Client::query()->whereNull('archived_at')->orderBy('code')->cursor() as $client) {
                yield $this->safeRow([
                    $client->code,
                    $client->name,
                    (string) ($client->email ?? ''),
                    (string) ($client->phone ?? ''),
                    (string) ($client->address ?? ''),
                    $client->status,
                ]);
            }

            return;
        }

        if ($resource === 'projects') {
            $query = Project::query()
                ->whereNull('archived_at')
                ->with(['client:id,code', 'manager:id,email', 'status:id,code'])
                ->orderBy('code');
            foreach ($query->lazy(500) as $project) {
                yield $this->safeRow([
                    $project->code,
                    $project->name,
                    (string) ($project->description ?? ''),
                    (string) $project->getRelation('client')?->getAttribute('code'),
                    (string) $project->getRelation('manager')?->getAttribute('email'),
                    $project->status->code,
                    $project->priority,
                    $project->start_date?->format('Y-m-d') ?? '',
                    $project->end_date?->format('Y-m-d') ?? '',
                ]);
            }

            return;
        }

        /** @var Builder<Task> $query */
        $query = Task::query()
            ->whereNull('archived_at')
            ->with(['project:id,code', 'status:id,code', 'assignee:id,email'])
            ->orderBy('project_id')
            ->orderBy('code');
        foreach ($query->lazy(500) as $task) {
            yield $this->safeRow([
                $task->project->code,
                $task->code,
                $task->title,
                (string) ($task->description ?? ''),
                $task->status->code,
                $task->priority,
                (string) $task->getRelation('assignee')?->getAttribute('email'),
                $task->assigned_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                $task->start_at->timezone($timezone)->format('Y-m-d H:i:s'),
                $task->due_at->timezone($timezone)->format('Y-m-d H:i:s'),
                $task->estimated_minutes !== null ? (string) $task->estimated_minutes : '',
                (string) ($task->notes ?? ''),
            ]);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  callable(): Generator<int, list<string>>  $rows
     */
    private function stream(
        string $filename,
        array $headers,
        callable $rows,
        ?DataJob $job = null,
        ?User $actor = null,
    ): StreamedResponse {
        $response = response()->streamDownload(function () use ($headers, $rows, $job, $actor): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                $this->failJob($job, 'تعذر فتح تدفق ملف CSV.');
                throw new RuntimeException('تعذر فتح تدفق ملف CSV.');
            }

            try {
                if (fwrite($output, "\xEF\xBB\xBF") === false
                    || fputcsv($output, $headers, ',', '"', '') === false) {
                    throw new RuntimeException('تعذر كتابة ترويسة ملف CSV.');
                }
                foreach ($rows() as $row) {
                    if (fputcsv($output, $row, ',', '"', '') === false) {
                        throw new RuntimeException('تعذرت كتابة أحد صفوف ملف CSV.');
                    }
                }
            } catch (Throwable $exception) {
                $this->failJob($job, 'فشل إنشاء ملف CSV أثناء البث.');
                throw $exception;
            } finally {
                fclose($output);
            }

            if ($job instanceof DataJob && $actor instanceof User) {
                $job->update(['status' => 'succeeded', 'completed_at' => now()]);
                $this->activityLogger->record(
                    $job,
                    'data_export.created',
                    $actor,
                    after: $job->fresh()->toArray(),
                    request: request(),
                );
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Pragma' => 'no-cache',
        ]);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');

        return $response;
    }

    private function failJob(?DataJob $job, string $message): void
    {
        $job?->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $row
     * @return list<string>
     */
    private function safeRow(array $row): array
    {
        return array_map(
            fn (string $value): string => preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value,
            $row,
        );
    }
}
