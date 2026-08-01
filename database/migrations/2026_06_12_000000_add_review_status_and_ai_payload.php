<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_documents', function (Blueprint $table) {
            $table->string('review_status', 30)->default('needs_review')->after('send_status');
            $table->index('review_status');
        });

        Schema::table('extracted_data', function (Blueprint $table) {
            // Snapshot dell'output AI validato: la revisione manuale modifica le
            // colonne tipizzate senza perdere il dato originale del modello.
            $table->json('ai_payload')->nullable()->after('confidence_score');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sub_documents ADD CONSTRAINT chk_sub_documents_review_status CHECK (review_status IN ('needs_review', 'auto_validated', 'quarantined', 'manually_validated'))");
        }

        // Backfill: i sotto-documenti gia' estratti con confidenza alta sono
        // considerati auto-validati; 80 e' il default di MVP_CONFIDENCE_THRESHOLD.
        DB::table('sub_documents')
            ->whereIn('id', fn ($query) => $query
                ->select('sub_document_id')
                ->from('extracted_data')
                ->where('confidence_score', '>=', 80))
            ->update(['review_status' => 'auto_validated']);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE sub_documents DROP CONSTRAINT IF EXISTS chk_sub_documents_review_status');
        }

        Schema::table('sub_documents', function (Blueprint $table) {
            $table->dropIndex(['review_status']);
            $table->dropColumn('review_status');
        });

        Schema::table('extracted_data', function (Blueprint $table) {
            $table->dropColumn('ai_payload');
        });
    }
};
