<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachment_links', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('meeting_minutes_id');
            $table->index(['project_id', 'archived_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attachment_links', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'archived_at', 'created_at']);
            $table->dropColumn('archived_at');
        });
    }
};
