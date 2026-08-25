<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('entry_mode', 20)->default('new')->after('priority');
            $table->string('progress_mode', 20)->default('tasks')->after('entry_mode');
            $table->dateTime('transitioned_at')->nullable()->after('end_date');
            $table->index(['progress_mode', 'archived_at']);
        });

        Schema::table('timeline_entries', function (Blueprint $table): void {
            $table->foreignId('parent_phase_id')->nullable()->after('project_id')
                ->constrained('timeline_entries')->nullOnDelete();
            $table->decimal('weight_percent', 5, 2)->nullable()->after('status');
            $table->longText('completion_criteria')->nullable()->after('weight_percent');
            $table->boolean('is_gate')->default(false)->after('completion_criteria');
            $table->dateTime('completed_at')->nullable()->after('is_gate');
            $table->foreignId('completed_by')->nullable()->after('completed_at')
                ->constrained('users')->nullOnDelete();
            $table->index(['project_id', 'kind', 'status']);
            $table->index(['parent_phase_id', 'is_gate']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('phase_id')->nullable()->after('project_id')
                ->constrained('timeline_entries')->nullOnDelete();
            $table->index(['project_id', 'phase_id', 'archived_at']);
        });

        Schema::create('requirement_timeline_entry', function (Blueprint $table): void {
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timeline_entry_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['requirement_id', 'timeline_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requirement_timeline_entry');

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['phase_id']);
            $table->dropColumn('phase_id');
        });

        Schema::table('timeline_entries', function (Blueprint $table): void {
            $table->dropForeign(['parent_phase_id']);
            $table->dropForeign(['completed_by']);
            $table->dropColumn([
                'parent_phase_id', 'weight_percent', 'completion_criteria', 'is_gate',
                'completed_at', 'completed_by',
            ]);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['entry_mode', 'progress_mode', 'transitioned_at']);
        });
    }
};
