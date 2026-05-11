<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sub_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_document_id')->constrained('original_documents')->cascadeOnDelete();
            $table->string('file_path', 1000);
            $table->smallInteger('start_page');
            $table->smallInteger('end_page');
            $table->string('send_status', 20)->default('pending');
            $table->timestamps();

            $table->index('original_document_id');
            $table->index('send_status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE sub_documents ADD CONSTRAINT chk_sub_documents_send_status CHECK (send_status IN ('pending', 'sent'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_documents');
    }
};
