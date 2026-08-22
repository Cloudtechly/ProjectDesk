<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('archived_at');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('archived_at');
        });

        Schema::table('timeline_entries', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('archived_at');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('archived_at');
        });

        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(1)->after('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_minutes', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('timeline_entries', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });

        Schema::table('risks', function (Blueprint $table) {
            $table->dropColumn('lock_version');
        });
    }
};
