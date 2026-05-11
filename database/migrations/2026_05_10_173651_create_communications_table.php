<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communications', function (Blueprint $table) {
            $table->id();
            $table->text('prompt');
            $table->string('tone', 100);
            $table->string('style', 100);
            $table->text('generated_title')->nullable();
            $table->text('generated_body')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestamps();

            $table->index('status');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE communications ADD CONSTRAINT chk_communications_status CHECK (status IN ('draft', 'discarded'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communications');
    }
};
