<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('original_documents', function (Blueprint $table) {
            $table->string('s3_bucket', 255)->nullable()->after('error_message');
            $table->string('s3_key', 1000)->nullable()->after('s3_bucket');
            $table->string('workflow_execution_arn', 500)->nullable()->after('s3_key');
            $table->timestamp('workflow_started_at')->nullable()->after('workflow_execution_arn');
            $table->timestamp('workflow_completed_at')->nullable()->after('workflow_started_at');
            $table->timestamp('workflow_failed_at')->nullable()->after('workflow_completed_at');
            $table->text('workflow_failure_reason')->nullable()->after('workflow_failed_at');
            $table->string('textract_job_id', 255)->nullable()->after('workflow_failure_reason');
            $table->longText('ocr_text')->nullable()->after('textract_job_id');
            $table->decimal('ocr_confidence_avg', 5, 2)->nullable()->after('ocr_text');

            $table->index('workflow_execution_arn');
            $table->index('textract_job_id');
        });

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
    }

    public function down(): void
    {
        Schema::dropIfExists('document_workflow_tasks');

        Schema::table('original_documents', function (Blueprint $table) {
            $table->dropIndex(['workflow_execution_arn']);
            $table->dropIndex(['textract_job_id']);
            $table->dropColumn([
                's3_bucket',
                's3_key',
                'workflow_execution_arn',
                'workflow_started_at',
                'workflow_completed_at',
                'workflow_failed_at',
                'workflow_failure_reason',
                'textract_job_id',
                'ocr_text',
                'ocr_confidence_avg',
            ]);
        });
    }
};
