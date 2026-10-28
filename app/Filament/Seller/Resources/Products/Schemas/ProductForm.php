<?php

namespace App\Filament\Seller\Resources\Products\Schemas;

use App\Models\Brand;
use App\Models\Category;
use App\Models\SalesChannel;
use App\Models\Unit;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('seller_id')
                    ->default(fn () => auth()->id()),

                Section::make('General Information')
                    ->description('Basic catalog details and sales channel assignment.')
                    ->schema([
                        Grid::make(2)->schema([
                            TextInput::make('title')
                                ->label('Product Title')
                                ->placeholder('e.g. Premium Cotton Polo Shirt')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (string $operation, $state, callable $set) {
                                    if ($operation === 'create' && filled($state)) {
                                        $set('slug', Str::slug($state));
                                    }
                                }),

                            TextInput::make('slug')
                                ->label('URL Slug')
                                ->required()
                                ->unique('products', 'slug', ignoreRecord: true),
                        ]),

                        Grid::make(3)->schema([
                            Select::make('category_id')
                                ->label('Category')
                                ->options(Category::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),

                            Select::make('brand_id')
                                ->label('Brand')
                                ->options(Brand::where('is_active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->placeholder('No Brand / Factory Direct'),

                            Select::make('unit_id')
                                ->label('Measurement Unit')
                                ->options(Unit::all()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->symbol})"]))
                                ->required(),
                        ]),

                        Grid::make(2)->schema([
                            Select::make('sales_channel_id')
                                ->label('Sales Channel')
                                ->options(SalesChannel::where('is_active', true)->pluck('name', 'id'))
                                ->default(fn () => SalesChannel::where('slug', SalesChannel::BOTH)->value('id'))
                                ->helperText('Choose whether to sell Retail, Wholesale, or Both.')
                                ->required(),

                            TextInput::make('buy_price')
                                ->label('Internal Cost / Buy Price (Tk)')
                                ->prefix('৳')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->helperText('Private to your vendor analytics. Never disclosed to buyers.'),
                        ]),

                        Textarea::make('short_description')
                            ->label('Summary')
                            ->rows(2)
                            ->columnSpanFull(),

                        Textarea::make('full_description')
                            ->label('Detailed Specifications & Description')
                            ->rows(4)
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Active on Marketplace')
                            ->default(true)
                            ->helperText('Deactivating immediately hides this item from all storefront queries.'),
                    ]),

                Section::make('Channel Tier Pricing (Tk)')
                    ->description('Set distinct retail rates and wholesale bulk minimum order thresholds.')
                    ->schema([
                        Repeater::make('prices')
                            ->relationship('prices')
                            ->schema([
                                Select::make('sales_channel_id')
                                    ->label('Channel')
                                    ->options(SalesChannel::where('is_active', true)->pluck('name', 'id'))
                                    ->required(),

                                TextInput::make('unit_price')
                                    ->label('Price per Unit (Tk)')
                                    ->prefix('৳')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->required(),

                                TextInput::make('min_quantity')
                                    ->label('Minimum Order Quantity (MOQ)')
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->required()
                                    ->helperText('Retail is typically 1. Wholesale typically 20, 50, 100+.'),

                                TextInput::make('max_quantity')
                                    ->label('Max Bracket Limit')
                                    ->numeric()
                                    ->minValue(1)
                                    ->placeholder('Leave empty for unlimited bulk'),
                            ])
                            ->columns(4)
                            ->defaultItems(2),
                    ]),

                Section::make('Product Variations & Stock Inventory')
                    ->description('Manage variations (SKUs, stock quantities, and inventory states).')
                    ->schema([
                        Toggle::make('has_variations')
                            ->label('Has Multiple Variations (Color, Size, etc.)')
                            ->default(true),

                        Repeater::make('variations')
                            ->relationship('variations')
                            ->schema([
                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->required()
                                    ->placeholder('e.g. POLO-BLK-M'),

                                TextInput::make('stock_quantity')
                                    ->label('Stock Quantity')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(100)
                                    ->required(),

                                TextInput::make('buy_price')
                                    ->label('Variation Cost (Tk)')
                                    ->prefix('৳')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0),

                                Toggle::make('is_active')
                                    ->label('In Stock & Active')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(1),
                    ]),
            ]);
    }
}
