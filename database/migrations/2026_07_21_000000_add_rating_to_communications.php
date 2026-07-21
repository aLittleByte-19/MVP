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
            $table->unsignedTinyInteger('rating')->nullable()->after('status');
            $table->text('rating_comment')->nullable()->after('rating');
            $table->timestamp('rated_at')->nullable()->after('rating_comment');
            $table->string('rated_by', 120)->nullable()->after('rated_at');
        });

        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE communications ADD CONSTRAINT chk_communications_rating CHECK (rating IS NULL OR (rating >= 1 AND rating <= 5))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE communications DROP CONSTRAINT IF EXISTS chk_communications_rating');
        }

        Schema::table('communications', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_comment', 'rated_at', 'rated_by']);
        });
    }
};
