<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_objects', function (Blueprint $table): void {
            $table->index(['uploaded_at', 'id'], 'file_objects_orphan_retention_idx');
        });
        Schema::table('attachment_links', function (Blueprint $table): void {
            $table->index(['file_object_id', 'archived_at'], 'attachment_links_file_history_idx');
        });
        Schema::table('requirement_book_versions', function (Blueprint $table): void {
            $table->index('file_object_id', 'requirement_book_versions_file_idx');
        });
        Schema::table('meeting_minutes', function (Blueprint $table): void {
            $table->index('file_object_id', 'meeting_minutes_file_idx');
        });
        Schema::table('data_jobs', function (Blueprint $table): void {
            $table->index('file_object_id', 'data_jobs_file_idx');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['project_id', 'archived_at', 'code'], 'tasks_attachment_target_idx');
        });
        Schema::table('requirements', function (Blueprint $table): void {
            $table->index(['project_id', 'archived_at', 'code'], 'requirements_attachment_target_idx');
        });
    }

    public function down(): void
    {
        Schema::table('requirements', function (Blueprint $table): void {
            $table->dropIndex('requirements_attachment_target_idx');
        });
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_attachment_target_idx');
        });
        Schema::table('data_jobs', function (Blueprint $table): void {
            $table->dropIndex('data_jobs_file_idx');
        });
        Schema::table('meeting_minutes', function (Blueprint $table): void {
            $table->dropIndex('meeting_minutes_file_idx');
        });
        Schema::table('requirement_book_versions', function (Blueprint $table): void {
            $table->dropIndex('requirement_book_versions_file_idx');
        });
        Schema::table('attachment_links', function (Blueprint $table): void {
            $table->dropIndex('attachment_links_file_history_idx');
        });
        Schema::table('file_objects', function (Blueprint $table): void {
            $table->dropIndex('file_objects_orphan_retention_idx');
        });
    }
};
