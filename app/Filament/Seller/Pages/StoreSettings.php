<?php

namespace App\Filament\Seller\Pages;

use App\Models\SellerProfile;
use App\Services\Settings\StoreConfigurationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Concerns\HasUnsavedDataChangesAlert;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The seller's own store controls: pausing orders (vacation mode) and overriding delivery charges.
 * Verified business details are shown read-only; only an admin can change them.
 */
class StoreSettings extends Page
{
    use HasUnsavedDataChangesAlert;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static UnitEnum|string|null $navigationGroup = 'Store';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Store settings';

    protected static ?string $title = 'Store settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $profile = $this->profile();

        $this->form->fill([
            'is_on_vacation' => (bool) $profile?->is_on_vacation,
            'vacation_message' => $profile?->vacation_message,
            'vacation_ends_at' => $profile?->vacation_ends_at?->format('Y-m-d'),
            'inside_dhaka_rate' => $profile?->inside_dhaka_rate !== null ? (float) $profile->inside_dhaka_rate : null,
            'outside_dhaka_rate' => $profile?->outside_dhaka_rate !== null ? (float) $profile->outside_dhaka_rate : null,
            'free_shipping_threshold' => $profile?->free_shipping_threshold !== null ? (float) $profile->free_shipping_threshold : null,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Pause orders while you are away and set what buyers pay for delivery.';
    }

    protected function profile(): ?SellerProfile
    {
        return auth()->user()?->sellerProfile;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
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
                    ])
                    ->columnSpan(['lg' => 2]),

                Group::make([$this->storeProfileSection()])->columnSpan(['lg' => 1]),
            ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $platform = app(StoreConfigurationService::class);
        $money = fn (float $amount): string => '৳'.number_format($amount, $amount == floor($amount) ? 0 : 2);

        return $schema
            ->statePath('data')
            ->components([
                Section::make('Store status')
                    ->description('Going on holiday or closing for stocktaking? Pause orders instead of letting buyers wait.')
                    ->icon(fn (Get $get): Heroicon => $get('is_on_vacation') ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedCheckCircle)
                    ->iconColor(fn (Get $get): string => $get('is_on_vacation') ? 'warning' : 'success')
                    ->schema([
                        Toggle::make('is_on_vacation')
                            ->label('Pause orders (vacation mode)')
                            ->helperText(fn (Get $get): string => $get('is_on_vacation')
                                ? 'Orders are paused once you save. Buyers see your notice and cannot add your products to their cart.'
                                : 'Your store is open and taking orders.')
                            ->onColor('warning')
                            ->live(),

                        Textarea::make('vacation_message')
                            ->label('Notice for buyers')
                            ->placeholder('e.g. We are closed for Eid. Orders placed after 20 April will ship from 28 April.')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Shown on your storefront and on every product page.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_on_vacation')),

                        DatePicker::make('vacation_ends_at')
                            ->label('Expected reopening date')
                            ->native(false)
                            ->displayFormat('M j, Y')
                            ->minDate(today())
                            ->helperText('Shown to buyers only. Orders stay paused until you switch vacation mode off yourself.')
                            ->visible(fn (Get $get): bool => (bool) $get('is_on_vacation'))
                            ->maxWidth('xs'),
                    ]),

                Section::make('Delivery charges')
                    ->description('Leave a field empty to use the marketplace rate. Your rates apply to the products buyers order from your store.')
                    ->icon(Heroicon::OutlinedTruck)
                    ->schema([
                        Grid::make(['default' => 1, 'sm' => 3])->schema([
                            TextInput::make('inside_dhaka_rate')
                                ->label('Inside Dhaka')
                                ->prefix('৳')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(10000)
                                ->placeholder(number_format($platform->getDeliveryFee('inside_dhaka'), 0))
                                ->helperText('Marketplace rate: '.$money($platform->getDeliveryFee('inside_dhaka'))),

                            TextInput::make('outside_dhaka_rate')
                                ->label('Outside Dhaka')
                                ->prefix('৳')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(10000)
                                ->placeholder(number_format($platform->getDeliveryFee('outside_dhaka'), 0))
                                ->helperText('Marketplace rate: '.$money($platform->getDeliveryFee('outside_dhaka'))),

                            TextInput::make('free_shipping_threshold')
                                ->label('Free delivery from')
                                ->prefix('৳')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(1000000)
                                ->placeholder(number_format($platform->getFreeShippingThreshold(), 0))
                                ->helperText('Order value from your store. Marketplace: '.$money($platform->getFreeShippingThreshold())),
                        ]),
                    ])
                    ->footer([
                        TextEntry::make('sub_dhaka_note')
                            ->hiddenLabel()
                            ->state('Orders to the Sub-Dhaka zone always use the marketplace rate of '.$money($platform->getDeliveryFee('sub_dhaka')).'.')
                            ->color('gray')
                            ->size('sm'),
                    ]),
            ]);
    }

    protected function storeProfileSection(): Section
    {
        $profile = $this->profile();
        $user = auth()->user();
        $support = app(StoreConfigurationService::class)->getStoreIdentity();
        $supportContact = collect([$support['email'] ?: null, $support['helpline'] ?: null])->filter()->implode(' or ');

        $approval = match (true) {
            (bool) $profile?->is_approved => ['Approved', 'success'],
            (bool) $profile?->rejected_at => ['Not approved', 'danger'],
            default => ['Under review', 'warning'],
        };

        return Section::make('Business details')
            ->description('Verified by the marketplace. Contact support'.($supportContact ? " ({$supportContact})" : '').' to change them.')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->schema([
                TextEntry::make('approval')
                    ->label('Account status')
                    ->state($approval[0])
                    ->badge()
                    ->color($approval[1]),

                TextEntry::make('store_name')
                    ->label('Store name')
                    ->state($profile?->store_name)
                    ->weight('medium')
                    ->url($profile?->store_slug ? route('store.show', $profile->store_slug) : null, shouldOpenInNewTab: true)
                    ->icon($profile?->store_slug ? Heroicon::OutlinedArrowTopRightOnSquare : null)
                    ->iconPosition('after')
                    ->placeholder('—'),

                TextEntry::make('trade_license_number')
                    ->label('Trade licence')
                    ->state($profile?->trade_license_number)
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('Not provided'),

                TextEntry::make('tax_id_number')
                    ->label('TIN')
                    ->state($profile?->tax_id_number)
                    ->fontFamily('mono')
                    ->copyable()
                    ->placeholder('Not provided'),

                TextEntry::make('commission')
                    ->label('Marketplace fee')
                    ->state($profile?->commission_rate_percentage !== null ? rtrim(rtrim(number_format((float) $profile->commission_rate_percentage, 2), '0'), '.').'% of each sale' : null)
                    ->placeholder('Not set'),

                TextEntry::make('contact')
                    ->label('Account contact')
                    ->state(collect([$user?->phone, $user?->email])->filter()->all())
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $profile = $this->profile();

        if (! $profile) {
            Notification::make()
                ->title('We could not find your store profile')
                ->body('Sign out and back in, or contact support if this keeps happening.')
                ->danger()
                ->send();

            return;
        }

        $isOnVacation = (bool) ($data['is_on_vacation'] ?? false);

        $profile->update([
            'is_on_vacation' => $isOnVacation,
            // Notice and date only mean something while paused; keep them so the seller can reuse them next time
            'vacation_message' => filled($data['vacation_message'] ?? null) ? $data['vacation_message'] : $profile->vacation_message,
            'vacation_ends_at' => $isOnVacation ? ($data['vacation_ends_at'] ?? null) : $profile->vacation_ends_at,
            'inside_dhaka_rate' => $data['inside_dhaka_rate'] ?? null,
            'outside_dhaka_rate' => $data['outside_dhaka_rate'] ?? null,
            'free_shipping_threshold' => $data['free_shipping_threshold'] ?? null,
        ]);

        $this->rememberData();

        Notification::make()
            ->title('Settings saved')
            ->body($isOnVacation ? 'Orders are paused. Buyers can browse but cannot check out from your store.' : 'Your store is open for orders.')
            ->success()
            ->send();
    }
}
