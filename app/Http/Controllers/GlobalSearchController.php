<?php

namespace App\Http\Controllers;

use App\Models\AttachmentLink;
use App\Models\Client;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\SalesDocument;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $term = trim((string) ($validated['q'] ?? ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['data' => [], 'meta' => ['query' => $term, 'total' => 0]]);
        }

        // Escape LIKE wildcards so a search for "%" cannot enumerate records.
        $escapedTerm = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
        $like = "%{$escapedTerm}%";
        $visibleProjects = Project::query()->visibleTo($user)->whereNull('archived_at');

        $projects = (clone $visibleProjects)
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("code LIKE ? ESCAPE '\\'", [$like]);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'code', 'name'])
            ->map(fn (Project $project): array => [
                'id' => "project-{$project->id}",
                'type' => 'project',
                'type_label' => 'مشروع',
                'title' => $project->name,
                'subtitle' => $project->code,
                'href' => route('projects.show', $project, false),
            ])
            ->all();

        $tasks = Task::query()
            ->whereIn('project_id', (clone $visibleProjects)->select('id'))
            ->whereNull('archived_at')
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("title LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("code LIKE ? ESCAPE '\\'", [$like]);
            })
            ->with('project:id,name,manager_id,archived_at')
            ->orderBy('due_at')
            ->limit(5)
            ->get(['id', 'project_id', 'code', 'title'])
            ->map(fn (Task $task): array => [
                'id' => "task-{$task->id}",
                'type' => 'task',
                'type_label' => 'مهمة',
                'title' => $task->title,
                'subtitle' => "{$task->project->name} · {$task->code}",
                'href' => $user->can('update', $task)
                    ? route('tasks.edit', $task, false)
                    : route('tasks.index', ['q' => $task->code], false),
            ])
            ->all();

        $clientsQuery = Client::query()->visibleTo($user)->whereNull('archived_at');

        $clients = $clientsQuery
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("code LIKE ? ESCAPE '\\'", [$like]);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'code', 'name'])
            ->map(fn (Client $client): array => [
                'id' => "client-{$client->id}",
                'type' => 'client',
                'type_label' => 'عميل',
                'title' => $client->name,
                'subtitle' => $client->code,
                'href' => route('clients.show', $client, false),
            ])
            ->all();

        $requirements = Requirement::query()
            ->whereIn('project_id', (clone $visibleProjects)->select('id'))
            ->whereNull('archived_at')
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("title LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("code LIKE ? ESCAPE '\\'", [$like]);
            })
            ->with('project:id,name')
            ->orderBy('title')
            ->limit(5)
            ->get(['id', 'project_id', 'code', 'title'])
            ->map(fn (Requirement $requirement): array => [
                'id' => "requirement-{$requirement->id}",
                'type' => 'requirement',
                'type_label' => 'متطلب',
                'title' => $requirement->title,
                'subtitle' => "{$requirement->project->name} · {$requirement->code}",
                'href' => route('projects.show', ['project' => $requirement->project_id, 'tab' => 'requirements'], false),
            ])
            ->all();

        $salesDocuments = SalesDocument::query()
            ->visibleTo($user)
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("title LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("number LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("reference LIKE ? ESCAPE '\\'", [$like]);
            })
            ->orderByDesc('issue_date')
            ->limit(5)
            ->get(['id', 'type', 'number', 'title'])
            ->map(fn (SalesDocument $document): array => [
                'id' => "sales-{$document->id}",
                'type' => 'sales',
                'type_label' => 'قالب فاتورة',
                'title' => $document->title,
                'subtitle' => $document->number,
                'href' => route('sales.index', ['q' => $document->number], false),
            ])
            ->all();

        $downloadableStatuses = ['safe'];
        $documents = AttachmentLink::query()
            ->whereIn('project_id', (clone $visibleProjects)->select('id'))
            ->whereNull('archived_at')
            ->whereHas('fileObject', fn (Builder $query) => $query
                ->whereIn('scan_status', $downloadableStatuses)
                ->whereRaw("original_name LIKE ? ESCAPE '\\'", [$like]))
            ->with(['fileObject:id,original_name', 'project:id,name'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'file_object_id', 'project_id'])
            ->map(fn (AttachmentLink $link): array => [
                'id' => "document-{$link->id}",
                'type' => 'document',
                'type_label' => 'وثيقة',
                'title' => $link->fileObject->original_name,
                'subtitle' => $link->project->name,
                'href' => route('projects.show', ['project' => $link->project_id, 'tab' => 'documents'], false),
            ])
            ->values()
            ->all();

        $teamQuery = User::query()
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($user->global_role !== 'admin') {
            $teamQuery->whereHas('projects', function (Builder $projects) use ($user): void {
                $projects
                    ->where(function (Builder $visible) use ($user): void {
                        $visible->where('manager_id', $user->id)
                            ->orWhereHas('members', fn (Builder $members) => $members
                                ->whereKey($user->id)
                                ->where('project_members.status', 'active'));
                    })
                    ->where('project_members.status', 'active');
            });
        }

        $team = $teamQuery
            ->where(function (Builder $query) use ($like): void {
                $query->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("job_title LIKE ? ESCAPE '\\'", [$like]);
            })
            ->orderBy('name')
            ->limit(5)
            ->get(['id', 'name', 'job_title'])
            ->map(fn (User $member): array => [
                'id' => "team-{$member->id}",
                'type' => 'team',
                'type_label' => 'عضو فريق',
                'title' => $member->name,
                'subtitle' => $member->job_title ?: 'عضو فريق',
                'href' => route('team.index', ['q' => $member->name], false),
            ])
            ->all();

        $results = array_merge(
            $projects,
            $tasks,
            $requirements,
            $clients,
            $team,
            $salesDocuments,
            $documents,
        );

        return response()->json([
            'data' => $results,
            'meta' => ['query' => $term, 'total' => count($results)],
        ]);
    }
}
