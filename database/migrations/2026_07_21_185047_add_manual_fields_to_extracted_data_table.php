<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            $table->string('recipient_email', 255)->nullable()->after('company_name');
            $table->string('fiscal_code', 16)->nullable()->after('recipient_email');
            $table->string('employee_id', 255)->nullable()->after('fiscal_code');
        });
    }

    public function down(): void
    {
        Schema::table('extracted_data', function (Blueprint $table) {
            $table->dropColumn(['recipient_email', 'fiscal_code', 'employee_id']);
        });
    }
};
