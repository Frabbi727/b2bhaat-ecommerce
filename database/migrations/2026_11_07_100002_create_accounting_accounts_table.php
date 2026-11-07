<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained('accounting_account_groups')->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();
            $table->string('code', 20)->unique();
            $table->string('system_slug', 60)->nullable()->unique();
            $table->string('name', 150);
            $table->string('type', 20); // 'asset', 'liability', 'equity', 'revenue', 'expense'
            $table->string('normal_balance', 10); // 'debit', 'credit'
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
            $table->index('group_id');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_accounts');
    }
};
