<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Services\Settings\StoreConfigurationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class StoreSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Store settings';

    protected static ?string $title = 'Store settings';

    protected static ?string $slug = 'store-settings';

    public function getSubheading(): ?string
    {
        return 'Rules and details the whole shop uses. Changes apply as soon as you save.';
    }

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
            'vat_bin_number' => $service->get('vat_bin_number', ''),
            'helpline_phone' => $service->get('helpline_phone', ''),
            'support_email' => $service->get('support_email', ''),
            'store_address' => $service->get('store_address', ''),
            'announcement_text' => $service->get('announcement_text', ''),

            'delivery_fee_inside_dhaka' => $service->getDeliveryFee('inside_dhaka'),
            'delivery_fee_sub_dhaka' => $service->getDeliveryFee('sub_dhaka'),
            'delivery_fee_outside_dhaka' => $service->getDeliveryFee('outside_dhaka'),
            'free_shipping_threshold' => $service->getFreeShippingThreshold(),
            'delivery_sla_inside_dhaka' => $service->getDeliverySla('inside_dhaka'),
            'delivery_sla_sub_dhaka' => $service->getDeliverySla('sub_dhaka'),
            'delivery_sla_outside_dhaka' => $service->getDeliverySla('outside_dhaka'),

            'low_stock_threshold' => $service->getLowStockThreshold(),
            'stock_reservation_ttl' => $service->getStockReservationTtl(),
            'mfs_verification_reminder_hours' => $service->getMfsVerificationReminderHours(),
            'mfs_verification_timeout_hours' => $service->getMfsVerificationTimeoutHours(),
            'allow_backorders' => $service->isBackorderAllowed(),

            'guest_checkout_enabled' => $service->isGuestCheckoutAllowed(),
            'cancellation_window_hours' => $service->getOrderCancellationWindowHours(),
            'min_order_amount_retail' => $service->getMinOrderAmount('retail'),
            'min_order_amount_wholesale' => $service->getMinOrderAmount('wholesale'),
            'cod_max_order_limit' => $service->getCodMaxLimit(),

            'min_payout_amount' => $service->getMinPayoutAmount(),
            'escrow_holding_period_hours' => $service->getEscrowHoldingPeriodHours(),

            'bkash_merchant_number' => (string) $service->get('bkash_merchant_number', ''),
            'nagad_merchant_number' => (string) $service->get('nagad_merchant_number', ''),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')
                            ->label('Save settings')
                            ->submit('save')
                            ->keyBindings(['mod+s']),
                    ]),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->schema([
                Tabs::make('Settings')
                    ->vertical()
                    ->persistTabInQueryString('section')
                    ->tabs([
                        $this->shopDetailsTab(),
                        $this->deliveryTab(),
                        $this->checkoutTab(),
                        $this->mobilePaymentsTab(),
                        $this->stockTab(),
                        $this->sellerPayoutsTab(),
                    ]),
            ]);
    }

    private function shopDetailsTab(): Tab
    {
        return Tab::make('Shop details')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->schema([
                Section::make('How shoppers reach you')
                    ->description('Shown in the shop header and footer, the Contact page and on every invoice.')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            TextInput::make('store_name')
                                ->label('Shop name')
                                ->helperText('Also the name at the top of the admin and seller panels.')
                                ->maxLength(100)
                                ->required(),

                            TextInput::make('helpline_phone')
                                ->label('Helpline number')
                                ->tel()
                                ->placeholder('+880 1XXX-XXXXXX')
                                ->maxLength(30),

                            TextInput::make('support_email')
                                ->label('Support email')
                                ->email()
                                ->placeholder('support@yourdomain.com')
                                ->maxLength(255),

                            TextInput::make('vat_bin_number')
                                ->label('VAT / BIN number')
                                ->helperText('Printed on invoices.')
                                ->placeholder('000000000-0101')
                                ->maxLength(50),
                        ]),

                        Textarea::make('store_address')
                            ->label('Office address')
                            ->helperText('Printed on invoices and the Contact page.')
                            ->rows(2)
                            ->maxLength(500),
                    ]),

                Section::make('Announcement')
                    ->schema([
                        TextInput::make('announcement_text')
                            ->label('Message in the top bar')
                            ->placeholder('e.g. Eid sale: up to 35% off TVs and audio')
                            ->helperText('One short line at the top of every shop page. Leave empty to show nothing.')
                            ->maxLength(150),
                    ]),
            ]);
    }

    private function deliveryTab(): Tab
    {
        $zone = fn (string $key, string $label, string $area): Section => Section::make($label)
            ->description($area)
            ->compact()
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                TextInput::make("delivery_fee_{$key}")
                    ->label('Delivery fee')
                    ->numeric()
                    ->minValue(0)
                    ->prefix('৳')
                    ->required(),

                TextInput::make("delivery_sla_{$key}")
                    ->label('Delivery time shown to shoppers')
                    ->placeholder('e.g. 1-2 days')
                    ->maxLength(50)
                    ->required(),
            ]);

        return Tab::make('Delivery')
            ->icon(Heroicon::OutlinedTruck)
            ->schema([
                Callout::make('Charged once for each seller in the order')
                    ->description('A basket from two sellers arrives as two parcels and pays two delivery fees. These are the default prices; a seller can set their own on their store settings.')
                    ->info(),

                $zone('inside_dhaka', 'Inside Dhaka', 'Dhaka city.'),
                $zone('sub_dhaka', 'Around Dhaka', 'Savar, Gazipur, Narayanganj and Keraniganj.'),
                $zone('outside_dhaka', 'Outside Dhaka', 'All other districts.'),

                Section::make('Free delivery')
                    ->compact()
                    ->schema([
                        TextInput::make('free_shipping_threshold')
                            ->label('Free when a seller\'s items cost at least')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('৳')
                            ->helperText('Counted per seller, not for the whole basket. Sellers can set their own amount.')
                            ->required(),
                    ]),
            ]);
    }

    private function checkoutTab(): Tab
    {
        return Tab::make('Checkout')
            ->icon(Heroicon::OutlinedShoppingBag)
            ->schema([
                Section::make('Who can order')
                    ->schema([
                        Toggle::make('guest_checkout_enabled')
                            ->label('Let shoppers order without an account')
                            ->helperText('When off, shoppers sign in with their phone number before paying.'),
                    ]),

                Section::make('Order limits')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('min_order_amount_retail')
                            ->label('Smallest retail order')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('৳')
                            ->helperText('Item total before delivery. 0 means no minimum.')
                            ->required(),

                        TextInput::make('min_order_amount_wholesale')
                            ->label('Smallest wholesale order')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('৳')
                            ->helperText('For approved wholesale buyers.')
                            ->required(),

                        TextInput::make('cod_max_order_limit')
                            ->label('Largest cash on delivery order')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('৳')
                            ->helperText('Bigger orders must be paid online first.')
                            ->required(),

                        TextInput::make('cancellation_window_hours')
                            ->label('Shoppers can cancel for')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('hours')
                            ->helperText('After ordering. Later, they must contact support.')
                            ->required(),
                    ]),
            ]);
    }

    private function mobilePaymentsTab(): Tab
    {
        return Tab::make('bKash & Nagad')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->schema([
                Section::make('Numbers that receive payments')
                    ->description('Shoppers send money to these numbers and enter the TrxID. Leave a number empty to hide that option at checkout.')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('bkash_merchant_number')
                            ->label('bKash number')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('01XXX-XXXXXX'),

                        TextInput::make('nagad_merchant_number')
                            ->label('Nagad number')
                            ->tel()
                            ->maxLength(20)
                            ->placeholder('01XXX-XXXXXX'),
                    ]),
            ]);
    }

    private function stockTab(): Tab
    {
        return Tab::make('Stock')
            ->icon(Heroicon::OutlinedCube)
            ->schema([
                Section::make('Stock rules')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('low_stock_threshold')
                            ->label('Warn sellers when stock falls to')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->suffix('pieces')
                            ->helperText('Shown as a low stock alert in the seller panel.')
                            ->required(),

                        TextInput::make('stock_reservation_ttl')
                            ->label('Cancel unpaid online orders after')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('minutes')
                            ->helperText('Their stock goes back on sale. Cash on delivery orders and payments waiting for your check are not affected.')
                            ->required(),

                        TextInput::make('mfs_verification_reminder_hours')
                            ->label('Remind admins about unchecked bKash/Nagad payments after')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('hours')
                            ->helperText('Admins get a notification in the panel. Nothing is cancelled.')
                            ->required(),

                        TextInput::make('mfs_verification_timeout_hours')
                            ->label('Cancel unchecked bKash/Nagad orders after')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->gt('mfs_verification_reminder_hours')
                            ->suffix('hours')
                            ->helperText('The order is cancelled as "payment not confirmed" and its stock goes back on sale. The buyer is told by SMS to contact support with their TrxID if they did pay.')
                            ->validationMessages(['gt' => 'Cancel after must be longer than the reminder time.'])
                            ->required(),

                        Toggle::make('allow_backorders')
                            ->label('Allow orders when stock is 0')
                            ->helperText('When off, shoppers cannot add more than the seller has in stock.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private function sellerPayoutsTab(): Tab
    {
        return Tab::make('Seller payouts')
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                Section::make('When sellers get paid')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('escrow_holding_period_hours')
                            ->label('Hold money after delivery for')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('hours')
                            ->helperText('The shopper can report a problem in this time. Then the money moves to the seller\'s balance.')
                            ->required(),

                        TextInput::make('min_payout_amount')
                            ->label('Smallest payout request')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('৳')
                            ->helperText('Sellers cannot withdraw less than this.')
                            ->required(),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $service = app(StoreConfigurationService::class);

        // Optional text fields come back as null when cleared; save them as empty so clearing actually works.
        $text = fn (string $key): string => trim((string) ($state[$key] ?? ''));

        foreach (['store_name', 'vat_bin_number', 'helpline_phone', 'support_email', 'store_address', 'announcement_text'] as $key) {
            $service->set($key, $text($key), 'general', 'string');
        }

        foreach (['inside_dhaka', 'sub_dhaka', 'outside_dhaka'] as $zone) {
            $service->set("delivery_fee_{$zone}", (float) $state["delivery_fee_{$zone}"], 'delivery', 'float');
            $service->set("delivery_sla_{$zone}", $text("delivery_sla_{$zone}"), 'delivery', 'string');
        }
        // Older key names, still read as fallbacks
        $service->set('delivery_charge_inside_dhaka', (float) $state['delivery_fee_inside_dhaka'], 'delivery', 'float');
        $service->set('delivery_charge_outside_dhaka', (float) $state['delivery_fee_outside_dhaka'], 'delivery', 'float');
        $service->set('free_shipping_threshold', (float) $state['free_shipping_threshold'], 'delivery', 'float');
        $service->set('free_delivery_threshold', (float) $state['free_shipping_threshold'], 'delivery', 'float');

        $service->set('low_stock_threshold', (int) $state['low_stock_threshold'], 'inventory', 'integer');
        $service->set('stock_reservation_ttl', (int) $state['stock_reservation_ttl'], 'inventory', 'integer');
        $service->set('mfs_verification_reminder_hours', (int) $state['mfs_verification_reminder_hours'], 'payments', 'integer');
        $service->set('mfs_verification_timeout_hours', (int) $state['mfs_verification_timeout_hours'], 'payments', 'integer');
        $service->set('allow_backorders', (bool) $state['allow_backorders'], 'inventory', 'boolean');

        $service->set('guest_checkout_enabled', (bool) $state['guest_checkout_enabled'], 'checkout', 'boolean');
        $service->set('cancellation_window_hours', (int) $state['cancellation_window_hours'], 'checkout', 'integer');
        $service->set('min_order_amount_retail', (float) $state['min_order_amount_retail'], 'checkout', 'float');
        $service->set('min_order_amount_wholesale', (float) $state['min_order_amount_wholesale'], 'checkout', 'float');
        $service->set('cod_max_order_limit', (float) $state['cod_max_order_limit'], 'checkout', 'float');

        $service->set('min_payout_amount', (float) $state['min_payout_amount'], 'financial', 'float');
        $service->set('escrow_holding_period_hours', (int) $state['escrow_holding_period_hours'], 'financial', 'integer');

        // An empty number hides that payment method at checkout
        foreach (['bkash_merchant_number', 'nagad_merchant_number'] as $key) {
            $service->set($key, $text($key), 'payments', 'string');
        }

        Notification::make()
            ->title('Settings saved')
            ->body('The shop uses the new settings straight away.')
            ->success()
            ->send();
    }
}
