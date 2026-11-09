<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SellerPlans;

use App\Filament\Admin\Resources\SellerPlans\Pages\CreateSellerPlan;
use App\Filament\Admin\Resources\SellerPlans\Pages\EditSellerPlan;
use App\Filament\Admin\Resources\SellerPlans\Pages\ListSellerPlans;
use App\Models\SellerPlan;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class SellerPlanResource extends Resource
{
    protected static ?string $model = SellerPlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static UnitEnum|string|null $navigationGroup = 'Monetization & Plans';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Seller Plans';

    protected static ?string $modelLabel = 'plan';

    protected static ?string $pluralModelLabel = 'seller plans';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && in_array($user->role?->slug, ['super_admin', 'admin'], true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Plan Information')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->label('Plan Name')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (string $operation, ?string $state, callable $set): void {
                                if ($operation === 'create' && filled($state)) {
                                    $set('slug', Str::slug($state));
                                }
                            }),

                        TextInput::make('slug')
                            ->label('Plan Slug')
                            ->required()
                            ->maxLength(50)
                            ->unique(ignoreRecord: true),

                        TextInput::make('badge_label')
                            ->label('Badge Label')
                            ->placeholder('e.g. Verified Factory, Top Merchant')
                            ->maxLength(50),

                        TextInput::make('sort_order')
                            ->label('Display Order')
                            ->numeric()
                            ->default(0),

                        Textarea::make('description')
                            ->label('Description')
                            ->columnSpanFull()
                            ->rows(2),
                    ]),

                Section::make('Subscription Pricing')
                    ->description('Changes to price, billing cycle, listing limit or commission apply to sellers already on this plan from their next renewal. They are told by SMS.')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        Toggle::make('is_subscription_fee_enabled')
                            ->label('Charge Subscription Fee')
                            ->default(true)
                            ->live(),

                        TextInput::make('subscription_price')
                            ->label('Subscription Price (৳)')
                            ->numeric()
                            ->prefix('৳')
                            ->default(0.00)
                            ->required(),

                        TextInput::make('billing_cycle_days')
                            ->label('Billing Cycle (Days)')
                            ->numeric()
                            ->default(30)
                            ->required(),
                    ]),

                Section::make('Product Listing Capacity')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        Toggle::make('is_listing_limit_enabled')
                            ->label('Limit Active Products')
                            ->default(true)
                            ->live(),

                        TextInput::make('max_active_products')
                            ->label('Max Active Products')
                            ->numeric()
                            ->placeholder('Leave blank for Unlimited')
                            ->nullable(),

                        Toggle::make('allow_listing_addons')
                            ->label('Allow Listing Addon Packs')
                            ->default(true),
                    ]),

                Section::make('Sales Commission Take Rates')
                    ->columns(['default' => 1, 'sm' => 3])
                    ->schema([
                        Toggle::make('is_commission_enabled')
                            ->label('Charge Sales Commission')
                            ->default(true)
                            ->live(),

                        TextInput::make('retail_commission_rate')
                            ->label('Retail Commission (%)')
                            ->numeric()
                            ->suffix('%')
                            ->default(10.00)
                            ->required(),

                        TextInput::make('wholesale_commission_rate')
                            ->label('Wholesale Commission (%)')
                            ->numeric()
                            ->suffix('%')
                            ->default(4.00)
                            ->required(),
                    ]),

                Section::make('Activation & Defaults')
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        Toggle::make('is_default_free_plan')
                            ->label('Default Free Plan for New Sellers')
                            ->default(false),

                        Toggle::make('is_active')
                            ->label('Active / Visible to Sellers')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order', 'asc')
            ->columns([
                TextColumn::make('name')
                    ->label('Plan')
                    ->weight('medium')
                    ->searchable(),

                TextColumn::make('subscription_price')
                    ->label('Price')
                    ->formatStateUsing(fn ($state, SellerPlan $record): string => $record->is_subscription_fee_enabled
                        ? '৳'.number_format((float) $state, 2).'/'.$record->billing_cycle_days.'d'
                        : 'Free')
                    ->sortable(),

                TextColumn::make('max_active_products')
                    ->label('Product Limit')
                    ->formatStateUsing(fn ($state, SellerPlan $record): string => $record->is_listing_limit_enabled
                        ? ($state ? "{$state} items" : 'Unlimited')
                        : 'Unlimited'),

                TextColumn::make('retail_commission_rate')
                    ->label('Retail %')
                    ->formatStateUsing(fn ($state, SellerPlan $record): string => $record->is_commission_enabled ? "{$state}%" : '0%'),

                TextColumn::make('wholesale_commission_rate')
                    ->label('Wholesale %')
                    ->formatStateUsing(fn ($state, SellerPlan $record): string => $record->is_commission_enabled ? "{$state}%" : '0%'),

                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),

                TextColumn::make('subscriptions_count')
                    ->counts('subscriptions')
                    ->label('Subscribers'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSellerPlans::route('/'),
            'create' => CreateSellerPlan::route('/create'),
            'edit' => EditSellerPlan::route('/{record}/edit'),
        ];
    }
}
