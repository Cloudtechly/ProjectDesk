<?php

namespace App\Services;

use App\Models\Client;
use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAssignmentEvent;
use App\Models\User;
use App\Models\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CsvImportService
{
    private const LOOKUP_CHUNK_SIZE = 500;

    private const PAIR_LOOKUP_CHUNK_SIZE = 200;

    private const WRITE_CHUNK_SIZE = 50;

    public function __construct(
        private readonly CsvDataService $csv,
        private readonly XlsxDataService $xlsx,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function preview(UploadedFile $upload, string $resource, User $actor): DataJob
    {
        return $this->previewFile($upload, $resource, $actor, 'csv');
    }

    public function previewXlsx(UploadedFile $upload, string $resource, User $actor): DataJob
    {
        return $this->previewFile($upload, $resource, $actor, 'xlsx');
    }

    private function previewFile(UploadedFile $upload, string $resource, User $actor, string $format): DataJob
    {
        $disk = 'local';
        $key = 'imports/project-desk/'.Str::uuid().'.'.$format;
        $stored = $upload->storeAs(dirname($key), basename($key), $disk);
        if (! is_string($stored)) {
            throw ValidationException::withMessages(['file' => 'تعذر تخزين ملف الاستيراد بأمان.']);
        }

        $path = Storage::disk($disk)->path($key);
        $checksum = hash_file('sha256', $path);
        if (! is_string($checksum)) {
            Storage::disk($disk)->delete($key);
            throw ValidationException::withMessages(['file' => 'تعذر حساب بصمة ملف CSV.']);
        }

        $fileObject = FileObject::query()->create([
            'disk' => $disk,
            'storage_key' => $key,
            'original_name' => mb_substr($upload->getClientOriginalName(), 0, 255),
            'mime_type' => $upload->getMimeType() ?: ($format === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'text/csv'),
            'extension' => $format,
            'size_bytes' => filesize($path) ?: 0,
            'checksum_sha256' => $checksum,
            'scan_status' => 'pending',
            'uploaded_by' => $actor->id,
            'uploaded_at' => now(),
        ]);
        $job = DataJob::query()->create([
            'type' => 'import',
            'resource_type' => $resource,
            'format' => $format,
            'status' => 'processing',
            'file_object_id' => $fileObject->id,
            'created_by' => $actor->id,
            'started_at' => now(),
        ]);

        try {
            $parsed = $this->parse($path, $resource, $format);
            $fileObject->update(['scan_status' => 'structurally_safe']);
            $validated = $this->validateRows($resource, $parsed['rows']);
            $sheet = (string) ($parsed['sheet'] ?? $resource);
            $validationErrors = array_map(
                fn (array $error): array => ['sheet' => $sheet, ...$error],
                $validated['errors'],
            );
            $errors = [...$parsed['errors'], ...$validationErrors];
            if ($parsed['rows'] === [] && $errors === []) {
                $errors[] = $this->error(null, null, 'empty_file', 'لا يحتوي الملف أي صفوف بيانات.');
            }
            $this->replaceErrors($job, $errors, $sheet);

            $summary = [
                'checksum_sha256' => $checksum,
                'template_version' => $format === 'xlsx' ? XlsxDataService::TEMPLATE_VERSION : 'csv-v1',
                'encoding' => $parsed['encoding'],
                'sheet' => $sheet,
                'row_count' => count($parsed['rows']),
                'valid_count' => count($validated['rows']),
                'error_count' => count($errors),
                'actions' => $validated['actions'],
                'record_versions' => $this->recordVersions($resource, $validated['rows']),
                'transformations' => $parsed['transformations'],
                'preview' => array_slice($validated['rows'], 0, (int) config('project-desk.data_center.preview_rows', 20)),
                'can_commit' => $errors === [] && $validated['rows'] !== [],
            ];
            $job->update([
                'status' => $summary['can_commit'] ? 'validated' : 'validation_failed',
                'summary' => $summary,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $fileObject->update(['scan_status' => 'quarantined']);
            $this->replaceErrors($job, [$this->error(null, null, 'parse_error', $exception->getMessage())], $resource);
            $job->update([
                'status' => 'validation_failed',
                'summary' => ['checksum_sha256' => $checksum, 'error_count' => 1, 'can_commit' => false],
                'error_message' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
        }

        $this->activityLogger->record($job, 'data_import.previewed', $actor, after: $job->fresh()->toArray(), request: request());

        return $job->fresh()->load(['fileObject', 'importErrors']);
    }

    public function commit(DataJob $job, string $checksum, User $actor): DataJob
    {
        if ($job->type !== 'import' || ! in_array($job->resource_type, ['clients', 'tasks'], true)) {
            throw ValidationException::withMessages(['data_job' => 'مهمة البيانات ليست معاينة استيراد قابلة للالتزام.']);
        }
        if ($job->status !== 'validated') {
            throw ValidationException::withMessages(['data_job' => 'يجب أن تكون المعاينة ناجحة وغير ملتزم بها قبل الاستيراد.']);
        }

        $file = $job->fileObject;
        if (! $file instanceof FileObject || ! Storage::disk($file->disk)->exists($file->storage_key)) {
            throw ValidationException::withMessages(['file' => 'ملف المعاينة لم يعد متاحاً.']);
        }
        $path = Storage::disk($file->disk)->path($file->storage_key);
        $actualChecksum = hash_file('sha256', $path);
        if (! is_string($actualChecksum)
            || ! hash_equals($file->checksum_sha256, $actualChecksum)
            || ! hash_equals($actualChecksum, $checksum)) {
            throw ValidationException::withMessages(['checksum' => 'تغير الملف بعد المعاينة أو لا تطابق بصمته.']);
        }

        $parsed = $this->parse($path, $job->resource_type, (string) $job->format);
        $validated = $this->validateRows($job->resource_type, $parsed['rows']);
        $sheet = (string) ($parsed['sheet'] ?? $job->resource_type);
        $validationErrors = array_map(
            fn (array $error): array => ['sheet' => $sheet, ...$error],
            $validated['errors'],
        );
        $errors = [...$parsed['errors'], ...$validationErrors];
        if ($errors !== [] || $validated['rows'] === []) {
            $this->replaceErrors($job, $errors !== [] ? $errors : [$this->error(null, null, 'empty_file', 'لا توجد صفوف صالحة للاستيراد.')], $sheet);
            $job->update(['status' => 'validation_failed', 'completed_at' => now()]);
            throw ValidationException::withMessages(['file' => 'لم يعد الملف صالحاً وفق البيانات الحالية؛ راجع الأخطاء وأعد المعاينة.']);
        }
        $expectedVersions = data_get($job->summary, 'record_versions', []);
        $currentVersions = $this->recordVersions($job->resource_type, $validated['rows']);
        if (! is_array($expectedVersions)
            || ($job->resource_type === 'tasks' && $expectedVersions !== $currentVersions)) {
            throw ValidationException::withMessages([
                'data_job' => 'تغيرت إحدى السجلات منذ المعاينة؛ أعد المعاينة قبل الاستيراد حتى لا تُستبدل تعديلات أحدث.',
            ]);
        }

        try {
            DB::transaction(function () use ($job, $validated, $expectedVersions, $actor): void {
                $lockedJob = DataJob::query()->lockForUpdate()->findOrFail($job->id);
                if ($lockedJob->status !== 'validated') {
                    throw ValidationException::withMessages(['data_job' => 'بدأ مستخدم آخر الالتزام بهذه المعاينة أو اكتمل بالفعل.']);
                }
                $lockedClients = $job->resource_type === 'clients'
                    ? $this->lockClientsForCommit($validated['rows'], $expectedVersions)
                    : [];
                $lockedTasks = $job->resource_type === 'tasks'
                    ? $this->lockTasksForCommit($validated['rows'])
                    : [];
                $lockedJob->update(['status' => 'processing', 'started_at' => now(), 'completed_at' => null]);

                if ($job->resource_type === 'clients') {
                    foreach ($validated['rows'] as $row) {
                        $this->commitClient($row, $actor, $lockedClients[(string) $row['code']] ?? null);
                    }
                } else {
                    $this->commitTasks($validated['rows'], $actor, $lockedTasks);
                }

                $summary = $lockedJob->summary ?? [];
                $summary['committed_count'] = count($validated['rows']);
                $summary['committed_at'] = now()->toIso8601String();
                $summary['can_commit'] = false;
                $lockedJob->update([
                    'status' => 'succeeded',
                    'summary' => $summary,
                    'error_message' => null,
                    'completed_at' => now(),
                ]);
                $this->activityLogger->record($lockedJob, 'data_import.committed', $actor, after: $lockedJob->toArray(), request: request());
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $job->update(['status' => 'failed', 'error_message' => 'تعذر إكمال الاستيراد؛ لم تُحفظ أي صفوف.', 'completed_at' => now()]);
            throw ValidationException::withMessages(['file' => 'فشل الاستيراد وتم التراجع عن جميع الصفوف.']);
        }

        return $job->fresh()->load(['fileObject', 'importErrors']);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   errors: list<array{row_number: int|null, field: string|null, code: string, message: string}>,
     *   actions: array{create: int, update: int}
     * }
     */
    private function validateRows(string $resource, array $rows): array
    {
        return $resource === 'clients' ? $this->validateClients($rows) : $this->validateTasks($rows);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row_number: int|null, field: string|null, code: string, message: string}>, actions: array{create: int, update: int}}
     */
    private function validateClients(array $rows): array
    {
        $canonical = [];
        $errors = [];
        $seen = [];
        $actions = ['create' => 0, 'update' => 0];

        foreach ($rows as $row) {
            $line = (int) ($row['_row_number'] ?? 0);
            $data = [
                'code' => mb_strtoupper($row['code'] ?? ''),
                'name' => $row['name'] ?? '',
                'email' => mb_strtolower($row['email'] ?? ''),
                'phone' => $row['phone'] ?? '',
                'address' => $row['address'] ?? '',
                'status' => mb_strtolower($row['status'] ?? ''),
            ];
            $validator = Validator::make($data, [
                'code' => ['required', 'string', 'max:40'],
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'address' => ['nullable', 'string', 'max:5000'],
                'status' => ['required', 'in:active,inactive'],
            ]);
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = $this->error($line, $field, 'validation', $message);
                }
            }
            if (isset($seen[$data['code']])) {
                $errors[] = $this->error($line, 'code', 'duplicate_row', 'رمز العميل مكرر داخل الملف.');
            }
            $seen[$data['code']] = true;

            $existing = Client::query()->where('code', $data['code'])->first();
            if ($existing?->archived_at !== null) {
                $errors[] = $this->error($line, 'code', 'archived_record', 'يوجد عميل مؤرشف بهذا الرمز ولا يمكن إحياؤه عبر الاستيراد.');
            }
            if (! $validator->fails() && $existing?->archived_at === null) {
                $action = $existing instanceof Client ? 'update' : 'create';
                $actions[$action]++;
                $canonical[] = [
                    ...$data,
                    '_row_number' => $line,
                    '_action' => $action,
                    '_record_version' => $existing instanceof Client ? $this->clientRecordVersion($existing) : null,
                ];
            }
        }

        return ['rows' => $canonical, 'errors' => $errors, 'actions' => $actions];
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{rows: list<array<string, mixed>>, errors: list<array{row_number: int|null, field: string|null, code: string, message: string}>, actions: array{create: int, update: int}}
     */
    private function validateTasks(array $rows): array
    {
        $canonical = [];
        $errors = [];
        $seen = [];
        $actions = ['create' => 0, 'update' => 0];
        $timezone = (string) config('project-desk.business_timezone', 'Africa/Tripoli');
        $maps = $this->taskValidationMaps($rows);

        foreach ($rows as $row) {
            $rowErrorStart = count($errors);
            $line = (int) ($row['_row_number'] ?? 0);
            $projectCode = mb_strtoupper($row['project_code'] ?? '');
            $code = mb_strtoupper($row['code'] ?? '');
            $statusCode = mb_strtolower($row['status_code'] ?? '');
            $email = mb_strtolower($row['assignee_email'] ?? '');
            $data = [
                'project_code' => $projectCode,
                'code' => $code,
                'title' => $row['title'] ?? '',
                'description' => $row['description'] ?? '',
                'status_code' => $statusCode,
                'priority' => mb_strtolower($row['priority'] ?? ''),
                'assignee_email' => $email,
                'assigned_at' => $row['assigned_at'] ?? '',
                'start_at' => $row['start_at'] ?? '',
                'due_at' => $row['due_at'] ?? '',
                'estimated_minutes' => $row['estimated_minutes'] ?? '',
                'notes' => $row['notes'] ?? '',
            ];
            $validator = Validator::make($data, [
                'project_code' => ['required', 'string', 'max:40'],
                'code' => ['nullable', 'string', 'max:40'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:20000'],
                'status_code' => ['required', 'string', 'max:60'],
                'priority' => ['required', 'in:low,medium,high,critical'],
                'assignee_email' => ['nullable', 'email', 'max:255'],
                'assigned_at' => ['nullable', 'string'],
                'start_at' => ['required', 'string'],
                'due_at' => ['required', 'string'],
                'estimated_minutes' => ['nullable', 'integer', 'between:1,100000'],
                'notes' => ['nullable', 'string', 'max:20000'],
            ]);
            $validatorFailed = $validator->fails();
            foreach ($validator->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $errors[] = $this->error($line, $field, 'validation', $message);
                }
            }

            $project = $maps['projects'][$projectCode] ?? null;
            if (! $project instanceof Project) {
                $errors[] = $this->error($line, 'project_code', 'unknown_project', 'المشروع غير موجود أو مؤرشف.');
            }
            $status = $maps['statuses'][$statusCode] ?? null;
            if (! $status instanceof WorkflowStatus) {
                $errors[] = $this->error($line, 'status_code', 'unknown_status', 'حالة المهمة غير موجودة أو غير نشطة.');
            }
            $assignee = $email !== '' ? ($maps['users'][$email] ?? null) : null;
            if ($email !== '' && ! $assignee instanceof User) {
                $errors[] = $this->error($line, 'assignee_email', 'unknown_assignee', 'المسؤول غير موجود أو غير نشط.');
            }
            if ($project instanceof Project && $assignee instanceof User) {
                $isMember = $project->manager_id === $assignee->id
                    || isset($maps['memberships'][$project->id.'|'.$assignee->id]);
                if (! $isMember) {
                    $errors[] = $this->error($line, 'assignee_email', 'project_scope', 'المسؤول ليس عضواً نشطاً في المشروع.');
                }
            }
            if ($email === '' && $data['assigned_at'] !== '') {
                $errors[] = $this->error($line, 'assigned_at', 'assignment_without_user', 'لا يمكن تسجيل وقت إسناد دون مسؤول.');
            }

            $startAt = $this->parseDate($data['start_at'], $timezone, $line, 'start_at', $errors);
            $dueAt = $this->parseDate($data['due_at'], $timezone, $line, 'due_at', $errors);
            $assignedAt = $data['assigned_at'] !== ''
                ? $this->parseDate($data['assigned_at'], $timezone, $line, 'assigned_at', $errors)
                : null;
            if ($startAt !== null && $dueAt !== null && $dueAt->lt($startAt)) {
                $errors[] = $this->error($line, 'due_at', 'date_order', 'نهاية المهمة يجب ألا تسبق بدايتها.');
            }

            $key = $projectCode.'|'.$code;
            if ($code !== '' && isset($seen[$key])) {
                $errors[] = $this->error($line, 'code', 'duplicate_row', 'رمز المهمة مكرر للمشروع نفسه داخل الملف.');
            }
            if ($code !== '') {
                $seen[$key] = true;
            }
            $existing = $project instanceof Project && $code !== ''
                ? ($maps['tasks'][$project->id.'|'.$code] ?? null)
                : null;
            if ($existing?->archived_at !== null) {
                $errors[] = $this->error($line, 'code', 'archived_record', 'توجد مهمة مؤرشفة بهذا الرمز ولا يمكن إحياؤها عبر الاستيراد.');
            }

            $rowHasError = count($errors) > $rowErrorStart;
            if (! $validatorFailed && ! $rowHasError && $project instanceof Project && $status instanceof WorkflowStatus) {
                $action = $existing instanceof Task ? 'update' : 'create';
                $actions[$action]++;
                $canonical[] = [
                    'project_id' => $project->id,
                    'project_code' => $projectCode,
                    'code' => $code,
                    'title' => $data['title'],
                    'description' => $data['description'] !== '' ? $data['description'] : null,
                    'status_id' => $status->id,
                    'status_semantic' => $status->semantic,
                    'priority' => $data['priority'],
                    'assignee_id' => $assignee?->id,
                    'assigned_at' => $assignedAt?->format('Y-m-d H:i:s'),
                    'start_at' => $startAt?->format('Y-m-d H:i:s'),
                    'due_at' => $dueAt?->format('Y-m-d H:i:s'),
                    'estimated_minutes' => $data['estimated_minutes'] !== '' ? (int) $data['estimated_minutes'] : null,
                    'notes' => $data['notes'] !== '' ? $data['notes'] : null,
                    '_row_number' => $line,
                    '_action' => $action,
                    '_lock_version' => $existing?->lock_version,
                ];
            }
        }

        return ['rows' => $canonical, 'errors' => $errors, 'actions' => $actions];
    }

    /**
     * Load every database-backed task validation dependency in bounded batches.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{
     *   projects: array<string, Project>,
     *   statuses: array<string, WorkflowStatus>,
     *   users: array<string, User>,
     *   memberships: array<string, true>,
     *   tasks: array<string, Task>
     * }
     */
    private function taskValidationMaps(array $rows): array
    {
        $projectCodes = [];
        $statusCodes = [];
        $emails = [];

        foreach ($rows as $row) {
            $projectCode = mb_strtoupper($row['project_code'] ?? '');
            $statusCode = mb_strtolower($row['status_code'] ?? '');
            $email = mb_strtolower($row['assignee_email'] ?? '');
            if ($projectCode !== '') {
                $projectCodes[$projectCode] = true;
            }
            if ($statusCode !== '') {
                $statusCodes[$statusCode] = true;
            }
            if ($email !== '') {
                $emails[$email] = true;
            }
        }

        $projects = [];
        foreach (array_chunk(array_keys($projectCodes), self::LOOKUP_CHUNK_SIZE) as $codes) {
            foreach (Project::query()
                ->whereIn('code', $codes)
                ->whereNull('archived_at')
                ->get(['id', 'code', 'manager_id']) as $project) {
                $projects[$project->code] = $project;
            }
        }

        $statuses = [];
        foreach (array_chunk(array_keys($statusCodes), self::LOOKUP_CHUNK_SIZE) as $codes) {
            foreach (WorkflowStatus::query()
                ->where('entity_type', 'task')
                ->where('is_active', true)
                ->whereIn('code', $codes)
                ->get(['id', 'code', 'semantic']) as $status) {
                $statuses[$status->code] = $status;
            }
        }

        $users = [];
        foreach (array_chunk(array_keys($emails), self::LOOKUP_CHUNK_SIZE) as $emailChunk) {
            foreach (User::query()
                ->whereIn('email', $emailChunk)
                ->where('status', 'active')
                ->whereNull('archived_at')
                ->where('global_role', '!=', 'viewer')
                ->get(['id', 'email']) as $user) {
                $users[$user->email] = $user;
            }
        }

        $membershipPairs = [];
        $taskPairs = [];
        foreach ($rows as $row) {
            $projectCode = mb_strtoupper($row['project_code'] ?? '');
            $project = $projects[$projectCode] ?? null;
            if (! $project instanceof Project) {
                continue;
            }

            $email = mb_strtolower($row['assignee_email'] ?? '');
            $assignee = $email !== '' ? ($users[$email] ?? null) : null;
            if ($assignee instanceof User && $project->manager_id !== $assignee->id) {
                $membershipPairs[$project->id.'|'.$assignee->id] = [
                    'project_id' => $project->id,
                    'user_id' => $assignee->id,
                ];
            }

            $code = mb_strtoupper($row['code'] ?? '');
            if ($code !== '') {
                $taskPairs[$project->id.'|'.$code] = [
                    'project_id' => $project->id,
                    'code' => $code,
                ];
            }
        }

        return [
            'projects' => $projects,
            'statuses' => $statuses,
            'users' => $users,
            'memberships' => $this->loadActiveMemberships(array_values($membershipPairs)),
            'tasks' => $this->loadTasksByPairs(array_values($taskPairs)),
        ];
    }

    /**
     * @param  list<array{project_id: int, user_id: int}>  $pairs
     * @return array<string, true>
     */
    private function loadActiveMemberships(array $pairs): array
    {
        $memberships = [];
        foreach (array_chunk($pairs, self::PAIR_LOOKUP_CHUNK_SIZE) as $chunk) {
            $query = DB::table('project_members')->where('status', 'active');
            $query->where(function ($pairQuery) use ($chunk): void {
                foreach ($chunk as $pair) {
                    $pairQuery->orWhere(function ($exactPair) use ($pair): void {
                        $exactPair
                            ->where('project_id', $pair['project_id'])
                            ->where('user_id', $pair['user_id']);
                    });
                }
            });

            foreach ($query->get(['project_id', 'user_id']) as $membership) {
                $memberships[(int) $membership->project_id.'|'.(int) $membership->user_id] = true;
            }
        }

        return $memberships;
    }

    /**
     * @param  list<array{project_id: int, code: string}>  $pairs
     * @return array<string, Task>
     */
    private function loadTasksByPairs(array $pairs, bool $lockForUpdate = false): array
    {
        usort($pairs, fn (array $left, array $right): int => [$left['project_id'], $left['code']] <=> [$right['project_id'], $right['code']]);

        $tasks = [];
        foreach (array_chunk($pairs, self::PAIR_LOOKUP_CHUNK_SIZE) as $chunk) {
            $query = Task::query()->where(function ($pairQuery) use ($chunk): void {
                foreach ($chunk as $pair) {
                    $pairQuery->orWhere(function ($exactPair) use ($pair): void {
                        $exactPair
                            ->where('project_id', $pair['project_id'])
                            ->where('code', $pair['code']);
                    });
                }
            })->orderBy('project_id')->orderBy('code');
            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            foreach ($query->get() as $task) {
                $tasks[$task->project_id.'|'.$task->code] = $task;
            }
        }

        return $tasks;
    }

    /**
     * @param  list<array{row_number: int|null, field: string|null, code: string, message: string}>  $errors
     */
    private function parseDate(string $value, string $timezone, int $line, string $field, array &$errors): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, $timezone)->utc();
        } catch (Throwable) {
            $errors[] = $this->error($line, $field, 'invalid_date', 'صيغة التاريخ أو الوقت غير صحيحة.');

            return null;
        }
    }

    /** @param  array<string, mixed>  $row */
    private function commitClient(array $row, User $actor, ?Client $client): void
    {
        $attributes = [
            'name' => $row['name'],
            'email' => $row['email'] !== '' ? $row['email'] : null,
            'phone' => $row['phone'] !== '' ? $row['phone'] : null,
            'address' => $row['address'] !== '' ? $row['address'] : null,
            'status' => $row['status'],
        ];
        if ($client instanceof Client) {
            $before = $client->toArray();
            $client->update($attributes);
            $this->activityLogger->record($client, 'client.import_updated', $actor, $before, $client->fresh()->toArray(), request());

            return;
        }

        $client = Client::query()->create([
            'code' => $row['code'],
            'created_by' => $actor->id,
            ...$attributes,
        ]);
        $this->activityLogger->record($client, 'client.import_created', $actor, after: $client->toArray(), request: request());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, Task>
     */
    private function lockTasksForCommit(array $rows): array
    {
        $pairs = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code !== '') {
                $key = (int) $row['project_id'].'|'.$code;
                $pairs[$key] = ['project_id' => (int) $row['project_id'], 'code' => $code];
            }
        }

        $lockedTasks = $this->loadTasksByPairs(array_values($pairs), true);
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $task = $lockedTasks[(int) $row['project_id'].'|'.$code] ?? null;
            $expectedVersion = $row['_lock_version'] ?? null;
            $matchesPreview = $expectedVersion === null
                ? ! $task instanceof Task
                : $task instanceof Task && $task->lock_version === $expectedVersion;
            if (! $matchesPreview || ($task instanceof Task && $task->archived_at !== null)) {
                throw ValidationException::withMessages([
                    'data_job' => 'تغيرت المهمة منذ التحقق الأخير؛ أعد المعاينة قبل الاستيراد.',
                ]);
            }
        }

        return $lockedTasks;
    }

    /**
     * Persist a validated task import without issuing a read/write query per row.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, Task>  $lockedTasks
     */
    private function commitTasks(array $rows, User $actor, array $lockedTasks): void
    {
        $timestamp = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $existingWrites = [];
        $newWrites = [];
        $writePairs = [];
        $metadata = [];

        foreach ($rows as $index => $row) {
            $projectId = (int) $row['project_id'];
            $code = (string) $row['code'];
            $task = $code !== '' ? ($lockedTasks[$projectId.'|'.$code] ?? null) : null;
            $storageCode = $code !== '' ? $code : 'PENDING-'.(string) Str::ulid();
            $assignedAt = $row['assignee_id'] === null
                ? null
                : ($row['assigned_at'] ?? ($task?->assigned_at?->utc()->format('Y-m-d H:i:s') ?? $timestamp));
            $completedAt = $row['status_semantic'] === 'done'
                ? ($task?->completed_at?->utc()->format('Y-m-d H:i:s') ?? $timestamp)
                : null;
            $write = [
                'project_id' => $projectId,
                'code' => $storageCode,
                'title' => $row['title'],
                'description' => $row['description'],
                'status_id' => $row['status_id'],
                'priority' => $row['priority'],
                'assignee_id' => $row['assignee_id'],
                'assigned_at' => $assignedAt,
                'start_at' => $row['start_at'],
                'due_at' => $row['due_at'],
                'completed_at' => $completedAt,
                'estimated_minutes' => $row['estimated_minutes'],
                'notes' => $row['notes'],
                'lock_version' => $task instanceof Task ? $task->lock_version + 1 : 1,
                'archived_at' => null,
                'created_at' => $task?->getRawOriginal('created_at') ?? $timestamp,
                'updated_at' => $timestamp,
            ];

            if ($task instanceof Task) {
                $write['id'] = $task->id;
                $existingWrites[] = $write;
            } else {
                $newWrites[] = $write;
            }

            $lookupKey = $projectId.'|'.$storageCode;
            $writePairs[$lookupKey] = ['project_id' => $projectId, 'code' => $storageCode];
            $metadata[$index] = [
                'lookup_key' => $lookupKey,
                'needs_generated_code' => $code === '',
                'before' => $task?->toArray() ?? [],
                'from_user_id' => $task?->assignee_id,
                'action' => $task instanceof Task ? 'task.import_updated' : 'task.import_created',
            ];
        }

        $updateColumns = [
            'title', 'description', 'status_id', 'priority', 'assignee_id', 'assigned_at',
            'start_at', 'due_at', 'completed_at', 'estimated_minutes', 'notes', 'lock_version',
            'archived_at', 'updated_at',
        ];
        foreach (array_chunk($existingWrites, self::WRITE_CHUNK_SIZE) as $chunk) {
            Task::query()->upsert($chunk, ['id'], $updateColumns);
        }
        foreach (array_chunk($newWrites, self::WRITE_CHUNK_SIZE) as $chunk) {
            Task::query()->insert($chunk);
        }

        $persistedTasks = $this->loadTasksByPairs(array_values($writePairs));
        $codeUpdates = [];
        $tasksByIndex = [];
        foreach ($metadata as $index => $entry) {
            $task = $persistedTasks[$entry['lookup_key']] ?? null;
            if (! $task instanceof Task) {
                throw new \RuntimeException('A committed task could not be reloaded.');
            }
            $tasksByIndex[$index] = $task;
            if ($entry['needs_generated_code']) {
                $generatedCode = 'TSK-'.str_pad((string) $task->id, 5, '0', STR_PAD_LEFT);
                $codeUpdates[] = [
                    ...$task->getAttributes(),
                    'code' => $generatedCode,
                    'updated_at' => $timestamp,
                ];
                $task->forceFill(['code' => $generatedCode, 'updated_at' => $timestamp]);
            }
        }
        foreach (array_chunk($codeUpdates, self::WRITE_CHUNK_SIZE) as $chunk) {
            Task::query()->upsert($chunk, ['id'], ['code', 'updated_at']);
        }

        $assignmentEvents = [];
        $activityEntries = [];
        foreach ($metadata as $index => $entry) {
            $task = $tasksByIndex[$index];
            if ($entry['from_user_id'] !== $task->assignee_id) {
                $assignmentEvents[] = [
                    'task_id' => $task->id,
                    'from_user_id' => $entry['from_user_id'],
                    'to_user_id' => $task->assignee_id,
                    'recorded_by' => $actor->id,
                    'assigned_at' => $task->assigned_at?->utc()->format('Y-m-d H:i:s') ?? $timestamp,
                    'recorded_at' => $timestamp,
                    'note' => 'استيراد CSV',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
            $activityEntries[] = [
                'subject' => $task,
                'action' => $entry['action'],
                'before' => $entry['before'],
                'after' => $task->toArray(),
            ];
        }

        foreach (array_chunk($assignmentEvents, self::WRITE_CHUNK_SIZE) as $chunk) {
            TaskAssignmentEvent::query()->insert($chunk);
        }
        $this->activityLogger->recordMany($activityEntries, $actor, request());
    }

    /** @param  list<array{sheet?: string|null, row_number: int|null, field: string|null, code: string, message: string}>  $errors */
    private function replaceErrors(DataJob $job, array $errors, ?string $defaultSheet = null): void
    {
        $job->importErrors()->delete();
        if ($errors !== []) {
            $job->importErrors()->createMany(array_map(fn (array $error): array => [
                'sheet' => $error['sheet'] ?? $defaultSheet ?? $job->resource_type,
                ...$error,
            ], $errors));
        }
    }

    /**
     * @return array{
     *   rows: list<array<string, string>>,
     *   errors: list<array{sheet?: string|null, row_number: int|null, field: string|null, code: string, message: string}>,
     *   encoding: string,
     *   sheet?: string,
     *   transformations: array<string, mixed>
     * }
     */
    private function parse(string $path, string $resource, string $format): array
    {
        return match ($format) {
            'csv' => $this->csv->parse($path, $resource),
            'xlsx' => $this->xlsx->parse($path, $resource),
            default => throw ValidationException::withMessages(['file' => 'صيغة ملف الاستيراد غير مدعومة.']),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int|string|null>
     */
    private function recordVersions(string $resource, array $rows): array
    {
        $versions = [];
        foreach ($rows as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            if ($resource === 'clients') {
                $version = $row['_record_version'] ?? null;
                $versions[$code] = is_string($version) ? $version : null;

                continue;
            }

            if ($resource === 'tasks') {
                $key = (string) ($row['project_id'] ?? '').'|'.$code;
                $version = $row['_lock_version'] ?? null;
                $versions[$key] = is_int($version) ? $version : null;
            }
        }
        ksort($versions);

        return $versions;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $expectedVersions
     * @return array<string, Client>
     */
    private function lockClientsForCommit(array $rows, array $expectedVersions): array
    {
        $codes = array_values(array_unique(array_map(
            fn (array $row): string => (string) ($row['code'] ?? ''),
            $rows,
        )));
        sort($codes, SORT_STRING);

        $lockedClients = [];
        foreach (Client::query()->whereIn('code', $codes)->orderBy('code')->lockForUpdate()->get() as $client) {
            $lockedClients[(string) $client->getAttribute('code')] = $client;
        }

        foreach ($codes as $code) {
            $client = $lockedClients[$code] ?? null;
            $currentVersion = $client instanceof Client ? $this->clientRecordVersion($client) : null;
            $expectedVersion = $expectedVersions[$code] ?? null;
            $hasExpectedVersion = array_key_exists($code, $expectedVersions)
                && (is_string($expectedVersion) || $expectedVersion === null);

            if (! $hasExpectedVersion || $expectedVersion !== $currentVersion) {
                throw ValidationException::withMessages([
                    'data_job' => 'تغيّر أحد العملاء منذ المعاينة؛ أعد المعاينة قبل الاستيراد حتى لا تُستبدل تعديلات أحدث.',
                ]);
            }
        }

        return $lockedClients;
    }

    private function clientRecordVersion(Client $client): string
    {
        $updatedAt = (string) ($client->getRawOriginal('updated_at') ?? '');
        $payload = json_encode([
            'id' => $client->getKey(),
            'created_by' => $client->getRawOriginal('created_by'),
            'code' => $client->getRawOriginal('code'),
            'name' => $client->getRawOriginal('name'),
            'email' => $client->getRawOriginal('email'),
            'phone' => $client->getRawOriginal('phone'),
            'address' => $client->getRawOriginal('address'),
            'status' => $client->getRawOriginal('status'),
            'archived_at' => $client->getRawOriginal('archived_at'),
            'updated_at' => $updatedAt,
        ], JSON_THROW_ON_ERROR);

        return $updatedAt.'|'.hash('sha256', $payload);
    }

    /** @return array{row_number: int|null, field: string|null, code: string, message: string} */
    private function error(?int $row, ?string $field, string $code, string $message): array
    {
        return ['row_number' => $row, 'field' => $field, 'code' => $code, 'message' => $message];
    }
}
