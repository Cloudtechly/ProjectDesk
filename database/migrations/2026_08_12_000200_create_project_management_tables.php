<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->string('code', 60);
            $table->string('label');
            $table->string('semantic', 30);
            $table->string('color', 7);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['entity_type', 'code']);
            $table->index(['entity_type', 'position']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'archived_at']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['client_id', 'is_primary']);
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('status_id')->constrained('workflow_statuses')->restrictOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['status_id', 'priority']);
            $table->index(['manager_id', 'archived_at']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('project_role', 40)->default('member');
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->longText('acceptance_criteria')->nullable();
            $table->string('priority', 20)->default('medium');
            $table->foreignId('status_id')->constrained('workflow_statuses')->restrictOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'status_id', 'priority']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('status_id')->constrained('workflow_statuses')->restrictOnDelete();
            $table->string('priority', 20)->default('medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('due_at');
            $table->dateTime('completed_at')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'code']);
            $table->index(['project_id', 'status_id', 'due_at']);
            $table->index(['assignee_id', 'status_id', 'due_at']);
            $table->index(['start_at', 'due_at']);
        });

        Schema::create('requirement_task', function (Blueprint $table) {
            $table->foreignId('requirement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['requirement_id', 'task_id']);
        });

        Schema::create('task_assignment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at');
            $table->dateTime('recorded_at');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['task_id', 'recorded_at']);
        });

        Schema::create('timeline_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('title');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('status', 30)->default('planned');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'starts_at']);
            $table->index(['kind', 'status']);
        });

        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timeline_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organizer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('meeting_url', 2048)->nullable();
            $table->longText('agenda')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('attendance_status', 30)->default('invited');
            $table->timestamps();
            $table->unique(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('timeline_entries');
        Schema::dropIfExists('task_assignment_events');
        Schema::dropIfExists('requirement_task');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('requirements');
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('workflow_statuses');
    }
};
