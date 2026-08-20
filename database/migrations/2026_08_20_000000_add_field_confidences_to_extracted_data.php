<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            // Confidenza OCR di ogni campo trascritto, presa dalla riga da cui
            // il campo proviene. Affianca confidence_score, che ne e' la sola
            // sintesi: il punteggio dice se il documento regge, questo dice
            // quale campo non regge (vedi ADR 0013).
            $table->json('field_confidences')->nullable()->after('confidence_score');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            $table->dropColumn('field_confidences');
        });
    }
};
