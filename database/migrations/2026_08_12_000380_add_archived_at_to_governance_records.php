<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('timeline_entries', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('timeline_entries', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('issues', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });

        Schema::table('risks', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
