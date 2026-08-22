<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->foreignId('project_id')->nullable()->after('actor_id')->constrained()->nullOnDelete();
            $table->uuid('correlation_id')->nullable()->after('request_id');
            $table->index(['project_id', 'created_at']);
            $table->index(['correlation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'created_at']);
            $table->dropIndex(['correlation_id', 'created_at']);
            $table->dropConstrainedForeignId('project_id');
            $table->dropColumn('correlation_id');
        });
    }
};
