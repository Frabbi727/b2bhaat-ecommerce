<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('actor_type', 32)->default('user')->index();
            $table->foreignId('actor_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 32)->nullable()->index();
            $table->string('auth_guard', 32)->nullable();
            $table->foreignId('seller_id')->nullable()->index();
            $table->string('module', 64)->index();
            $table->string('action', 64)->index();
            $table->text('description');
            $table->string('auditable_type')->nullable()->index();
            $table->unsignedBigInteger('auditable_id')->nullable()->index();
            $table->string('record_label')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('changed_fields')->nullable();
            $table->string('status', 32)->default('success')->index();
            $table->text('failure_reason')->nullable();
            $table->boolean('is_suspicious')->default(false)->index();
            $table->string('risk_notes')->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('request_method', 10)->nullable();
            $table->text('request_url')->nullable();
            $table->string('route_name')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['seller_id', 'created_at'], 'idx_audit_seller_created');
            $table->index(['module', 'created_at'], 'idx_audit_module_created');
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_polymorphic');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
