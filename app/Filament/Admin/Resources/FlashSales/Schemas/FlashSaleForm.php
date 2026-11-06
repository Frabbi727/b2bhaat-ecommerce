<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FlashSales\Schemas;

use App\Enums\FlashSaleStatus;
use App\Enums\SellerEligibilityType;
use App\Models\FlashSale;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class FlashSaleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    self::generalSection(),
                    self::participationSection(),
                    self::termsSection(),
                ])->columnSpan(['lg' => 2]),

                Group::make([
                    self::scheduleSection(),
                    self::prioritySection(),
                ])->columnSpan(['lg' => 1]),
            ]);
    }

    private static function generalSection(): Section
    {
        return Section::make('Campaign Details')
            ->schema([
                TextInput::make('name')
                    ->label('Campaign Name')
                    ->placeholder('e.g. 11.11 Mega Sale')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Set $set, ?FlashSale $record): void {
                        if (! $record) {
                            $set('slug', Str::slug((string) $state));
                        }
                    })
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('slug')
                    ->label('URL Slug')
                    ->required()
                    ->unique(FlashSale::class, 'slug', ignoreRecord: true)
                    ->maxLength(255)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                Textarea::make('description')
                    ->label('Description')
                    ->rows(3)
                    ->placeholder('Brief description shown to customers')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                FileUpload::make('banner_path')
                    ->label('Promotional Banner')
                    ->image()
                    ->disk('public')
                    ->directory('flash-sales')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function scheduleSection(): Section
    {
        return Section::make('Schedule (Asia/Dhaka)')
            ->schema([
                DateTimePicker::make('starts_at')
                    ->label('Starts at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->required()
                    ->disabled(function (?FlashSale $record): bool {
                        if (self::isTerminal($record)) {
                            return true;
                        }
                        if ($record && $record->status !== FlashSaleStatus::Draft && $record->starts_at->lte(now())) {
                            return true;
                        }

                        return false;
                    }),

                DateTimePicker::make('ends_at')
                    ->label('Ends at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->required()
                    ->after('starts_at')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function prioritySection(): Section
    {
        return Section::make('Placement')
            ->schema([
                Toggle::make('is_featured')
                    ->label('Featured Campaign')
                    ->helperText('Featured campaigns take precedence on the storefront homepage.')
                    ->default(false)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('display_priority')
                    ->label('Display Priority')
                    ->numeric()
                    ->default(0)
                    ->helperText('Higher numbers appear earlier in listings.')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function participationSection(): Section
    {
        return Section::make('Seller Participation & Eligibility Rules')
            ->columns(2)
            ->schema([
                DateTimePicker::make('registration_starts_at')
                    ->label('Registration Starts at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                DateTimePicker::make('registration_ends_at')
                    ->label('Registration Ends at')
                    ->timezone('Asia/Dhaka')
                    ->seconds(false)
                    ->native(false)
                    ->afterOrEqual('registration_starts_at')
                    ->beforeOrEqual('starts_at')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('min_discount_percentage')
                    ->label('Min Discount Percentage')
                    ->numeric()
                    ->default(5.00)
                    ->minValue(1)
                    ->maxValue(99)
                    ->lte('max_discount_percentage')
                    ->suffix('%')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('max_discount_percentage')
                    ->label('Max Discount Percentage')
                    ->numeric()
                    ->default(80.00)
                    ->minValue(1)
                    ->maxValue(99)
                    ->gte('min_discount_percentage')
                    ->suffix('%')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('max_products_per_seller')
                    ->label('Max Products per Seller')
                    ->numeric()
                    ->default(10)
                    ->minValue(1)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('min_stock_per_product')
                    ->label('Min Stock per Product')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('max_stock_per_product')
                    ->label('Max Stock per Product')
                    ->numeric()
                    ->nullable()
                    ->gte('min_stock_per_product')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                Select::make('seller_eligibility_type')
                    ->label('Seller Eligibility')
                    ->options(SellerEligibilityType::class)
                    ->default(SellerEligibilityType::All->value)
                    ->live()
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('minimum_seller_rating')
                    ->label('Minimum Seller Rating')
                    ->numeric()
                    ->nullable()
                    ->live()
                    ->step(0.1)
                    ->minValue(0)
                    ->maxValue(5)
                    ->visible(fn (Get $get): bool => $get('seller_eligibility_type') === SellerEligibilityType::MinimumRating->value || $get('seller_eligibility_type') === SellerEligibilityType::MinimumRating)
                    ->required(fn (Get $get): bool => $get('seller_eligibility_type') === SellerEligibilityType::MinimumRating->value || $get('seller_eligibility_type') === SellerEligibilityType::MinimumRating)
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                Toggle::make('requires_approval')
                    ->label('Requires Approval')
                    ->default(true)
                    ->helperText('Require manual admin review before seller products appear in flash sale')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                TextInput::make('min_approved_products')
                    ->label('Min Approved Products to Launch')
                    ->numeric()
                    ->default(1)
                    ->minValue(1)
                    ->helperText('Minimum approved products required before this campaign can be published to the storefront.')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),

                Select::make('categories')
                    ->label('Eligible Categories')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->preload()
                    ->helperText('Select eligible categories. Leave empty to allow all product categories.')
                    ->columnSpanFull()
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function termsSection(): Section
    {
        return Section::make('Terms & Conditions')
            ->collapsible()
            ->collapsed()
            ->schema([
                Textarea::make('terms')
                    ->label('Terms and Conditions')
                    ->rows(4)
                    ->placeholder('Campaign-specific terms, purchase rules, or return policies.')
                    ->disabled(fn (?FlashSale $record): bool => self::isTerminal($record)),
            ]);
    }

    private static function isTerminal(?FlashSale $record): bool
    {
        if (! $record) {
            return false;
        }

        return in_array($record->effectiveStatus(), [FlashSaleStatus::Ended, FlashSaleStatus::Cancelled], true);
    }
}
