<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_document_id')->unique()->constrained('sub_documents')->cascadeOnDelete();
            $table->string('employee_first_name', 200)->nullable();
            $table->string('employee_last_name', 200)->nullable();
            $table->string('company_name', 500)->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_type', 200)->nullable();
            $table->text('description')->nullable();
            $table->smallInteger('confidence_score')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE extracted_data ADD CONSTRAINT chk_extracted_data_confidence CHECK (confidence_score IS NULL OR (confidence_score >= 0 AND confidence_score <= 100))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_data');
    }
};
