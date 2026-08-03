<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('status');
            $table->index(['tenant_id', 'is_favorite']);
        });
    }

    public function down(): void
    {
        Schema::table('communications', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'is_favorite']);
            $table->dropColumn('is_favorite');
        });
    }
};
