<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requirement_book_versions', function (Blueprint $table) {
            $table->string('title')->nullable()->after('requirement_book_id');
            $table->unsignedInteger('lock_version')->default(1)->after('is_current');
            $table->timestamp('archived_at')->nullable()->after('lock_version');
            $table->index(['requirement_book_id', 'archived_at', 'version_number'], 'requirement_book_versions_active_index');
        });

        $existingVersions = DB::table('requirement_book_versions')
            ->join('requirement_books', 'requirement_books.id', '=', 'requirement_book_versions.requirement_book_id')
            ->get(['requirement_book_versions.id', 'requirement_books.title']);
        foreach ($existingVersions as $version) {
            DB::table('requirement_book_versions')
                ->where('id', $version->id)
                ->update(['title' => $version->title]);
        }
    }

    public function down(): void
    {
        Schema::table('requirement_book_versions', function (Blueprint $table) {
            $table->dropIndex('requirement_book_versions_active_index');
            $table->dropColumn(['title', 'lock_version', 'archived_at']);
        });
    }
};
