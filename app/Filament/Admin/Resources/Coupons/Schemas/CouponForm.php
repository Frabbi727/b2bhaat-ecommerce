<?php

namespace App\Filament\Admin\Resources\Coupons\Schemas;

use App\Models\Coupon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Coupon editor. Checkout upper-cases what the shopper types (CouponService), so codes are saved in capitals too;
 * otherwise a code typed in lower case here could never be redeemed.
 */
class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::codeSection(),
                    self::discountSection(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::summarySection(),
                    self::limitsSection(),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    private static function codeSection(): Section
    {
        return Section::make('Code')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('code')
                        ->label('Code shoppers type')
                        ->placeholder('e.g. EID20')
                        ->helperText('Letters, numbers and dashes. Saved in capitals.')
                        ->maxLength(50)
                        ->regex('/^[A-Za-z0-9-]+$/')
                        ->validationMessages(['regex' => 'Use only letters, numbers and dashes, with no spaces.'])
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, Set $set) => $set('code', Str::upper(trim((string) $state))))
                        ->dehydrateStateUsing(fn (?string $state): string => Str::upper(trim((string) $state)))
                        ->unique('coupons', 'code', ignoreRecord: true)
                        ->extraInputAttributes(['style' => 'text-transform: uppercase; font-family: ui-monospace, monospace; letter-spacing: 0.04em'])
                        ->suffixAction(
                            Action::make('generateCode')
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->tooltip('Make a random code')
                                ->action(fn (Set $set) => $set('code', 'B2B-'.Str::upper(Str::random(6)))),
                        )
                        ->required(),

                    TextInput::make('name')
                        ->label('Name for your records')
                        ->placeholder('e.g. Eid 2026, new buyers')
                        ->helperText('Only admins see this.')
                        ->maxLength(255)
                        ->required(),
                ]),
            ]);
    }

    private static function discountSection(): Section
    {
        return Section::make('Discount')
            ->schema([
                ToggleButtons::make('discount_type')
                    ->label('Type of discount')
                    ->options([
                        Coupon::TYPE_PERCENTAGE => 'Percent off',
                        Coupon::TYPE_FIXED => 'Taka off',
                    ])
                    ->icons([
                        Coupon::TYPE_PERCENTAGE => Heroicon::OutlinedReceiptPercent,
                        Coupon::TYPE_FIXED => Heroicon::OutlinedBanknotes,
                    ])
                    ->inline()
                    ->default(Coupon::TYPE_PERCENTAGE)
                    ->live()
                    ->required(),

                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    TextInput::make('discount_value')
                        ->label(fn (Get $get): string => $get('discount_type') === Coupon::TYPE_FIXED ? 'Amount off' : 'Percent off')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (Get $get): ?int => $get('discount_type') === Coupon::TYPE_FIXED ? null : 100)
                        ->prefix(fn (Get $get): ?string => $get('discount_type') === Coupon::TYPE_FIXED ? '৳' : null)
                        ->suffix(fn (Get $get): ?string => $get('discount_type') === Coupon::TYPE_FIXED ? null : '%')
                        ->live(onBlur: true)
                        ->required(),

                    TextInput::make('min_order_amount')
                        ->label('Minimum basket')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('৳')
                        ->helperText('0 means any order.')
                        ->live(onBlur: true)
                        ->required(),

                    TextInput::make('max_discount_amount')
                        ->label('Most it can take off')
                        ->numeric()
                        ->minValue(1)
                        ->prefix('৳')
                        ->placeholder('No cap')
                        ->helperText('Stops big baskets getting a huge discount.')
                        ->live(onBlur: true)
                        ->visible(fn (Get $get): bool => $get('discount_type') !== Coupon::TYPE_FIXED),
                ]),
            ]);
    }

    private static function summarySection(): Section
    {
        return Section::make('What shoppers get')
            ->schema([
                TextEntry::make('offer_preview')
                    ->hiddenLabel()
                    ->state(fn (Get $get): string => Coupon::describeOffer(
                        $get('discount_type'),
                        $get('discount_value'),
                        $get('min_order_amount'),
                        $get('max_discount_amount'),
                    ) ?? 'Enter the discount to see it here.')
                    ->weight('medium')
                    ->size('lg'),

                TextEntry::make('status')
                    ->label('Right now')
                    ->badge()
                    ->state(fn (?Coupon $record): ?string => $record?->statusLabel())
                    ->color(fn (?Coupon $record): string => $record?->statusColor() ?? 'gray')
                    ->hiddenOn('create'),

                TextEntry::make('used')
                    ->label('Used')
                    ->state(fn (?Coupon $record): ?string => $record
                        ? number_format($record->used_count).($record->usage_limit_total ? ' of '.number_format($record->usage_limit_total) : '').' times'
                        : null)
                    ->hiddenOn('create'),
            ]);
    }

    private static function limitsSection(): Section
    {
        return Section::make('When and how often')
            ->schema([
                Toggle::make('is_active')
                    ->label('Can be used')
                    ->helperText('Turn off to stop it straight away.')
                    ->default(true),

                DateTimePicker::make('start_at')
                    ->label('Works from')
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d M Y, g:i A')
                    ->placeholder('Right away')
                    ->live(onBlur: true),

                DateTimePicker::make('expires_at')
                    ->label('Works until')
                    ->native(false)
                    ->seconds(false)
                    ->displayFormat('d M Y, g:i A')
                    ->placeholder('No end date')
                    ->minDate(fn (Get $get): ?string => $get('start_at'))
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if (filled($value) && filled($get('start_at')) && Carbon::parse($value)->lte(Carbon::parse($get('start_at')))) {
                            $fail('Pick an end time after the start time.');
                        }
                    }),

                TextInput::make('usage_limit_total')
                    ->label('Total uses')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->placeholder('No limit')
                    ->helperText('Across all shoppers. Stops working once reached.'),

                TextInput::make('usage_limit_per_user')
                    ->label('Uses per shopper')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
            ]);
    }
}
