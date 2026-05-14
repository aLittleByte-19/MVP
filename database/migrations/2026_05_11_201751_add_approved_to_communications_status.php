<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE communications DROP CONSTRAINT chk_communications_status');
            DB::statement("ALTER TABLE communications ADD CONSTRAINT chk_communications_status CHECK (status IN ('draft', 'approved', 'discarded'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE communications DROP CONSTRAINT chk_communications_status');
            DB::statement("ALTER TABLE communications ADD CONSTRAINT chk_communications_status CHECK (status IN ('draft', 'discarded'))");
        }
    }
};
