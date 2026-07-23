<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            $table->string('mail')->nullable();
            $table->string('codice_fiscale')->nullable();
            $table->string('indirizzo_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            $table->dropColumn(['mail', 'codice_fiscale', 'indirizzo_id']);
        });
    }
};