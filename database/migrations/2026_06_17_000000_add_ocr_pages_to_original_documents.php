<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('original_documents', function (Blueprint $table): void {
            // Testo OCR per pagina ({page, text, confidence_avg}): consente al
            // classificatore di assegnare gli intervalli di pagina ai destinatari.
            $table->jsonb('ocr_pages')->nullable()->after('ocr_text');
        });
    }

    public function down(): void
    {
        Schema::table('original_documents', function (Blueprint $table): void {
            $table->dropColumn('ocr_pages');
        });
    }
};
