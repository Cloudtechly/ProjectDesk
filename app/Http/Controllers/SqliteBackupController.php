<?php

namespace App\Http\Controllers;

use App\Http\Requests\RestoreSqliteBackupRequest;
use App\Http\Requests\UploadSqliteBackupRequest;
use App\Models\DataJob;
use App\Models\FileObject;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\RestoreNonceManager;
use App\Services\RestoreWriteFence;
use App\Services\SqliteBackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SqliteBackupController extends Controller
{
    public function store(Request $request, SqliteBackupService $service): JsonResponse
    {
        Gate::authorize('create', DataJob::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $job = $service->create($user);

        return response()->json(['data' => $job], 201)
            ->header('Location', route('data-center.jobs.show', $job));
    }

    public function upload(UploadSqliteBackupRequest $request, SqliteBackupService $service): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $file = $request->file('file');
        abort_unless($file !== null, 422);
        $job = $service->upload($file, $user);

        return response()->json(['data' => $job], 201)
            ->header('Location', route('data-center.jobs.show', $job));
    }

    public function validateBackup(
        Request $request,
        FileObject $backup,
        SqliteBackupService $service,
        RestoreNonceManager $nonces,
    ): JsonResponse {
        $this->authorize('restore', $backup);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $service->validate($backup, $user);
        $result['restore_nonce'] = $nonces->issue($user, $backup);

        return response()->json(['data' => $result]);
    }

    public function restore(
        RestoreSqliteBackupRequest $request,
        FileObject $backup,
        SqliteBackupService $service,
        RestoreWriteFence $fence,
        RestoreNonceManager $nonces,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $checksum = $request->string('checksum_sha256')->toString();
        $nonces->consume(
            $user,
            $backup,
            $checksum,
            $request->string('restore_nonce')->toString(),
        );
        $result = $fence->exclusive(
            fn (): array => $service->restore(
                $backup,
                $checksum,
                $user,
            ),
        );
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => $result]);
    }

    public function download(Request $request, FileObject $backup, ActivityLogger $activityLogger): BinaryFileResponse
    {
        $this->authorize('restore', $backup);
        abort_unless($backup->scan_status === 'safe' && Storage::disk($backup->disk)->exists($backup->storage_key), 404);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $response = response()->download(
            Storage::disk($backup->disk)->path($backup->storage_key),
            $backup->original_name,
            [
                'Content-Type' => $backup->extension === 'pdesk'
                    ? 'application/vnd.projectdesk.backup'
                    : 'application/vnd.sqlite3',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'X-Checksum-SHA256' => $backup->checksum_sha256,
            ],
        );
        $activityLogger->record($backup, 'database_backup.downloaded', $user, after: [
            'file_id' => $backup->id,
            'size_bytes' => $backup->size_bytes,
            'checksum_sha256' => $backup->checksum_sha256,
        ], request: $request);

        return $response;
    }
}
