<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sub_documents', function (Blueprint $table) {
            $table->string('send_recipient_override', 255)->nullable()->after('send_status');
            $table->string('send_subject_override', 255)->nullable()->after('send_recipient_override');
            $table->text('send_body_override')->nullable()->after('send_subject_override');
        });
    }

    public function down(): void
    {
        Schema::table('sub_documents', function (Blueprint $table) {
            $table->dropColumn([
                'send_recipient_override',
                'send_subject_override',
                'send_body_override',
            ]);
        });
    }
};
