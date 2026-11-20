<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN specifications TYPE jsonb USING specifications::jsonb;');
            DB::statement('ALTER TABLE products ALTER COLUMN features TYPE jsonb USING features::jsonb;');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE products ALTER COLUMN specifications TYPE json USING specifications::json;');
            DB::statement('ALTER TABLE products ALTER COLUMN features TYPE json USING features::json;');
        }
    }
};
