<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_account_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('type', 20); // 'asset', 'liability', 'equity', 'revenue', 'expense'
            $table->string('code_prefix', 10);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_account_groups');
    }
};
