<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('job_title')->nullable()->after('phone');
            $table->string('global_role', 40)->default('member')->after('job_title');
            $table->string('status', 30)->default('active')->after('global_role');
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->index(['global_role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['global_role', 'status']);
            $table->dropColumn(['phone', 'job_title', 'global_role', 'status', 'archived_at']);
        });
    }
};
