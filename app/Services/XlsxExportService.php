<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DataJob;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class XlsxExportService
{
    public function __construct(
        private readonly CsvDataService $contracts,
        private readonly ActivityLogger $activityLogger,
        private readonly Filesystem $files,
    ) {}

    public function template(string $resource): BinaryFileResponse
    {
        return $this->buildDownload(
            $resource,
            "project-desk-{$resource}-template.xlsx",
            static function (): Generator {
                yield from [];
            },
        );
    }

    public function export(string $resource, User $actor, Request $request): BinaryFileResponse
    {
        $filters = $this->filterSnapshot($resource, $request);
        $count = $this->count($resource, $actor, $request);
        $job = DataJob::query()->create([
            'type' => 'export',
            'resource_type' => $resource,
            'format' => 'xlsx',
            'status' => 'processing',
            'created_by' => $actor->id,
            'summary' => [
                'row_count' => $count,
                'filters' => $filters,
                'scope_user_id' => $actor->id,
            ],
            'started_at' => now(),
        ]);

        try {
            $response = $this->buildDownload(
                $resource,
                'project-desk-'.$resource.'-'.now()->format('Ymd-His').'.xlsx',
                fn (): Generator => $this->rows($resource, $actor, $request),
            );
            $job->update(['status' => 'succeeded', 'completed_at' => now()]);
            $this->activityLogger->record(
                $job,
                'data_export.created',
                $actor,
                after: $job->fresh()->toArray(),
                request: $request,
            );

            return $response;
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'error_message' => 'فشل إنشاء ملف Excel.',
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    /** @param callable(): Generator<int, list<string>> $rows */
    private function buildDownload(string $resource, string $filename, callable $rows): BinaryFileResponse
    {
        $directory = storage_path('app/private/exports');
        $this->files->ensureDirectoryExists($directory, 0700);
        $path = tempnam($directory, 'project-desk-xlsx-');

        if ($path === false) {
            throw new RuntimeException('تعذر تجهيز ملف Excel المؤقت.');
        }

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->getProperties()
                ->setCreator('Project Desk')
                ->setTitle("Project Desk {$resource} export")
                ->setSubject('Authorized operational data export')
                ->setCustomProperty('project_desk_template_version', XlsxDataService::TEMPLATE_VERSION)
                ->setCustomProperty('project_desk_resource', $resource);
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($this->sheetTitle($resource));
            $headers = $this->contracts->headers($resource);

            foreach ($headers as $index => $header) {
                $cell = Coordinate::stringFromColumnIndex($index + 1).'1';
                $sheet->setCellValueExplicit($cell, $header, DataType::TYPE_STRING);
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))
                    ->setWidth($this->columnWidth($header));
            }

            $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
            $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '123B4A'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);
            $sheet->freezePane('A2');
            $sheet->setAutoFilter("A1:{$lastColumn}1");

            $rowNumber = 2;
            foreach ($rows() as $row) {
                foreach ($row as $index => $value) {
                    $cell = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;
                    $sheet->setCellValueExplicit($cell, $this->safeCell($value), DataType::TYPE_STRING);
                }
                $rowNumber++;
            }

            if ($rowNumber > 2) {
                $sheet->getStyle("A2:{$lastColumn}".($rowNumber - 1))
                    ->getAlignment()
                    ->setVertical(Alignment::VERTICAL_TOP)
                    ->setWrapText(true);
            }

            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);
            $spreadsheet->disconnectWorksheets();

            $response = response()->download($path, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'X-Content-Type-Options' => 'nosniff',
                'Pragma' => 'no-cache',
            ])->deleteFileAfterSend(true);
            $response->setPrivate();
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0, must-revalidate');

            return $response;
        } catch (Throwable $exception) {
            $this->files->delete($path);
            throw $exception;
        }
    }

    private function count(string $resource, User $actor, Request $request): int
    {
        return match ($resource) {
            'clients' => $this->clientQuery($actor, $request)->count(),
            'projects' => $this->projectQuery($actor, $request)->count(),
            'tasks' => $this->taskQuery($actor, $request)->count(),
            default => throw new RuntimeException('نوع بيانات Excel غير مدعوم.'),
        };
    }

    /** @return Generator<int, list<string>> */
    private function rows(string $resource, User $actor, Request $request): Generator
    {
        $timezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');

        if ($resource === 'clients') {
            foreach ($this->clientQuery($actor, $request)->cursor() as $client) {
                yield [
                    $client->code,
                    $client->name,
                    (string) ($client->email ?? ''),
                    (string) ($client->phone ?? ''),
                    (string) ($client->address ?? ''),
                    $client->status,
                ];
            }

            return;
        }

        if ($resource === 'projects') {
            $projects = $this->projectQuery($actor, $request)
                ->with(['client:id,code', 'manager:id,email', 'status:id,code']);
            foreach ($projects->lazy(500) as $project) {
                yield [
                    $project->code,
                    $project->name,
                    (string) ($project->description ?? ''),
                    (string) $project->getRelation('client')?->getAttribute('code'),
                    (string) $project->getRelation('manager')?->getAttribute('email'),
                    $project->status->code,
                    $project->priority,
                    $project->start_date?->format('Y-m-d') ?? '',
                    $project->end_date?->format('Y-m-d') ?? '',
                ];
            }

            return;
        }

        if ($resource !== 'tasks') {
            throw new RuntimeException('نوع بيانات Excel غير مدعوم.');
        }

        $tasks = $this->taskQuery($actor, $request)
            ->with(['project:id,code', 'status:id,code', 'assignee:id,email']);
        foreach ($tasks->lazy(500) as $task) {
            yield [
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
            ];
        }
    }

    /** @return Builder<Client> */
    private function clientQuery(User $actor, Request $request): Builder
    {
        $query = Client::query()->visibleTo($actor);
        $archived = $request->string('archived')->toString();
        match ($archived) {
            'only' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
        $status = $request->string('status')->toString();
        if (in_array($status, ['active', 'inactive', 'archived'], true)) {
            $query->where('status', $status);
        }
        $search = mb_substr(trim($request->string('q')->toString()), 0, 255);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $matches) use ($like): void {
                $matches->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhereHas('contacts', function (Builder $contacts) use ($like): void {
                        $contacts->where(function (Builder $fields) use ($like): void {
                            $fields->where('name', 'like', $like)
                                ->orWhere('email', 'like', $like)
                                ->orWhere('phone', 'like', $like);
                        });
                    });
            });
        }

        return $query->orderBy('name')->orderBy('id');
    }

    /** @return Builder<Project> */
    private function projectQuery(User $actor, Request $request): Builder
    {
        $query = Project::query()->visibleTo($actor);
        if ($request->string('scope')->toString() === 'archived') {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }
        $search = mb_substr(trim($request->string('q')->toString()), 0, 255);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $project) => $project
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like));
        }
        if ($request->string('activity')->toString() === 'active') {
            $query->whereHas('status', fn (Builder $status) => $status->whereNotIn('semantic', ['done', 'cancelled']));
        }
        if ($request->string('risk')->toString() === 'high') {
            $query->whereHas('risks', fn (Builder $risk) => $risk
                ->whereNull('archived_at')
                ->where('status', 'open')
                ->whereRaw('(probability * impact) >= ?', [16]));
        }
        foreach (['status' => 'status_id', 'client' => 'client_id'] as $filter => $column) {
            $value = $request->integer($filter);
            if ($value > 0) {
                $query->where($column, $value);
            }
        }
        $priority = $request->string('priority')->toString();
        if (in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $query->where('priority', $priority);
        }
        $sortColumns = ['name' => 'name', 'end_date' => 'end_date', 'priority' => 'priority'];
        $sort = $request->string('sort')->toString();
        $sort = isset($sortColumns[$sort]) ? $sort : 'end_date';
        $direction = $request->string('direction')->toString() === 'desc' ? 'desc' : 'asc';
        if ($sort === 'priority') {
            $query->orderByRaw("CASE priority WHEN 'low' THEN 1 WHEN 'medium' THEN 2 WHEN 'high' THEN 3 WHEN 'critical' THEN 4 ELSE 5 END {$direction}");
        } else {
            $query->orderBy($sortColumns[$sort], $direction);
        }

        return $query->orderBy('id');
    }

    /** @return Builder<Task> */
    private function taskQuery(User $actor, Request $request): Builder
    {
        $query = Task::query()
            ->whereIn('project_id', Project::query()->visibleTo($actor)->select('projects.id'));
        $request->boolean('archived')
            ? $query->whereNotNull('archived_at')
            : $query->whereNull('archived_at');
        $search = mb_substr(trim($request->string('q')->toString()), 0, 255);
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $task) => $task
                ->where('title', 'like', $like)
                ->orWhere('code', 'like', $like));
        }
        foreach (['project' => 'project_id', 'assignee' => 'assignee_id', 'status' => 'status_id'] as $filter => $column) {
            $value = $request->integer($filter);
            if ($value > 0) {
                $query->where($column, $value);
            }
        }
        $due = $request->string('due')->toString();
        if ($due === 'overdue') {
            $query->where('due_at', '<', now())
                ->whereHas('status', fn (Builder $status) => $status->whereNotIn('semantic', ['done', 'cancelled']));
        } elseif ($due === 'soon') {
            $query->whereBetween('due_at', [now(), now()->addDays(7)])
                ->whereHas('status', fn (Builder $status) => $status->whereNotIn('semantic', ['done', 'cancelled']));
        }

        return $query->orderBy('due_at')->orderBy('id');
    }

    /** @return array<string, string> */
    private function filterSnapshot(string $resource, Request $request): array
    {
        $allowed = match ($resource) {
            'clients' => ['q', 'status', 'archived'],
            'projects' => ['q', 'status', 'priority', 'client', 'activity', 'risk', 'sort', 'direction', 'scope'],
            'tasks' => ['q', 'project', 'assignee', 'status', 'due', 'archived'],
            default => [],
        };
        $filters = [];
        foreach ($allowed as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $filters[$key] = mb_substr($value, 0, 255);
            }
        }

        return $filters;
    }

    private function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/u', $value) === 1 ? "'".$value : $value;
    }

    private function sheetTitle(string $resource): string
    {
        return match ($resource) {
            'clients' => 'Clients',
            'projects' => 'Projects',
            'tasks' => 'Tasks',
            default => 'Export',
        };
    }

    private function columnWidth(string $header): int
    {
        return match ($header) {
            'description', 'notes', 'address' => 38,
            'name', 'title' => 28,
            'email', 'manager_email', 'assignee_email' => 30,
            'assigned_at', 'start_at', 'due_at' => 22,
            default => 18,
        };
    }
}
