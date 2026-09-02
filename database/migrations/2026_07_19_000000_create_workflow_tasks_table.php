<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabella unica dei task di callback: il claim atomico e la dedup sul
        // token vivono in un solo posto per tutte le pipeline, il dominio e'
        // identificato dalla coppia morph subject_type/subject_id.
        Schema::create('workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->string('task_type', 80);
            $table->char('task_token_hash', 64)->unique();
            $table->string('status', 40)->default('pending');
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'task_type']);
            $table->index(['status', 'task_type']);
        });

        Schema::dropIfExists('document_workflow_tasks');
    }

    public function down(): void
    {
        Schema::create('document_workflow_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_document_id')->constrained('original_documents')->cascadeOnDelete();
            $table->string('task_type', 80);
            $table->char('task_token_hash', 64)->unique();
            $table->string('status', 40)->default('pending');
            $table->json('input_payload')->nullable();
            $table->json('output_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['original_document_id', 'task_type']);
            $table->index(['status', 'task_type']);
        });

        Schema::dropIfExists('workflow_tasks');
    }
};
