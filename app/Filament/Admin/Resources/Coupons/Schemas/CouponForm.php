<?php

namespace App\Filament\Admin\Resources\Coupons\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CouponForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Coupon Core Configuration')
                    ->description('Voucher code, title, and discount deduction formula.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('code')
                                ->label('Coupon Code')
                                ->placeholder('e.g. MEGA20')
                                ->required()
                                ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                ->unique('coupons', 'code', ignoreRecord: true),

                            TextInput::make('name')
                                ->label('Campaign Name')
                                ->placeholder('e.g. Eid Mega Discount')
                                ->required(),

                            Select::make('discount_type')
                                ->label('Discount Type')
                                ->options([
                                    'percentage' => 'Percentage Discount (%)',
                                    'fixed' => 'Fixed Amount Discount (Tk)',
                                ])
                                ->default('percentage')
                                ->required(),
                        ]),

                        Grid::make(3)->schema([
                            TextInput::make('discount_value')
                                ->label('Discount Value')
                                ->numeric()
                                ->minValue(0.01)
                                ->required()
                                ->helperText('Enter percentage rate (e.g. 10 for 10%) or fixed Taka amount (e.g. 500).'),

                            TextInput::make('min_order_amount')
                                ->label('Minimum Order Subtotal (Tk)')
                                ->numeric()
                                ->default(0.00)
                                ->prefix('৳')
                                ->helperText('Required basket subtotal before coupon becomes valid.'),

                            TextInput::make('max_discount_amount')
                                ->label('Maximum Cap (Tk)')
                                ->numeric()
                                ->nullable()
                                ->prefix('৳')
                                ->helperText('Max ceiling for percentage discounts (leave empty for unlimited).'),
                        ]),
                    ]),

                Section::make('Usage Limits & Scheduling')
                    ->description('Set redemption caps and active promotional campaign dates.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('usage_limit_total')
                                ->label('Total Usage Limit')
                                ->numeric()
                                ->nullable()
                                ->placeholder('Unlimited')
                                ->helperText('Leave empty for unlimited claims across all buyers.'),

                            TextInput::make('usage_limit_per_user')
                                ->label('Usage Limit Per Customer')
                                ->numeric()
                                ->default(1)
                                ->required(),

                            Toggle::make('is_active')
                                ->label('Active / Redeemable')
                                ->default(true),
                        ]),

                        Grid::make(2)->schema([
                            DateTimePicker::make('start_at')
                                ->label('Valid From')
                                ->nullable(),

                            DateTimePicker::make('expires_at')
                                ->label('Valid Until / Expiration')
                                ->nullable(),
                        ]),
                    ]),
            ]);
    }
}
