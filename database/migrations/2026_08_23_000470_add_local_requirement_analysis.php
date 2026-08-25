<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_book_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 40)->default('queued');
            $table->string('file_fingerprint', 64);
            $table->string('instruction_version', 40)->default('v1');
            $table->string('model', 120);
            $table->unsignedInteger('context_size')->default(8192);
            $table->unsignedInteger('page_count')->nullable();
            $table->string('injection_risk', 20)->default('none');
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['requirement_book_version_id', 'file_fingerprint', 'instruction_version', 'model'],
                'requirement_analysis_dedupe'
            );
            $table->index(['status', 'created_at']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('requirement_candidates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained('requirement_analysis_runs')->cascadeOnDelete();
            $table->string('candidate_key', 80);
            $table->string('category_name');
            $table->string('group_name');
            $table->string('type', 30);
            $table->string('title');
            $table->longText('description')->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->json('relations')->nullable();
            $table->json('ambiguities')->nullable();
            $table->string('source_locator_type', 20);
            $table->string('source_locator', 255);
            $table->text('source_excerpt');
            $table->decimal('confidence', 4, 3);
            $table->string('status', 30)->default('pending');
            $table->string('change_type', 30)->default('new');
            $table->foreignId('matched_requirement_id')->nullable()->constrained('requirements')->nullOnDelete();
            $table->json('affected_entities')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('approved_requirement_id')->nullable()->constrained('requirements')->nullOnDelete();
            $table->timestamps();
            $table->unique(['analysis_run_id', 'candidate_key']);
            $table->index(['analysis_run_id', 'status']);
            $table->index(['analysis_run_id', 'change_type']);
        });

        Schema::create('requirement_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_book_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('requirement_analysis_runs')->nullOnDelete();
            $table->string('locator_type', 20);
            $table->string('locator', 255);
            $table->text('excerpt');
            $table->decimal('confidence', 4, 3);
            $table->timestamps();
            $table->index(['requirement_id', 'requirement_book_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_sources');
        Schema::dropIfExists('requirement_candidates');
        Schema::dropIfExists('requirement_analysis_runs');
    }
};
