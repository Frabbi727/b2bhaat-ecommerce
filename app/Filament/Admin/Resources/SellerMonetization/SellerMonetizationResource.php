<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerMonetization;

use App\Filament\Admin\Resources\SellerMonetization\Pages\ListSellerMonetizations;
use App\Models\SellerInvoice;
use App\Models\SellerProfile;
use App\Models\SellerSubscription;
use App\Services\Formatting\Money;
use App\Services\Monetization\MonetizationSuspensionService;
use App\Services\Monetization\SellerInvoiceSettlementService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class SellerMonetizationResource extends Resource
{
    protected static ?string $model = SellerProfile::class;

    protected static ?string $slug = 'seller-monetization';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static UnitEnum|string|null $navigationGroup = 'Monetization & Plans';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Seller Monetization & Billing';

    protected static ?string $modelLabel = 'seller billing';

    protected static ?string $pluralModelLabel = 'seller monetization';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'user.activeSellerSubscription.plan',
                'user.sellerInvoices',
            ])->withCount(['products' => fn ($q) => $q->where('products.is_active', true)]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('store_name')
                    ->label('Store')
                    ->description(fn (SellerProfile $record): ?string => $record->user?->name)
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('user.activeSellerSubscription.plan.name')
                    ->label('Current Plan')
                    ->placeholder('No Plan')
                    ->badge(),

                TextColumn::make('monetization_status')
                    ->label('Billing Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'grace_period' => 'warning',
                        'suspended' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('products_count')
                    ->label('Active Listings / Quota')
                    ->formatStateUsing(function ($state, SellerProfile $record): string {
                        $sub = $record->user?->activeSellerSubscription;
                        $limit = $sub?->termListingLimitEnabled() ? $sub->termMaxActiveProducts() : null;
                        $limitText = $limit ? "{$limit}" : '∞';

                        return "{$state} / {$limitText}";
                    }),

                TextColumn::make('outstanding_balance')
                    ->label('Unpaid Invoices (৳)')
                    ->state(fn (SellerProfile $record): float => (float) SellerInvoice::where('seller_id', $record->user_id)
                        ->where('status', SellerInvoice::STATUS_UNPAID)
                        ->sum('total_amount'))
                    ->formatStateUsing(fn ($state): string => Money::taka($state))
                    ->color(fn ($state): string => (float) $state > 0 ? 'danger' : 'gray'),
            ])
            ->recordActions([
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (SellerProfile $record): bool => $record->monetization_status !== 'suspended')
                    ->modalHeading('Suspend Seller Selling Privileges')
                    ->modalDescription('This will immediately snapshot all active products, set them inactive, and hide the store from the customer-facing storefront.')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Suspension Reason')
                            ->required()
                            ->minLength(5)
                            ->maxLength(500),
                    ])
                    ->action(function (SellerProfile $record, array $data): void {
                        if ($record->user) {
                            app(MonetizationSuspensionService::class)->suspendSeller($record->user, $data['reason'], auth()->user());
                            Notification::make()->title('Seller successfully suspended')->success()->send();
                        }
                    }),

                Action::make('restore')
                    ->label('Restore')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (SellerProfile $record): bool => $record->monetization_status === 'suspended')
                    ->action(function (SellerProfile $record): void {
                        if ($record->user) {
                            $restored = app(MonetizationSuspensionService::class)->restoreSeller($record->user, auth()->user());
                            Notification::make()
                                ->title('Seller successfully restored')
                                ->body(count($restored).' previously active products have been reactivated.')
                                ->success()
                                ->send();
                        }
                    }),

                ActionGroup::make([
                    Action::make('recordOfflinePayment')
                        ->label('Record Offline Payment')
                        ->icon(Heroicon::OutlinedCurrencyBangladeshi)
                        ->color('primary')
                        ->schema([
                            Select::make('invoice_id')
                                ->label('Bill being paid')
                                ->options(fn (SellerProfile $record): array => self::unpaidBillOptions($record))
                                ->default(fn (SellerProfile $record): ?int => array_key_first(self::unpaidBillOptions($record)))
                                ->helperText('The payment must be for the full bill amount.')
                                ->required(),

                            Select::make('payment_method')
                                ->label('Payment Channel')
                                ->options([
                                    'bkash' => 'bKash MFS',
                                    'nagad' => 'Nagad MFS',
                                    'bank_wire' => 'Bank Wire / Deposit',
                                    'admin_waiver' => 'Admin Fee Waiver',
                                ])
                                ->live()
                                ->required(),

                            TextInput::make('amount')
                                ->label('Amount Received (৳)')
                                ->numeric()
                                ->prefix('৳')
                                ->hidden(fn (Get $get): bool => $get('payment_method') === 'admin_waiver')
                                ->required(fn (Get $get): bool => $get('payment_method') !== 'admin_waiver'),

                            TextInput::make('transaction_reference')
                                ->label('TrxID / Deposit Slip #')
                                ->helperText('Each reference can be recorded only once.')
                                ->maxLength(100)
                                ->hidden(fn (Get $get): bool => $get('payment_method') === 'admin_waiver')
                                ->required(fn (Get $get): bool => $get('payment_method') !== 'admin_waiver'),

                            Textarea::make('notes')
                                ->label('Admin Verification Notes')
                                ->maxLength(500),
                        ])
                        ->action(function (SellerProfile $record, array $data): void {
                            $seller = $record->user;
                            if (! $seller) {
                                return;
                            }

                            $invoice = SellerInvoice::where('seller_id', $seller->id)
                                ->where('status', SellerInvoice::STATUS_UNPAID)
                                ->find($data['invoice_id'] ?? null);

                            if (! $invoice) {
                                Notification::make()
                                    ->title('No unpaid bill')
                                    ->body('This seller has no unpaid bill, so nothing was recorded.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            try {
                                app(SellerInvoiceSettlementService::class)->recordOfflinePayment($seller, $invoice->id, $data, auth()->user());
                            } catch (DomainException $exception) {
                                Notification::make()->title('Payment not recorded')->body($exception->getMessage())->danger()->send();

                                return;
                            }

                            Notification::make()->title('Offline payment recorded and seller account updated')->success()->send();
                        }),

                    Action::make('extendGrace')
                        ->label('Extend Grace Period')
                        ->icon(Heroicon::OutlinedClock)
                        ->schema([
                            TextInput::make('days')
                                ->label('Extra Grace Days')
                                ->numeric()
                                ->default(3)
                                ->required(),
                        ])
                        ->visible(fn (SellerProfile $record): bool => $record->monetization_status === 'grace_period')
                        ->action(function (SellerProfile $record, array $data): void {
                            $sub = $record->user?->activeSellerSubscription;
                            if ($sub) {
                                $currentGrace = $sub->grace_period_ends_at ?? now();
                                $sub->update([
                                    'grace_period_ends_at' => $currentGrace->addDays((int) $data['days']),
                                    'status' => SellerSubscription::STATUS_GRACE_PERIOD,
                                ]);
                                $record->update(['monetization_status' => 'grace_period']);
                                Notification::make()->title('Grace period extended')->success()->send();
                            }
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerMonetizations::route('/'),
        ];
    }

    /**
     * The seller's unpaid bills, newest first, labelled with number, type, amount and due date (MON-6).
     *
     * @return array<int, string>
     */
    public static function unpaidBillOptions(SellerProfile $record): array
    {
        if (! $record->user_id) {
            return [];
        }

        return SellerInvoice::where('seller_id', $record->user_id)
            ->where('status', SellerInvoice::STATUS_UNPAID)
            ->latest('id')
            ->get()
            ->mapWithKeys(fn (SellerInvoice $invoice): array => [
                $invoice->id => sprintf(
                    '#%s · %s · ৳%s · due %s',
                    $invoice->invoice_number,
                    str_replace('_', ' ', (string) $invoice->invoice_type),
                    number_format((float) $invoice->total_amount, 2),
                    $invoice->due_date?->format('d M Y') ?? '—',
                ),
            ])
            ->all();
    }
}
