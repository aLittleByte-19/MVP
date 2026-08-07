<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('original_documents', function (Blueprint $table) {
            // Metadati impostati in upload: non devono essere sovrascritti dall'AI.
            $table->string('manual_document_type', 200)->nullable()->after('original_filename');
            $table->string('manual_company_name', 500)->nullable()->after('manual_document_type');
            $table->unsignedTinyInteger('manual_reference_month')->nullable()->after('manual_company_name');
            $table->unsignedSmallInteger('manual_reference_year')->nullable()->after('manual_reference_month');
        });
    }

    public function down(): void
    {
        Schema::table('original_documents', function (Blueprint $table) {
            $table->dropColumn([
                'manual_document_type',
                'manual_company_name',
                'manual_reference_month',
                'manual_reference_year',
            ]);
        });
    }
};
