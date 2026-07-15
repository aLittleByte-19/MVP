<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->string('tenant_id', 120)->default('mvp-local-tenant')->after('id');
            $table->string('created_by', 120)->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('original_documents', function (Blueprint $table) {
            $table->string('tenant_id', 120)->default('mvp-local-tenant')->after('id');
            $table->string('created_by', 120)->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'processing_status']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 120);
            $table->string('tenant_id', 120)->nullable();
            $table->string('actor_id', 120)->nullable();
            $table->string('actor_email', 255)->nullable();
            $table->string('resource_type', 120)->nullable();
            $table->string('resource_id', 120)->nullable();
            $table->string('request_id', 120)->nullable();
            $table->string('correlation_id', 120)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['resource_type', 'resource_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        Schema::table('original_documents', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'processing_status']);
            $table->dropColumn(['tenant_id', 'created_by']);
        });

        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn(['tenant_id', 'created_by']);
        });
    }
};
