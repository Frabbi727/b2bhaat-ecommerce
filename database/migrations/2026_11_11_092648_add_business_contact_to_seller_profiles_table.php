<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seller business address and phone (printed on documents), a pending copy waiting for admin approval,
     * and a per-seller override of the platform's "show seller contact on invoices" setting.
     */
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->text('business_address')->nullable()->after('tax_id_number');
            $table->string('business_phone', 32)->nullable()->after('business_address');
            $table->text('pending_business_address')->nullable()->after('business_phone');
            $table->string('pending_business_phone', 32)->nullable()->after('pending_business_address');
            $table->timestamp('contact_change_submitted_at')->nullable()->after('pending_business_phone');
            $table->boolean('show_contact_on_invoice')->nullable()->after('contact_change_submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'business_address',
                'business_phone',
                'pending_business_address',
                'pending_business_phone',
                'contact_change_submitted_at',
                'show_contact_on_invoice',
            ]);
        });
    }
};
