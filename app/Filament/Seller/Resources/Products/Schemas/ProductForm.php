<?php

namespace App\Filament\Seller\Resources\Products\Schemas;

use App\Models\Brand;
use App\Models\Category;
use App\Models\SalesChannel;
use App\Models\Unit;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
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
                                // buy_price is $hidden on the model, so load it from the record explicitly
                                ->afterStateHydrated(fn (TextInput $component, ?Model $record) => $record ? $component->state($record->buy_price ?? 0) : null)
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

                Section::make('Pickaboo Specifications & Warranty')
                    ->description('Manufacturer model, master SKU, strikethrough MSRP, and brand warranty coverage.')
                    ->schema([
                        Grid::make(3)->schema([
                            TextInput::make('sku')
                                ->label('Master SKU')
                                ->placeholder('e.g. HAIER-H55M80GUX-TV'),

                            TextInput::make('model_number')
                                ->label('Model Number')
                                ->placeholder('e.g. H55M80GUX'),

                            TextInput::make('regular_price')
                                ->label('Regular MSRP (Crossed-Out Price Tk)')
                                ->prefix('৳')
                                ->numeric()
                                ->helperText('Displayed with strikethrough styling to show buyer savings.'),
                        ]),

                        Grid::make(2)->schema([
                            TextInput::make('warranty_period')
                                ->label('Warranty Period')
                                ->placeholder('e.g. 2 Years Official Brand Warranty'),

                            TextInput::make('delivery_info')
                                ->label('Delivery Highlight')
                                ->placeholder('e.g. Express 24-48 Hours Delivery Across Bangladesh'),
                        ]),

                        Textarea::make('warranty_policy')
                            ->label('Detailed Warranty Policy')
                            ->rows(2)
                            ->placeholder('e.g. 2 Years Panel Warranty, 2 Years Spare Parts, 4 Years Free Service.'),

                        Grid::make(3)->schema([
                            Toggle::make('is_featured')
                                ->label('Feature on Homepage')
                                ->default(false),

                            Toggle::make('is_flash_sale')
                                ->label('Include in Flash Sale')
                                ->default(false),

                            TextInput::make('flash_sale_price')
                                ->label('Flash Sale Special Price (Tk)')
                                ->prefix('৳')
                                ->numeric()
                                ->helperText('Overrides standard retail price during active flash sale.'),
                        ]),
                    ]),

                Section::make('Channel Tier Pricing (Tk)')
                    ->description('Set distinct retail rates and wholesale bulk minimum order thresholds.')
                    ->schema([
                        Repeater::make('prices')
                            ->relationship('prices')
                            ->rule(fn (Get $get): Closure => self::pricesMatchChannelRule($get('sales_channel_id')))
                            ->schema([
                                Select::make('sales_channel_id')
                                    ->label('Channel')
                                    // A price is either a shop (retail) price or a wholesale tier; "both" only describes the product
                                    ->options(SalesChannel::where('is_active', true)->whereIn('slug', [SalesChannel::RETAIL, SalesChannel::WHOLESALE])->pluck('name', 'id'))
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

                Section::make('Product Photos')
                    ->description('JPG, PNG or WebP up to 2 MB each. Mark one photo as the primary image.')
                    ->schema([
                        Repeater::make('images')
                            ->relationship('images')
                            ->schema([
                                FileUpload::make('image_url')
                                    ->label('Photo')
                                    ->image()
                                    ->disk('public')
                                    ->directory('products')
                                    ->visibility('public')
                                    ->maxSize(2048)
                                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                                    ->required(),
                                Toggle::make('is_primary')
                                    ->label('Primary photo')
                                    ->default(false),
                            ])
                            ->orderColumn('sort_order')
                            ->columns(2)
                            ->defaultItems(0),
                    ]),

                Section::make('Product Variations & Stock Inventory')
                    ->description('Manage variations (SKUs, stock quantities, and inventory states).')
                    ->schema([
                        Toggle::make('has_variations')
                            ->label('Has Multiple Variations (Color, Size, etc.)')
                            ->default(true)
                            ->live(),

                        // Simple products keep stock on their default variation (PRA-011)
                        TextInput::make('simple_stock_quantity')
                            ->label('Stock Quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ! $get('has_variations'))
                            ->helperText('Units available to sell. Orders are refused once this reaches 0.'),

                        Repeater::make('variations')
                            ->relationship('variations')
                            ->visible(fn (Get $get): bool => (bool) $get('has_variations'))
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
                                    ->default(0)
                                    ->afterStateHydrated(fn (TextInput $component, ?Model $record) => $record ? $component->state($record->buy_price ?? 0) : null),

                                Toggle::make('is_active')
                                    ->label('In Stock & Active')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(1),
                    ]),
            ]);
    }

    /**
     * A shop product needs a retail price and a wholesale product needs at least one wholesale tier, otherwise the
     * storefront would show ৳0 or the product could never be ordered.
     */
    public static function pricesMatchChannelRule(mixed $productChannelId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($productChannelId): void {
            $slugs = SalesChannel::pluck('slug', 'id');
            $productChannel = $slugs[(int) $productChannelId] ?? null;
            $priceChannels = collect(is_array($value) ? $value : [])
                ->map(fn (mixed $row): ?string => is_array($row) ? ($slugs[(int) ($row['sales_channel_id'] ?? 0)] ?? null) : null)
                ->filter();

            if (in_array($productChannel, [SalesChannel::RETAIL, SalesChannel::BOTH], true) && ! $priceChannels->contains(SalesChannel::RETAIL)) {
                $fail('Add a Retail price: shoppers need a price to buy this product.');
            }

            if (in_array($productChannel, [SalesChannel::WHOLESALE, SalesChannel::BOTH], true) && ! $priceChannels->contains(SalesChannel::WHOLESALE)) {
                $fail('Add at least one Wholesale price tier for business buyers.');
            }
        };
    }
}
