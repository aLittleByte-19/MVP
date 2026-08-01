<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            // Ciclo di vita tecnico della pipeline, distinto da "status" che
            // resta la decisione dell'operatore sulla bozza.
            $table->string('generation_status', 20)->default('pending')->after('generated_body');
            $table->string('workflow_execution_arn', 500)->nullable()->after('generation_status');
            $table->timestamp('workflow_started_at')->nullable()->after('workflow_execution_arn');
            $table->timestamp('workflow_completed_at')->nullable()->after('workflow_started_at');
            $table->timestamp('workflow_failed_at')->nullable()->after('workflow_completed_at');
            $table->text('workflow_failure_reason')->nullable()->after('workflow_failed_at');
            $table->text('error_message')->nullable()->after('workflow_failure_reason');

            // Direzione visiva prodotta dal modello testuale insieme al testo:
            // e' l'input della generazione della copertina.
            $table->text('image_prompt')->nullable()->after('generated_body');

            // La copertina vive su storage documentale: qui resta il path
            // relativo al disk, come per i sotto-documenti.
            $table->string('cover_image_path', 1000)->nullable()->after('error_message');
            $table->string('cover_image_mime', 100)->nullable()->after('cover_image_path');
            $table->unsignedInteger('cover_image_size')->nullable()->after('cover_image_mime');
            $table->string('cover_image_source', 20)->nullable()->after('cover_image_size');
            $table->string('cover_status', 20)->default('pending')->after('cover_image_source');
            $table->text('cover_error')->nullable()->after('cover_status');

            $table->index('workflow_execution_arn');
            $table->index('generation_status');
            $table->index('cover_status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE communications ADD CONSTRAINT chk_communications_generation_status CHECK (generation_status IN ('pending', 'processing', 'completed', 'failed'))");
            DB::statement("ALTER TABLE communications ADD CONSTRAINT chk_communications_cover_status CHECK (cover_status IN ('pending', 'processing', 'ready', 'failed', 'removed'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE communications DROP CONSTRAINT chk_communications_generation_status');
            DB::statement('ALTER TABLE communications DROP CONSTRAINT chk_communications_cover_status');
        }

        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['workflow_execution_arn']);
            $table->dropIndex(['generation_status']);
            $table->dropIndex(['cover_status']);
            $table->dropColumn([
                'image_prompt',
                'generation_status',
                'workflow_execution_arn',
                'workflow_started_at',
                'workflow_completed_at',
                'workflow_failed_at',
                'workflow_failure_reason',
                'error_message',
                'cover_image_path',
                'cover_image_mime',
                'cover_image_size',
                'cover_image_source',
                'cover_status',
                'cover_error',
            ]);
        });
    }
};
