<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreviewXlsxImportRequest;
use App\Models\User;
use App\Services\CsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

class XlsxImportController extends Controller
{
    public function preview(
        PreviewXlsxImportRequest $request,
        string $resource,
        CsvImportService $service,
    ): JsonResponse|RedirectResponse {
        $user = $request->user();
        $upload = $request->file('file');
        abort_unless($user instanceof User && $upload instanceof UploadedFile, 422);
        $job = $service->previewXlsx($upload, $resource, $user);

        if ($request->expectsJson()) {
            return response()->json(['data' => $job], 201)
                ->header('Location', route('data-center.jobs.show', $job));
        }

        Inertia::flash('dataJob', $job);
        Inertia::flash('toast', [
            'type' => $job->status === 'validated' ? 'success' : 'error',
            'message' => $job->status === 'validated'
                ? 'اكتملت معاينة Excel ويمكن تنفيذ الاستيراد.'
                : 'تحتوي معاينة Excel أخطاء يجب تصحيحها.',
        ]);

        return back();
    }
}
