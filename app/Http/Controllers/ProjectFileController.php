<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectFileRequest;
use App\Models\AttachmentLink;
use App\Models\FileObject;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ProjectFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectFileController extends Controller
{
    public function index(Request $request, Project $project, ProjectFileService $service): JsonResponse
    {
        $this->authorize('view', $project);
        $perPage = min(max($request->integer('per_page', 30), 1), 100);
        $includeArchived = $request->boolean('include_archived')
            && ($request->user()?->can('update', $project) ?? false);
        $links = AttachmentLink::query()
            ->where('project_id', $project->id)
            ->whereNull('requirement_book_version_id')
            ->whereNull('meeting_minutes_id')
            ->where(function ($query): void {
                $query->where(function ($target): void {
                    $target->whereNull('task_id')->whereNull('requirement_id');
                })->orWhere(function ($target): void {
                    $target->whereNotNull('task_id')->whereNull('requirement_id');
                })->orWhere(function ($target): void {
                    $target->whereNull('task_id')->whereNotNull('requirement_id');
                });
            })
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->with([
                'fileObject.uploader:id,name',
                'project:id,code,name',
                'task:id,project_id,code,title',
                'requirement:id,project_id,code,title',
            ])
            ->latest('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'data' => $links->getCollection()
                ->map(fn (AttachmentLink $link): array => $service->metadata(
                    $link->fileObject,
                    $request->user(),
                    $project,
                    $link,
                ))
                ->values(),
            'meta' => [
                'current_page' => $links->currentPage(),
                'last_page' => $links->lastPage(),
                'per_page' => $links->perPage(),
                'total' => $links->total(),
            ],
        ]);
    }

    public function targets(Request $request, Project $project): JsonResponse
    {
        $this->authorize('uploadFile', $project);
        $validated = $request->validate([
            'type' => ['required', Rule::in(['task', 'requirement'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $type = (string) $validated['type'];
        $search = trim((string) ($validated['q'] ?? ''));
        $query = $type === 'task'
            ? Task::query()
            : Requirement::query();
        $targets = $query
            ->where('project_id', $project->id)
            ->whereNull('archived_at')
            ->when($search !== '', function ($targetQuery) use ($search): void {
                $targetQuery->where(function ($match) use ($search): void {
                    $pattern = '%'.$search.'%';
                    $match->where('code', 'like', $pattern)->orWhere('title', 'like', $pattern);
                });
            })
            ->orderBy('code')
            ->limit(50)
            ->get(['id', 'code', 'title']);

        return response()->json([
            'data' => $targets->map(fn (Task|Requirement $target): array => [
                'id' => $target->id,
                'code' => $target->code,
                'title' => $target->title,
            ])->values(),
        ]);
    }

    public function store(
        StoreProjectFileRequest $request,
        Project $project,
        ProjectFileService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $file = $service->storeForTarget(
            $request->file('file'),
            $project,
            $user,
            $request->targetType(),
            $request->targetId(),
        );
        $link = $file->attachmentLinks()->where('project_id', $project->id)->sole();

        if ($request->expectsJson()) {
            return response()->json(['data' => $service->metadata($file, $user, $project, $link)], 201);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم رفع المرفق وحفظه ضمن المشروع.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function download(FileObject $fileObject, ProjectFileService $service): StreamedResponse
    {
        $this->authorize('download', $fileObject);
        $response = $service->download($fileObject);
        $user = request()->user();
        if ($user instanceof User) {
            app(ActivityLogger::class)->record(
                $fileObject,
                'project_file.downloaded',
                $user,
                after: ['file_id' => $fileObject->id],
                request: request(),
            );
        }

        return $response;
    }

    public function archiveLink(
        Request $request,
        Project $project,
        FileObject $file,
        AttachmentLink $attachmentLink,
        ProjectFileService $service,
    ): JsonResponse|RedirectResponse {
        $this->authorize('archiveAttachment', [$file, $project, $attachmentLink]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $service->archiveAttachmentLink($file, $project, $attachmentLink, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تمت أرشفة رابط المرفق.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة رابط المرفق دون حذف الملف أو سجله.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function restoreLink(
        Request $request,
        Project $project,
        FileObject $file,
        AttachmentLink $attachmentLink,
        ProjectFileService $service,
    ): JsonResponse|RedirectResponse {
        $this->authorize('restoreAttachment', [$file, $project, $attachmentLink]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $service->restoreAttachmentLink($file, $project, $attachmentLink, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تمت استعادة رابط المرفق.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة رابط المرفق وأصبح متاحاً للتنزيل.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function archive(
        Request $request,
        Project $project,
        FileObject $file,
        ProjectFileService $service,
    ): JsonResponse|RedirectResponse {
        $this->authorize('archiveAttachment', [$file, $project]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($file->attachmentLinks()->where('project_id', $project->id)->whereNull('archived_at')->exists(), 404);
        $service->archiveForProject($file, $project, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تمت أرشفة رابط الملف من المشروع.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت أرشفة المرفق دون حذف الملف أو سجله.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }

    public function restore(
        Request $request,
        Project $project,
        FileObject $file,
        ProjectFileService $service,
    ): JsonResponse|RedirectResponse {
        $this->authorize('restoreAttachment', [$file, $project]);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $service->restoreForProject($file, $project, $user);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'تمت استعادة المرفق إلى المشروع.']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تمت استعادة المرفق وأصبح متاحاً للتنزيل.']);

        return to_route('projects.show', ['project' => $project, 'tab' => 'documents']);
    }
}
