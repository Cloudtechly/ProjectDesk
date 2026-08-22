<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_objects', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 40)->default('local');
            $table->string('storage_key', 1024)->unique();
            $table->string('original_name');
            $table->string('mime_type', 150);
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->string('scan_status', 30)->default('pending');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->timestamps();
            $table->index(['scan_status', 'uploaded_at']);
        });

        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->unique()->constrained()->cascadeOnDelete();
            $table->longText('summary');
            $table->longText('decisions')->nullable();
            $table->longText('action_items')->nullable();
            $table->foreignId('file_object_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('recorded_at');
            $table->timestamps();
        });

        Schema::create('requirement_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('requirement_book_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requirement_book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status', 30)->default('draft');
            $table->foreignId('file_object_id')->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['requirement_book_id', 'version_number']);
            $table->index(['requirement_book_id', 'is_current']);
        });

        Schema::create('risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('probability')->default(1);
            $table->unsignedTinyInteger('impact')->default(1);
            $table->string('status', 30)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('mitigation')->nullable();
            $table->dateTime('due_at')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status']);
        });

        Schema::create('issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('medium');
            $table->string('status', 30)->default('open');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();
            $table->index(['project_id', 'status', 'severity']);
        });

        Schema::create('attachment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_object_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('requirement_book_version_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('meeting_minutes_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'created_at']);
        });

        Schema::create('sales_documents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('number', 60)->unique();
            $table->string('title');
            $table->string('status', 30)->default('draft');
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained('sales_documents')->nullOnDelete();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('reference')->nullable();
            $table->char('currency', 3)->default('LYD');
            $table->decimal('discount_rate', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->json('client_snapshot')->nullable();
            $table->json('company_snapshot')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['type', 'status', 'issue_date']);
            $table->index(['client_id', 'project_id']);
        });

        Schema::create('sales_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 40);
            $table->decimal('unit_price', 14, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['sales_document_id', 'position']);
        });

        Schema::create('proposal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('subtitle')->nullable();
            $table->longText('summary')->nullable();
            $table->longText('objectives')->nullable();
            $table->longText('deliverables')->nullable();
            $table->boolean('includes_contract')->default(false);
            $table->longText('contract_terms')->nullable();
            $table->timestamps();
        });

        Schema::create('receipt_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('receipt_type', 30)->default('receive');
            $table->string('payer');
            $table->decimal('amount', 14, 2);
            $table->string('amount_words')->nullable();
            $table->string('payment_method', 50)->nullable();
            $table->text('purpose');
            $table->timestamps();
        });

        Schema::create('letter_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient');
            $table->string('subject');
            $table->longText('body');
            $table->string('closing')->nullable();
            $table->string('signatory')->nullable();
            $table->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['document_type', 'year']);
        });

        Schema::create('data_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('resource_type', 60)->nullable();
            $table->string('format', 20)->nullable();
            $table->string('status', 30)->default('queued');
            $table->foreignId('file_object_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('summary')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->index(['type', 'status', 'created_at']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_job_id')->constrained()->cascadeOnDelete();
            $table->string('sheet')->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('field')->nullable();
            $table->string('code', 60);
            $table->text('message');
            $table->timestamps();
            $table->index(['data_job_id', 'row_number']);
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 60);
            $table->string('key', 120);
            $table->json('value');
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
            $table->unique(['group', 'key']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('request_id', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('data_jobs');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('letter_details');
        Schema::dropIfExists('receipt_details');
        Schema::dropIfExists('proposal_details');
        Schema::dropIfExists('sales_line_items');
        Schema::dropIfExists('sales_documents');
        Schema::dropIfExists('attachment_links');
        Schema::dropIfExists('issues');
        Schema::dropIfExists('risks');
        Schema::dropIfExists('requirement_book_versions');
        Schema::dropIfExists('requirement_books');
        Schema::dropIfExists('meeting_minutes');
        Schema::dropIfExists('file_objects');
    }
};
