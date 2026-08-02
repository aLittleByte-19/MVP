<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preset di prompt riutilizzabili (UC-19): indipendenti da una
        // generazione effettiva, servono solo a precompilare il form.
        Schema::create('prompt_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 120)->default('mvp-local-tenant');
            $table->string('created_by', 120)->nullable();
            $table->string('name', 150);
            $table->text('prompt');
            $table->string('tone', 100);
            $table->string('style', 100);
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_configurations');
    }
};
