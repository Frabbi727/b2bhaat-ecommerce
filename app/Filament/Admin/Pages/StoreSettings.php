<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Settings\StoreConfigurationService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use UnitEnum;

class StoreSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'Store Settings';

    protected static ?string $title = 'Store Configuration & Policies';

    protected static ?string $slug = 'store-settings';

    protected static UnitEnum|string|null $navigationGroup = 'Settings & Management';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.pages.store-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public function mount(): void
    {
        $service = app(StoreConfigurationService::class);

        $this->form->fill([
            'store_name' => $service->get('store_name', 'B2B Haat'),
            'vat_bin_number' => $service->get('vat_bin_number', 'BIN-002394812-0101'),
            'helpline_phone' => $service->get('helpline_phone', '+880 9612-445566'),
            'support_email' => $service->get('support_email', 'support@b2bhaat.com'),
            'store_address' => $service->get('store_address', 'Level 4, B2Bhaat Tower, 45 Motijheel C/A, Dhaka-1000'),
            'announcement_text' => $service->get('announcement_text', 'Mega Electronics Fest: Up to 35% off on Mini LED Smart TVs & Audio!'),

            'delivery_fee_inside_dhaka' => $service->getDeliveryFee('inside_dhaka'),
            'delivery_fee_sub_dhaka' => $service->getDeliveryFee('sub_dhaka'),
            'delivery_fee_outside_dhaka' => $service->getDeliveryFee('outside_dhaka'),
            'free_shipping_threshold' => $service->getFreeShippingThreshold(),
            'delivery_sla_inside_dhaka' => $service->getDeliverySla('inside_dhaka'),
            'delivery_sla_sub_dhaka' => $service->getDeliverySla('sub_dhaka'),
            'delivery_sla_outside_dhaka' => $service->getDeliverySla('outside_dhaka'),

            'low_stock_threshold' => $service->getLowStockThreshold(),
            'stock_reservation_ttl' => $service->getStockReservationTtl(),
            'allow_backorders' => $service->isBackorderAllowed(),

            'guest_checkout_enabled' => $service->isGuestCheckoutAllowed(),
            'cancellation_window_hours' => $service->getOrderCancellationWindowHours(),
            'min_order_amount_retail' => $service->getMinOrderAmount('retail'),
            'min_order_amount_wholesale' => $service->getMinOrderAmount('wholesale'),
            'cod_max_order_limit' => $service->getCodMaxLimit(),

            'min_payout_amount' => $service->getMinPayoutAmount(),
            'escrow_holding_period_hours' => $service->getEscrowHoldingPeriodHours(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Tabs::make('Store Configuration Tabs')
                    ->tabs([
                        Tab::make('Store Identity & Contacts')
                            ->icon('heroicon-o-building-storefront')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('store_name')
                                        ->label('Store Name')
                                        ->placeholder('e.g. B2B Haat')
                                        ->required(),

                                    TextInput::make('vat_bin_number')
                                        ->label('VAT / BIN Registration Number')
                                        ->placeholder('BIN-002394812-0101')
                                        ->required(),

                                    TextInput::make('helpline_phone')
                                        ->label('Customer Helpline Phone')
                                        ->placeholder('+880 9612-445566')
                                        ->required(),

                                    TextInput::make('support_email')
                                        ->label('Support Email Address')
                                        ->email()
                                        ->placeholder('support@b2bhaat.com')
                                        ->required(),
                                ]),

                                TextInput::make('store_address')
                                    ->label('Registered Corporate Address')
                                    ->placeholder('e.g. Level 4, B2Bhaat Tower, Dhaka-1000')
                                    ->required(),

                                TextInput::make('announcement_text')
                                    ->label('Header Promo Marquee / Announcement Text')
                                    ->placeholder('Special discount announcement...'),
                            ]),

                        Tab::make('Delivery & Freight Zones')
                            ->icon('heroicon-o-truck')
                            ->schema([
                                Grid::make(3)->schema([
                                    TextInput::make('delivery_fee_inside_dhaka')
                                        ->label('Inside Dhaka Fee')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),

                                    TextInput::make('delivery_fee_sub_dhaka')
                                        ->label('Sub-Dhaka / Adjacent Zone Fee')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),

                                    TextInput::make('delivery_fee_outside_dhaka')
                                        ->label('Outside Dhaka / Nationwide Fee')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),
                                ]),

                                Grid::make(2)->schema([
                                    TextInput::make('free_shipping_threshold')
                                        ->label('Free Shipping Order Threshold')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),

                                    TextInput::make('delivery_sla_inside_dhaka')
                                        ->label('Inside Dhaka Delivery SLA')
                                        ->required(),
                                ]),

                                Grid::make(2)->schema([
                                    TextInput::make('delivery_sla_sub_dhaka')
                                        ->label('Sub-Dhaka Delivery SLA')
                                        ->required(),

                                    TextInput::make('delivery_sla_outside_dhaka')
                                        ->label('Outside Dhaka Delivery SLA')
                                        ->required(),
                                ]),
                            ]),

                        Tab::make('Inventory & Stock Controls')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('low_stock_threshold')
                                        ->label('Low Stock Warning Threshold')
                                        ->numeric()
                                        ->required(),

                                    TextInput::make('stock_reservation_ttl')
                                        ->label('Stock Cart Reservation TTL')
                                        ->suffix('minutes')
                                        ->numeric()
                                        ->required(),
                                ]),

                                Toggle::make('allow_backorders')
                                    ->label('Allow Backorders When Inventory is Zero'),
                            ]),

                        Tab::make('Checkout & Order Policies')
                            ->icon('heroicon-o-shopping-bag')
                            ->schema([
                                Toggle::make('guest_checkout_enabled')
                                    ->label('Enable Guest Checkout Without Registration'),

                                Grid::make(2)->schema([
                                    TextInput::make('cancellation_window_hours')
                                        ->label('Buyer Self-Service Cancellation Window')
                                        ->suffix('hours')
                                        ->numeric()
                                        ->required(),

                                    TextInput::make('cod_max_order_limit')
                                        ->label('Cash on Delivery (COD) Maximum Order Ceilings')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),
                                ]),

                                Grid::make(2)->schema([
                                    TextInput::make('min_order_amount_retail')
                                        ->label('Minimum Retail Order Amount')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),

                                    TextInput::make('min_order_amount_wholesale')
                                        ->label('Minimum Wholesale Order Amount')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->required(),
                                ]),
                            ]),

                        Tab::make('Escrow & Financial Settlement')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Grid::make(2)->schema([
                                    TextInput::make('min_payout_amount')
                                        ->label('Minimum Seller Payout Request Amount')
                                        ->numeric()
                                        ->prefix('৳')
                                        ->helperText('Sellers cannot request withdrawals below this limit.')
                                        ->required(),

                                    TextInput::make('escrow_holding_period_hours')
                                        ->label('Delivered Escrow Maturity Period')
                                        ->suffix('hours')
                                        ->numeric()
                                        ->helperText('Hours after delivery before funds automatically mature into available balance.')
                                        ->required(),
                                ]),
                            ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->data ?? $this->form->getState();

        $service = app(StoreConfigurationService::class);

        // General
        if (isset($state['store_name'])) {
            $service->set('store_name', $state['store_name'], 'general', 'string');
        }
        if (isset($state['vat_bin_number'])) {
            $service->set('vat_bin_number', $state['vat_bin_number'], 'general', 'string');
        }
        if (isset($state['helpline_phone'])) {
            $service->set('helpline_phone', $state['helpline_phone'], 'general', 'string');
        }
        if (isset($state['support_email'])) {
            $service->set('support_email', $state['support_email'], 'general', 'string');
        }
        if (isset($state['store_address'])) {
            $service->set('store_address', $state['store_address'], 'general', 'string');
        }
        if (isset($state['announcement_text'])) {
            $service->set('announcement_text', $state['announcement_text'], 'general', 'string');
        }

        // Delivery
        if (isset($state['delivery_fee_inside_dhaka'])) {
            $fee = (float) $state['delivery_fee_inside_dhaka'];
            $service->set('delivery_fee_inside_dhaka', $fee, 'delivery', 'float');
            $service->set('delivery_charge_inside_dhaka', $fee, 'delivery', 'float');
        }
        if (isset($state['delivery_fee_sub_dhaka'])) {
            $service->set('delivery_fee_sub_dhaka', (float) $state['delivery_fee_sub_dhaka'], 'delivery', 'float');
        }
        if (isset($state['delivery_fee_outside_dhaka'])) {
            $fee = (float) $state['delivery_fee_outside_dhaka'];
            $service->set('delivery_fee_outside_dhaka', $fee, 'delivery', 'float');
            $service->set('delivery_charge_outside_dhaka', $fee, 'delivery', 'float');
        }
        if (isset($state['free_shipping_threshold'])) {
            $threshold = (float) $state['free_shipping_threshold'];
            $service->set('free_shipping_threshold', $threshold, 'delivery', 'float');
            $service->set('free_delivery_threshold', $threshold, 'delivery', 'float');
        }
        if (isset($state['delivery_sla_inside_dhaka'])) {
            $service->set('delivery_sla_inside_dhaka', $state['delivery_sla_inside_dhaka'], 'delivery', 'string');
        }
        if (isset($state['delivery_sla_sub_dhaka'])) {
            $service->set('delivery_sla_sub_dhaka', $state['delivery_sla_sub_dhaka'], 'delivery', 'string');
        }
        if (isset($state['delivery_sla_outside_dhaka'])) {
            $service->set('delivery_sla_outside_dhaka', $state['delivery_sla_outside_dhaka'], 'delivery', 'string');
        }

        // Inventory
        if (isset($state['low_stock_threshold'])) {
            $service->set('low_stock_threshold', (int) $state['low_stock_threshold'], 'inventory', 'integer');
        }
        if (isset($state['stock_reservation_ttl'])) {
            $service->set('stock_reservation_ttl', (int) $state['stock_reservation_ttl'], 'inventory', 'integer');
        }
        if (isset($state['allow_backorders'])) {
            $service->set('allow_backorders', (bool) $state['allow_backorders'], 'inventory', 'boolean');
        }

        // Checkout
        if (isset($state['guest_checkout_enabled'])) {
            $service->set('guest_checkout_enabled', (bool) $state['guest_checkout_enabled'], 'checkout', 'boolean');
        }
        if (isset($state['cancellation_window_hours'])) {
            $service->set('cancellation_window_hours', (int) $state['cancellation_window_hours'], 'checkout', 'integer');
        }
        if (isset($state['min_order_amount_retail'])) {
            $service->set('min_order_amount_retail', (float) $state['min_order_amount_retail'], 'checkout', 'float');
        }
        if (isset($state['min_order_amount_wholesale'])) {
            $service->set('min_order_amount_wholesale', (float) $state['min_order_amount_wholesale'], 'checkout', 'float');
        }
        if (isset($state['cod_max_order_limit'])) {
            $service->set('cod_max_order_limit', (float) $state['cod_max_order_limit'], 'checkout', 'float');
        }

        // Financial & Settlement
        if (isset($state['min_payout_amount'])) {
            $service->set('min_payout_amount', (float) $state['min_payout_amount'], 'financial', 'float');
        }
        if (isset($state['escrow_holding_period_hours'])) {
            $service->set('escrow_holding_period_hours', (int) $state['escrow_holding_period_hours'], 'financial', 'integer');
        }

        Notification::make()
            ->title('Store settings saved successfully')
            ->body('Dynamic store configuration and business policies have been updated.')
            ->success()
            ->send();
    }
}
