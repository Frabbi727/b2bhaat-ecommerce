<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\StoreSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class StoreConfigurationService
{
    /**
     * Social networks admins can link from the shop footer.
     *
     * @var array<string, string>
     */
    public const SOCIAL_NETWORKS = [
        'facebook' => 'Facebook',
        'youtube' => 'YouTube',
        'whatsapp' => 'WhatsApp',
        'linkedin' => 'LinkedIn',
    ];

    /**
     * Retrieve a typed configuration value by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return StoreSetting::getTypedValue($key, $default);
    }

    /**
     * Persist a typed configuration value.
     */
    public function set(
        string $key,
        mixed $value,
        string $group = 'general',
        string $type = 'string',
        bool $isPublic = true
    ): void {
        StoreSetting::setTypedValue($key, $value, $group, $type, $isPublic);
    }

    /**
     * Retrieve all settings grouped by domain.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllGrouped(): array
    {
        return Cache::remember('store_settings_all_grouped', 3600, function () {
            $settings = StoreSetting::all();
            $grouped = [];

            foreach ($settings as $setting) {
                $grouped[$setting->group][$setting->key] = StoreSetting::castValue($setting->value, $setting->type ?? 'string');
            }

            return $grouped;
        });
    }

    /**
     * Get store branding and identity details.
     *
     * @return array{name: string, tagline: string, logo_url: string, helpline: string, email: string, address: string, vat_bin: string, announcement: string, invoice_footer_note: string, social: array<string, string>}
     */
    public function getStoreIdentity(): array
    {
        return [
            'name' => (string) $this->get('store_name', 'B2B Haat'),
            'tagline' => (string) $this->get('store_tagline', 'Hybrid Wholesale & Retail Marketplace Platform'),
            'logo_url' => $this->getLogoUrl(),
            'helpline' => (string) $this->get('helpline_phone', ''),
            'email' => (string) $this->get('support_email', ''),
            'address' => (string) $this->get('store_address', ''),
            'vat_bin' => (string) $this->get('vat_bin_number', ''),
            'announcement' => (string) $this->get('announcement_text', ''),
            'invoice_footer_note' => (string) $this->get('invoice_footer_note', ''),
            'social' => $this->getSocialLinks(),
        ];
    }

    /**
     * Absolute URL of the uploaded shop logo, or the bundled image when none was uploaded.
     *
     * @param  string  $fallback  Bundled image to use without an upload: the square mark or the full wordmark.
     */
    public function getLogoUrl(string $fallback = 'images/brand/logo-mark.png'): string
    {
        $path = (string) $this->get('store_logo_path', '');

        return $path !== '' ? Storage::disk('public')->url($path) : asset($fallback);
    }

    /**
     * Social profile links that were filled in, keyed by network.
     *
     * @return array<string, string>
     */
    public function getSocialLinks(): array
    {
        $links = [];

        foreach (self::SOCIAL_NETWORKS as $network => $label) {
            $url = (string) $this->get("social_{$network}_url", '');

            if ($url !== '') {
                $links[$network] = $url;
            }
        }

        return $links;
    }

    /**
     * Whether seller address and phone print on customer invoices by default. A seller's own override wins.
     *
     * @return array{show_seller_address: bool, show_seller_phone: bool}
     */
    public function getInvoiceDisplaySettings(): array
    {
        return [
            'show_seller_address' => (bool) $this->get('invoice_show_seller_address', false),
            'show_seller_phone' => (bool) $this->get('invoice_show_seller_phone', false),
        ];
    }

    /**
     * Get delivery fee for a specific geographical zone.
     */
    public function getDeliveryFee(string $zone): float
    {
        return match ($zone) {
            'inside_dhaka' => (float) $this->get('delivery_fee_inside_dhaka', $this->get('delivery_charge_inside_dhaka', 70.00)),
            'sub_dhaka' => (float) $this->get('delivery_fee_sub_dhaka', 100.00),
            'outside_dhaka' => (float) $this->get('delivery_fee_outside_dhaka', $this->get('delivery_charge_outside_dhaka', 130.00)),
            default => (float) $this->get('delivery_fee_outside_dhaka', 130.00),
        };
    }

    /**
     * Get minimum cart total required for free shipping.
     */
    public function getFreeShippingThreshold(): float
    {
        return (float) $this->get('free_shipping_threshold', 10000.00);
    }

    /**
     * Get delivery SLA text for a specific zone.
     */
    public function getDeliverySla(string $zone): string
    {
        return match ($zone) {
            'inside_dhaka' => (string) $this->get('delivery_sla_inside_dhaka', '24-48 Hours'),
            'sub_dhaka' => (string) $this->get('delivery_sla_sub_dhaka', '48-72 Hours'),
            'outside_dhaka' => (string) $this->get('delivery_sla_outside_dhaka', '3-5 Business Days'),
            default => (string) $this->get('delivery_sla_outside_dhaka', '3-5 Business Days'),
        };
    }

    /**
     * Get stock threshold when low-stock alerts trigger.
     */
    public function getLowStockThreshold(): int
    {
        return (int) $this->get('low_stock_threshold', 10);
    }

    /**
     * Get stock reservation duration in minutes before returning reserved items to inventory.
     */
    public function getStockReservationTtl(): int
    {
        return (int) $this->get('stock_reservation_ttl', 15);
    }

    /**
     * Hours a bKash/Nagad payment may wait for an admin's check before admins are reminded (PAY-2).
     */
    public function getMfsVerificationReminderHours(): int
    {
        return max(1, (int) $this->get('mfs_verification_reminder_hours', 12));
    }

    /**
     * Hours after which an unchecked bKash/Nagad order is cancelled as "payment not confirmed" (PAY-2).
     */
    public function getMfsVerificationTimeoutHours(): int
    {
        return max(1, (int) $this->get('mfs_verification_timeout_hours', 72));
    }

    /**
     * Check if customers can place orders without logged-in accounts.
     */
    public function isGuestCheckoutAllowed(): bool
    {
        return (bool) $this->get('guest_checkout_enabled', true);
    }

    /**
     * Check if backorders are allowed when stock reaches zero.
     */
    public function isBackorderAllowed(): bool
    {
        return (bool) $this->get('allow_backorders', false);
    }

    /**
     * Get allowable hours from order placement during which buyer can self-cancel without penalty.
     */
    public function getOrderCancellationWindowHours(): int
    {
        return (int) $this->get('cancellation_window_hours', 24);
    }

    /**
     * Get maximum total order value allowed for Cash on Delivery.
     */
    public function getCodMaxLimit(): float
    {
        return (float) $this->get('cod_max_order_limit', 50000.00);
    }

    /**
     * Get minimum order basket value for given sales channel.
     */
    public function getMinOrderAmount(string $channel = 'retail'): float
    {
        return match ($channel) {
            'wholesale' => (float) $this->get('min_order_amount_wholesale', 5000.00),
            default => (float) $this->get('min_order_amount_retail', 200.00),
        };
    }

    /**
     * Get minimum payout withdrawal request amount.
     */
    public function getMinPayoutAmount(): float
    {
        return (float) $this->get('min_payout_amount', 500.00);
    }

    /**
     * Get holding period in hours before delivered escrow funds transition to available balance.
     */
    public function getEscrowHoldingPeriodHours(): int
    {
        return (int) $this->get('escrow_holding_period_hours', 48);
    }

    public function isMonetizationEnabled(): bool
    {
        return (bool) $this->get('monetization_enabled', true);
    }

    public function getMonetizationGracePeriodDays(): int
    {
        return (int) $this->get('monetization_default_grace_days', 3);
    }

    public function getMonetizationPreExpiryNoticeDays(): int
    {
        return (int) $this->get('monetization_pre_expiry_notice_days', 3);
    }

    public function isWalletAutoRenewAllowed(): bool
    {
        return (bool) $this->get('monetization_allow_wallet_auto_renew', true);
    }

    /**
     * MON-13: the commission a seller starts on when they sign up, and the rate charged when a seller has no plan
     * and no own rate. Previously 10% written into the code.
     */
    public function getDefaultCommissionRate(): float
    {
        return (float) $this->get('default_commission_rate', 10.00);
    }

    public function getListingAddonPackSize(): int
    {
        return (int) $this->get('monetization_addon_pack_size', 25);
    }

    public function getListingAddonPackPrice(): float
    {
        return (float) $this->get('monetization_addon_pack_price', 250.00);
    }

    public function getCodDeficitGraceDays(): int
    {
        return (int) $this->get('monetization_cod_deficit_threshold_days', 7);
    }

    public function getMonetizationBkashNumber(): ?string
    {
        return $this->get('monetization_bkash_merchant_number') ?: null;
    }

    public function getMonetizationNagadNumber(): ?string
    {
        return $this->get('monetization_nagad_merchant_number') ?: null;
    }

    public function getMonetizationBankInstructions(): ?string
    {
        return $this->get('monetization_offline_bank_details') ?: null;
    }
}
