<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('store_settings', 'type')) {
                $table->string('type', 30)->default('string')->after('value');
            }
            if (! Schema::hasColumn('store_settings', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_settings', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_public']);
        });
    }
};
