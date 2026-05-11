<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('original_documents', function (Blueprint $table) {
            $table->id();
            $table->string('file_path', 1000);
            $table->string('original_filename', 500);
            $table->string('processing_status', 20)->default('pending');
            $table->timestamps();

            $table->index('processing_status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE original_documents ADD CONSTRAINT chk_original_documents_status CHECK (processing_status IN ('pending', 'processing', 'completed', 'failed'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('original_documents');
    }
};
