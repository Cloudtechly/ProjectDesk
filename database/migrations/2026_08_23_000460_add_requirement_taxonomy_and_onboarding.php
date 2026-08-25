<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requirement_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'position']);
        });

        Schema::create('requirement_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('requirement_categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['category_id', 'name']);
            $table->index(['project_id', 'category_id', 'position']);
        });

        Schema::table('requirements', function (Blueprint $table): void {
            $table->foreignId('group_id')->nullable()->after('project_id')
                ->constrained('requirement_groups')->nullOnDelete();
            $table->string('type', 30)->default('functional')->after('acceptance_criteria');
            $table->index(['project_id', 'group_id', 'type']);
        });

        Schema::create('requirement_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_requirement_id')->constrained('requirements')->cascadeOnDelete();
            $table->foreignId('target_requirement_id')->constrained('requirements')->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['source_requirement_id', 'target_requirement_id', 'type'], 'requirement_relation_unique');
            $table->index(['project_id', 'type']);
        });

        Schema::create('taxonomy_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->json('tree');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_onboarding_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_onboarding_snapshots');
        Schema::dropIfExists('taxonomy_templates');
        Schema::dropIfExists('requirement_relations');

        Schema::table('requirements', function (Blueprint $table): void {
            $table->dropForeign(['group_id']);
            $table->dropColumn(['group_id', 'type']);
        });

        Schema::dropIfExists('requirement_groups');
        Schema::dropIfExists('requirement_categories');
    }
};
