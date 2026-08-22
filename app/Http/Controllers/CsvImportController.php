<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommitCsvImportRequest;
use App\Http\Requests\PreviewCsvImportRequest;
use App\Models\DataJob;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

class CsvImportController extends Controller
{
    public function preview(
        PreviewCsvImportRequest $request,
        string $resource,
        CsvImportService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        $upload = $request->file('file');
        abort_unless($user instanceof User && $upload instanceof UploadedFile, 422);
        $job = $service->preview($upload, $resource, $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $job], 201)
                ->header('Location', route('data-center.jobs.show', $job));
        }

        Inertia::flash('dataJob', $job);
        Inertia::flash('toast', [
            'type' => $job->status === 'validated' ? 'success' : 'error',
            'message' => $job->status === 'validated' ? 'اكتملت معاينة CSV ويمكن تنفيذ الاستيراد.' : 'تحتوي المعاينة أخطاء يجب تصحيحها.',
        ]);

        return back();
    }

    public function commit(
        CommitCsvImportRequest $request,
        DataJob $dataJob,
        CsvImportService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $job = $service->commit($dataJob, $request->string('checksum_sha256')->toString(), $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $job]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'تم استيراد جميع الصفوف بنجاح.']);

        return back();
    }
}
