<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('original_documents', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('processing_status');
        });
    }

    public function down(): void
    {
        Schema::table('original_documents', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
