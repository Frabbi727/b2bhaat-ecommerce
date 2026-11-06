<?php

namespace App\Filament\Seller\Resources\Products\Schemas;

use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Unit;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The seller's product editor. Create and Edit share this one layout: the work (details, photos, pricing, stock,
 * specifications) runs down the main column; the settings a seller glances at (visibility, organisation,
 * promotions, URL) sit in a side column.
 */
class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('seller_id')
                    ->default(fn () => auth()->id()),

                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            self::detailsSection(),
                            self::photosSection(),
                            self::pricingSection(),
                            self::inventorySection(),
                            self::specificationsSection(),
                        ])->columnSpan(['lg' => 2]),

                        Group::make([
                            self::moderationNotice(),
                            self::visibilitySection(),
                            self::organisationSection(),
                            self::promotionsSection(),
                            self::urlSection(),
                        ])->columnSpan(['lg' => 1]),
                    ]),
            ]);
    }

    protected static function detailsSection(): Section
    {
        return Section::make('Product details')
            ->description('What buyers see first on the product page.')
            ->schema([
                TextInput::make('title')
                    ->label('Product name')
                    ->placeholder('e.g. Premium Cotton Polo Shirt')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (string $operation, $state, callable $set) {
                        if ($operation === 'create' && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                Grid::make(['default' => 1, 'sm' => 3])->schema([
                    TextInput::make('sku')
                        ->label('SKU')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Generated when you save')
                        ->helperText('Created automatically and unique.'),

                    TextInput::make('barcode')
                        ->label('Barcode (EAN-13)')
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder('Generated when you save')
                        ->helperText('Print it on labels or scan it at the warehouse.'),

                    TextInput::make('model_number')
                        ->label('Model number')
                        ->placeholder('e.g. H55M80GUX')
                        ->maxLength(255),
                ]),

                Textarea::make('short_description')
                    ->label('Summary')
                    ->rows(2)
                    ->helperText('One or two sentences shown near the price.'),

                Textarea::make('full_description')
                    ->label('Full description')
                    ->rows(6)
                    ->helperText('Materials, sizing, what is in the box, care instructions.'),
            ]);
    }

    protected static function photosSection(): Section
    {
        return Section::make('Photos')
            ->description('JPG, PNG or WebP, up to 2 MB each. Square photos of at least 800 × 800 px look best. The first photo is the cover image; drag photos to reorder them.')
            ->schema([
                Repeater::make('images')
                    ->relationship('images')
                    ->hiddenLabel()
                    ->schema([
                        FileUpload::make('image_url')
                            ->hiddenLabel()
                            ->image()
                            ->imagePreviewHeight('160')
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            // Some catalogue photos are remote URLs, not files on the disk; keep and preview them
                            // instead of silently dropping them (which made the product impossible to save)
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(fn (FileUpload $component, string $file, string|array|null $storedFileNames): ?array => filter_var($file, FILTER_VALIDATE_URL)
                                ? ['name' => basename((string) parse_url($file, PHP_URL_PATH)) ?: 'photo', 'size' => 0, 'type' => null, 'url' => $file]
                                : $component->getUploadedFile($file, $storedFileNames))
                            ->required(),
                    ])
                    ->orderColumn('sort_order')
                    ->grid(['default' => 2, 'md' => 3])
                    ->addActionLabel('Add photo')
                    ->defaultItems(0),
            ]);
    }

    protected static function pricingSection(): Section
    {
        return Section::make('Pricing')
            ->description(fn (Get $get): string => self::pricingGuidance($get('sales_channel_id')))
            ->schema([
                Repeater::make('prices')
                    ->relationship('prices')
                    ->hiddenLabel()
                    ->rule(fn (Get $get): Closure => self::pricesMatchChannelRule($get('sales_channel_id')))
                    ->table([
                        TableColumn::make('Channel')->markAsRequired()->width('230px'),
                        TableColumn::make('Price per unit')->markAsRequired(),
                        TableColumn::make('Min. qty')->markAsRequired()->width('110px'),
                        TableColumn::make('Max. qty')->width('110px'),
                    ])
                    ->schema([
                        Select::make('sales_channel_id')
                            ->label('Channel')
                            // A price is either a shop (retail) price or a wholesale tier; "both" only describes the product
                            ->options(SalesChannel::where('is_active', true)->whereIn('slug', [SalesChannel::RETAIL, SalesChannel::WHOLESALE])->pluck('name', 'id'))
                            ->required(),

                        TextInput::make('unit_price')
                            ->label('Price per unit')
                            ->prefix('৳')
                            ->numeric()
                            ->minValue(0.01)
                            ->required(),

                        TextInput::make('min_quantity')
                            ->label('Minimum order quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->default(1)
                            ->required(),

                        TextInput::make('max_quantity')
                            ->label('Maximum quantity')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('No limit'),
                    ])
                    ->addActionLabel('Add price tier')
                    ->defaultItems(1),

                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('regular_price')
                        ->label('Compare-at price')
                        ->prefix('৳')
                        ->numeric()
                        ->minValue(0)
                        ->helperText('Optional. Shown crossed out next to your price.'),

                    TextInput::make('buy_price')
                        ->label('Your cost per unit')
                        ->prefix('৳')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        // buy_price is $hidden on the model, so load it from the record explicitly
                        ->afterStateHydrated(fn (TextInput $component, ?Model $record) => $record ? $component->state($record->buy_price ?? 0) : null)
                        ->helperText('Private. Used only for your profit reports; buyers never see it.'),
                ]),
            ]);
    }

    protected static function inventorySection(): Section
    {
        return Section::make('Inventory & variations')
            ->description('Track stock for one item, or for each size, colour or model you sell.')
            ->schema([
                Toggle::make('has_variations')
                    ->label('This product comes in several options (size, colour, model…)')
                    ->default(false)
                    ->live(),

                // Simple products keep stock on their default variation (PRA-011)
                TextInput::make('simple_stock_quantity')
                    ->label('Units in stock')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->dehydrated(false)
                    ->visible(fn (Get $get): bool => ! $get('has_variations'))
                    ->helperText('Orders are refused once this reaches 0.')
                    ->maxWidth('xs'),

                Repeater::make('variations')
                    ->relationship('variations')
                    ->hiddenLabel()
                    ->visible(fn (Get $get): bool => (bool) $get('has_variations'))
                    ->table([
                        TableColumn::make('SKU')->width('150px'),
                        TableColumn::make('Options'),
                        TableColumn::make('Stock')->markAsRequired()->width('100px'),
                        TableColumn::make('Cost')->width('120px'),
                        TableColumn::make('Sellable')->width('80px'),
                    ])
                    ->schema([
                        TextInput::make('sku')
                            ->label('SKU')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Auto'),

                        Select::make('attributeValues')
                            ->label('Options')
                            ->relationship('attributeValues', 'value', fn (Builder $query) => $query->with('attribute'))
                            ->getOptionLabelFromRecordUsing(fn (AttributeValue $record): string => "{$record->attribute?->name}: {$record->value}")
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->placeholder('e.g. Colour: Black'),

                        TextInput::make('stock_quantity')
                            ->label('Stock')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->default(0)
                            ->required(),

                        TextInput::make('buy_price')
                            ->label('Cost')
                            ->prefix('৳')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->afterStateHydrated(fn (TextInput $component, ?Model $record) => $record ? $component->state($record->buy_price ?? 0) : null),

                        Toggle::make('is_active')
                            ->label('Sellable')
                            ->default(true),
                    ])
                    ->addActionLabel('Add variation')
                    ->minItems(fn (Get $get): int => $get('has_variations') ? 1 : 0)
                    ->defaultItems(1),
            ]);
    }

    protected static function specificationsSection(): Section
    {
        return Section::make('Specifications & warranty')
            ->description('Shown in the Specifications and Key features tabs on the product page.')
            ->collapsible()
            ->collapsed(fn (string $operation): bool => $operation === 'create')
            ->schema([
                Repeater::make('features')
                    ->label('Key features')
                    ->simple(
                        TextInput::make('feature')
                            ->placeholder('e.g. 4K UHD display with HDR10')
                            ->maxLength(255)
                            ->required(),
                    )
                    ->addActionLabel('Add feature')
                    ->defaultItems(0)
                    ->reorderable(),

                Repeater::make('specification_groups')
                    ->label('Technical specifications')
                    ->schema([
                        TextInput::make('group')
                            ->label('Group name')
                            ->placeholder('e.g. Display')
                            ->required(),
                        KeyValue::make('items')
                            ->label('Details')
                            ->keyLabel('Property')
                            ->valueLabel('Value')
                            ->keyPlaceholder('e.g. Resolution')
                            ->valuePlaceholder('e.g. 3840 × 2160'),
                        Hidden::make('text'),
                    ])
                    ->itemLabel(fn (array $state): ?string => $state['group'] ?? null)
                    ->collapsible()
                    ->addActionLabel('Add specification group')
                    ->defaultItems(0),

                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    TextInput::make('warranty_period')
                        ->label('Warranty')
                        ->placeholder('e.g. 2 years official brand warranty')
                        ->maxLength(255),

                    TextInput::make('delivery_info')
                        ->label('Delivery note')
                        ->placeholder('e.g. Delivered in 24–48 hours inside Dhaka')
                        ->maxLength(255),
                ]),

                Textarea::make('warranty_policy')
                    ->label('Warranty terms')
                    ->rows(2)
                    ->placeholder('e.g. 2 years panel, 2 years spare parts, 4 years free service.'),
            ]);
    }

    protected static function moderationNotice(): Section
    {
        return Section::make('Hidden by the marketplace')
            ->description(fn (?Product $record): string => 'An admin has hidden this product from the storefront'.($record?->blocked_reason ? ': '.$record->blocked_reason : '.').' Contact support once it is fixed.')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->iconColor('danger')
            ->visible(fn (?Product $record): bool => (bool) $record?->blocked_at)
            ->schema([]);
    }

    protected static function visibilitySection(): Section
    {
        return Section::make('Visibility')
            ->schema([
                Toggle::make('is_active')
                    ->label('Visible on storefront')
                    ->default(true)
                    ->helperText('Turn off to hide this product from every listing and search without deleting it.'),
            ]);
    }

    protected static function organisationSection(): Section
    {
        return Section::make('Organisation')
            ->schema([
                Select::make('sales_channel_id')
                    ->label('Sell to')
                    ->options(SalesChannel::where('is_active', true)->pluck('name', 'id'))
                    ->default(fn () => SalesChannel::where('slug', SalesChannel::BOTH)->value('id'))
                    ->helperText('Retail shoppers, wholesale business buyers, or both.')
                    ->live()
                    ->required(),

                Select::make('category_id')
                    ->label('Category')
                    ->options(Category::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(),

                Select::make('brand_id')
                    ->label('Brand')
                    ->options(Brand::where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('No brand (factory direct)'),

                Select::make('unit_id')
                    ->label('Sold by')
                    ->options(Unit::all()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->symbol})"]))
                    ->helperText('The unit quantities and stock are counted in.')
                    ->required(),
            ]);
    }

    protected static function promotionsSection(): Section
    {
        return Section::make('Promotions')
            ->collapsible()
            ->schema([
                Toggle::make('is_featured')
                    ->label('Feature on homepage')
                    ->default(false),

                Placeholder::make('flash_sale_status')
                    ->label('Flash Sale')
                    ->content(function (?Product $record): string {
                        $offer = $record?->liveFlashOffer();
                        if (! $offer || ! $offer->flashSale) {
                            return '';
                        }

                        $dhakaEnd = $offer->flashSale->ends_at?->timezone('Asia/Dhaka')->format('d M Y, h:i A');

                        return "Flash sale: {$offer->flashSale->name} until {$dhakaEnd}";
                    })
                    ->visible(fn (?Product $record): bool => (bool) $record?->liveFlashOffer()),
            ]);
    }

    protected static function urlSection(): Section
    {
        return Section::make('Product URL')
            ->collapsible()
            ->schema([
                TextInput::make('slug')
                    ->label('URL handle')
                    ->prefix('/product/')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique('products', 'slug', ignoreRecord: true)
                    ->helperText('Filled in from the product name. Changing it later breaks links people have shared.'),
            ]);
    }

    protected static function pricingGuidance(mixed $productChannelId): string
    {
        $slug = SalesChannel::whereKey($productChannelId)->value('slug');

        return match ($slug) {
            SalesChannel::RETAIL => 'Add one Retail price. Retail shoppers buy from 1 unit.',
            SalesChannel::WHOLESALE => 'Add at least one Wholesale tier. Use several tiers to give bigger orders a lower unit price (e.g. 50–99 @ ৳110, 100+ @ ৳95).',
            default => 'Add a Retail price for shoppers and at least one Wholesale tier for business buyers. Extra wholesale tiers reward bigger orders.',
        };
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

    /**
     * Stored specifications (`{"Display": {"Size": "55 in"}}`) as editable repeater rows.
     *
     * @param  array<string, mixed>|null  $specifications
     * @return array<int, array{group: string, items: array<string, string>, text: ?string}>
     */
    public static function specificationsToGroups(?array $specifications): array
    {
        return collect($specifications ?? [])
            ->map(fn (mixed $items, string|int $group): array => [
                'group' => (string) $group,
                'items' => is_array($items)
                    ? collect($items)->map(fn (mixed $value): string => is_array($value) ? implode(', ', $value) : (string) $value)->all()
                    : [],
                'text' => is_array($items) ? null : (string) $items,
            ])
            ->values()
            ->all();
    }

    /**
     * Repeater rows back into the stored specifications shape; a group with no rows keeps its original free text.
     *
     * @param  array<int|string, array{group?: ?string, items?: ?array<string, string>, text?: ?string}>|null  $groups
     * @return array<string, mixed>|null
     */
    public static function groupsToSpecifications(?array $groups): ?array
    {
        $specifications = collect($groups ?? [])
            ->filter(fn (array $row): bool => filled($row['group'] ?? null))
            ->mapWithKeys(function (array $row): array {
                $items = collect($row['items'] ?? [])->filter(fn (mixed $value, mixed $key): bool => filled($key))->all();

                return [trim((string) $row['group']) => $items !== [] ? $items : ($row['text'] ?? [])];
            })
            ->all();

        return $specifications === [] ? null : $specifications;
    }
}
