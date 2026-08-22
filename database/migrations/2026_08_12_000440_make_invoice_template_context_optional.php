<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->change();
            $table->date('issue_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_documents')->whereNull('client_id')->orWhereNull('issue_date')->exists()) {
            throw new LogicException(
                'Cannot restore required invoice context while templates with empty client or issue date exist.',
            );
        }

        Schema::table('sales_documents', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable(false)->change();
            $table->date('issue_date')->nullable(false)->change();
        });
    }
};
