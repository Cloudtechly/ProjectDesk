<?php

namespace App\Services;

use App\Jobs\ProcessRequirementAnalysis;
use App\Models\Project;
use App\Models\RequirementAnalysisRun;
use App\Models\RequirementBookVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequirementAnalysisService
{
    public function __construct(private readonly LocalAiSettings $settings) {}

    public function start(Project $project, RequirementBookVersion $version, User $actor): RequirementAnalysisRun
    {
        if (! $this->settings->enabled()) {
            throw ValidationException::withMessages(['analysis' => 'التحليل المحلي غير مفعّل من إعدادات النظام.']);
        }
        if ($version->requirementBook->project_id !== $project->id) {
            abort(404);
        }
        if ($version->archived_at !== null) {
            throw ValidationException::withMessages(['version' => 'لا يمكن تحليل إصدار مؤرشف.']);
        }
        $file = $version->fileObject;
        if (! in_array($file->extension, ['pdf', 'docx'], true)) {
            throw ValidationException::withMessages(['version' => 'التحليل المحلي يدعم PDF وDOCX فقط.']);
        }
        if (! in_array($file->scan_status, ['safe', 'structurally_safe'], true)) {
            throw ValidationException::withMessages(['version' => 'يجب أن يجتاز الملف فحص الأمان قبل بدء التحليل.']);
        }
        if ($file->size_bytes > (int) config('local-ai.max_file_bytes')) {
            throw ValidationException::withMessages(['version' => 'يتجاوز الملف حد التحليل المحلي البالغ 25MB.']);
        }
        $fingerprint = $file->checksum_sha256;
        try {
            $run = DB::transaction(fn () => RequirementAnalysisRun::query()->create([
                'project_id' => $project->id, 'requirement_book_version_id' => $version->id,
                'requested_by' => $actor->id, 'status' => 'queued', 'file_fingerprint' => $fingerprint,
                'instruction_version' => (string) config('local-ai.instruction_version'),
                'model' => $this->settings->model(), 'context_size' => $this->settings->contextSize(),
            ]));
        } catch (QueryException $exception) {
            $run = RequirementAnalysisRun::query()
                ->where('requirement_book_version_id', $version->id)->where('file_fingerprint', $fingerprint)
                ->where('instruction_version', config('local-ai.instruction_version'))->where('model', $this->settings->model())
                ->first();
            if (! $run instanceof RequirementAnalysisRun) {
                throw $exception;
            }
        }
        if (in_array($run->status, ['queued', 'waiting_for_engine', 'failed', 'cancelled'], true)) {
            $run->update(['status' => 'queued', 'cancel_requested' => false, 'error_code' => null, 'error_message' => null, 'finished_at' => null]);
            ProcessRequirementAnalysis::dispatch($run->id)->afterCommit();
        }

        return $run->fresh(['candidates']);
    }

    public function cancel(RequirementAnalysisRun $run): RequirementAnalysisRun
    {
        if (! in_array($run->status, ['approved', 'cancelled', 'failed'], true)) {
            $run->update(['cancel_requested' => true]);
        }

        return $run->fresh();
    }

    public function retry(RequirementAnalysisRun $run): RequirementAnalysisRun
    {
        if (! in_array($run->status, ['failed', 'cancelled', 'waiting_for_engine', 'security_review_required'], true)) {
            throw ValidationException::withMessages(['analysis' => 'لا يمكن إعادة تشغيل التحليل في حالته الحالية.']);
        }
        $run->update(['status' => 'queued', 'cancel_requested' => false, 'error_code' => null, 'error_message' => null, 'finished_at' => null]);
        ProcessRequirementAnalysis::dispatch($run->id);

        return $run->fresh();
    }

    public function overrideSecurityReview(RequirementAnalysisRun $run, User $actor): RequirementAnalysisRun
    {
        if ($actor->global_role !== 'admin') {
            abort(403);
        }
        if ($run->status !== 'security_review_required') {
            throw ValidationException::withMessages(['analysis' => 'لا يوجد تحذير حرج بانتظار التجاوز.']);
        }
        $metadata = $run->getAttribute('metadata');
        $run->update(['status' => 'queued', 'metadata' => [
            ...(is_array($metadata) ? $metadata : []),
            'security_override' => true,
            'security_override_by' => $actor->id,
            'security_override_at' => now()->toIso8601String(),
        ]]);
        ProcessRequirementAnalysis::dispatch($run->id);

        return $run->fresh();
    }
}
