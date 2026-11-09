<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Filament notification bell filters on `data->>'format'`, which PostgreSQL only allows on a JSON column. The
 * table was created with `data` as text (INV-8), so every admin and seller page failed there. The existing rows are
 * already JSON text, so they convert as they are. Other databases store JSON as text and need no change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
