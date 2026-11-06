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
        Schema::create('email_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('recipient_email')->index();
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->string('template_key', 100)->index();
            $table->string('mailable_class');
            $table->string('status', 20)->default('pending')->index(); // 'pending', 'sent', 'failed', 'skipped'
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('retries_count')->default(0);
            $table->nullableMorphs('related');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name');
            $table->string('subject_en');
            $table->string('subject_bn')->nullable();
            $table->text('content_en');
            $table->text('content_bn')->nullable();
            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('email_logs');
    }
};
