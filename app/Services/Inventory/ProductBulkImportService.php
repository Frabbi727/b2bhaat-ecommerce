<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\ProductApprovalService;
use App\Services\Monetization\MonetizationEngineService;
use DomainException;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates new products from a spreadsheet, applying the same rules as the "Add product" form.
 *
 * One row is one product, or one option (size, colour…) of a product: rows sharing a product_title become a
 * single product with variations. SKU, barcode, URL handle and variation SKUs are generated, never read from the
 * sheet. Each product is all-or-nothing: a product with any bad row is reported and not created, the rest are.
 */
class ProductBulkImportService
{
    public const MAX_ROWS = 2000;

    /** Titles starting with this are the template's worked examples and are never imported. */
    public const EXAMPLE_PREFIX = 'EXAMPLE';

    /** Columns the system fills in itself; a sheet that includes them is accepted but the values are ignored. */
    public const GENERATED_COLUMNS = ['sku', 'barcode', 'slug', 'url_handle', 'variation_id', 'variation_sku', 'product_id'];

    /**
     * The sheet's columns, in template order. `need` is required | conditional | optional.
     *
     * @return array<string, array{need: string, when?: string, example: string, help: string}>
     */
    public static function columns(): array
    {
        return [
            'product_title' => ['need' => 'required', 'example' => 'Cotton Polo Shirt', 'help' => 'Product name. Repeat the same name on several rows to add options (sizes, colours…) to one product.'],
            'category' => ['need' => 'required', 'example' => 'False Nails & Accessories', 'help' => 'A category name from the list below.'],
            'sell_to' => ['need' => 'required', 'example' => 'both', 'help' => 'retail (shoppers), wholesale (business buyers) or both.'],
            'unit' => ['need' => 'required', 'example' => 'pc', 'help' => 'The unit stock and quantities are counted in, from the list below.'],
            'stock' => ['need' => 'required', 'example' => '120', 'help' => 'Units in stock for this row: a whole number, 0 or more.'],
            'retail_price' => ['need' => 'conditional', 'when' => 'retail or both', 'example' => '450', 'help' => 'Shop price per unit in ৳. Shoppers buy from 1 unit.'],
            'wholesale_tiers' => ['need' => 'conditional', 'when' => 'wholesale or both', 'example' => '50-99:110; 100-249:95; 250+:85', 'help' => 'Quantity range : price per unit, tiers separated by “;”. The first tier’s minimum is your minimum order quantity (MOQ). Use “250+” for no upper limit.'],
            'options' => ['need' => 'conditional', 'when' => 'the product has options', 'example' => 'Color: Pink, Length: Medium', 'help' => 'Name: value pairs separated by commas. Leave empty for a product without options.'],
            'compare_at_price' => ['need' => 'optional', 'example' => '550', 'help' => 'Shown crossed out next to your price.'],
            'cost_price' => ['need' => 'optional', 'example' => '300', 'help' => 'Your cost per unit. Private: only used for your profit reports.'],
            'brand' => ['need' => 'optional', 'example' => '', 'help' => 'A brand from the list below. Leave empty for factory-direct products.'],
            'model_number' => ['need' => 'optional', 'example' => '', 'help' => 'Manufacturer model number.'],
            'short_description' => ['need' => 'optional', 'example' => 'Breathable cotton polo for everyday wear.', 'help' => 'One or two sentences shown near the price.'],
            'full_description' => ['need' => 'optional', 'example' => '', 'help' => 'Materials, sizing, what is in the box.'],
            'key_features' => ['need' => 'optional', 'example' => '100% cotton | Machine washable', 'help' => 'Bullet points separated by “|”.'],
            'image_urls' => ['need' => 'optional', 'example' => 'https://example.com/polo-front.jpg', 'help' => 'Photo links (https://…) separated by “|”. The first is the cover. You can also add photos later.'],
            'warranty_period' => ['need' => 'optional', 'example' => '', 'help' => 'e.g. 6 months replacement warranty.'],
            'delivery_info' => ['need' => 'optional', 'example' => '', 'help' => 'e.g. Delivered in 24–48 hours inside Dhaka.'],
            'visible' => ['need' => 'optional', 'example' => 'yes', 'help' => 'yes or no. Empty means yes. Use no to import as hidden drafts.'],
        ];
    }

    /**
     * Worked examples built from this marketplace's real categories and units. Their titles start with
     * EXAMPLE, so they are skipped on import and uploading the untouched template creates nothing.
     *
     * @return array<int, array<string, string>>
     */
    public function exampleRows(): array
    {
        $category = Category::where('is_active', true)->whereNotNull('parent_id')->orderBy('id')->value('name')
            ?? Category::where('is_active', true)->orderBy('id')->value('name')
            ?? 'Category name';
        $unit = Unit::orderBy('id')->value('symbol') ?? 'pc';

        $rows = [
            [
                'product_title' => self::EXAMPLE_PREFIX.' - Cotton Polo Shirt', 'category' => $category, 'sell_to' => 'both', 'unit' => $unit,
                'stock' => '120', 'retail_price' => '450', 'wholesale_tiers' => '20-49:380; 50+:340', 'compare_at_price' => '550', 'cost_price' => '300',
                'short_description' => 'Breathable cotton polo for everyday wear.', 'key_features' => '100% cotton | Machine washable', 'visible' => 'yes',
            ],
            [
                'product_title' => self::EXAMPLE_PREFIX.' - Press-On Nails Set', 'category' => $category, 'sell_to' => 'wholesale', 'unit' => $unit,
                'stock' => '200', 'wholesale_tiers' => '50-99:110; 100-249:95; 250+:85', 'options' => 'Color: Pink, Length: Medium', 'cost_price' => '60',
                'short_description' => 'Salon-quality reusable press-on nails.', 'visible' => 'yes',
            ],
            // A second option of the same product: only the option, stock and (optionally) cost are needed
            ['product_title' => self::EXAMPLE_PREFIX.' - Press-On Nails Set', 'stock' => '150', 'options' => 'Color: Black, Length: Long', 'cost_price' => '60'],
        ];

        return array_map(fn (array $row): array => array_merge(array_fill_keys(array_keys(self::columns()), ''), $row), $rows);
    }

    /**
     * The downloadable template: every column plus the worked examples.
     */
    public function templateCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\u{FEFF}"); // UTF-8 BOM so Excel shows ৳ and Bangla text correctly
        fputcsv($handle, array_keys(self::columns()), ',', '"', '');
        foreach ($this->exampleRows() as $example) {
            fputcsv($handle, array_values($example), ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @return array{
     *     total_rows: int,
     *     products_created: int,
     *     variations_created: int,
     *     created: array<int, string>,
     *     sent_for_review: array<int, string>,
     *     examples_skipped: int,
     *     ignored_columns: array<int, string>,
     *     errors: array<int, string>,
     *     hidden_over_limit: array<int, string>,
     *     dry_run: bool
     * }
     */
    public function import(User $seller, string $csvContent, bool $dryRun = false): array
    {
        [$header, $rows] = $this->parse($csvContent);

        $missing = collect(self::columns())
            ->filter(fn (array $column): bool => $column['need'] === 'required')
            ->keys()
            ->reject(fn (string $column): bool => in_array($column, $header, true));

        if ($missing->isNotEmpty()) {
            $hint = in_array('new_stock_quantity', $header, true)
                ? ' This looks like a stock sheet: use “Stock sheet → Import stock sheet” for that.'
                : ' Download the template from this window and keep its header row.';

            throw new Exception('Missing column(s): '.$missing->implode(', ').'.'.$hint);
        }

        $ignoredColumns = array_values(array_intersect($header, self::GENERATED_COLUMNS));
        $lookups = $this->lookups();
        $existingTitles = Product::where('seller_id', $seller->id)->pluck('title')->map(fn (string $title): string => Str::lower(trim($title)))->flip();

        $result = [
            'total_rows' => count($rows),
            'products_created' => 0,
            'variations_created' => 0,
            'created' => [],
            'sent_for_review' => [],
            'examples_skipped' => 0,
            'ignored_columns' => $ignoredColumns,
            'errors' => [],
            'hidden_over_limit' => [],
            'dry_run' => $dryRun,
        ];

        // Products asked to be visible use up listing slots; the rest are imported as hidden drafts (MON-9)
        $engine = app(MonetizationEngineService::class);
        $isSuspended = $seller->sellerProfile?->monetization_status === 'suspended';
        $slotsLeft = $engine->remainingActivationSlots($seller);

        // Rows sharing a title are one product, wherever they sit in the sheet
        $groups = collect($rows)
            ->map(fn (array $cells, int $index): array => ['line' => $index + 2, 'data' => $this->combine($header, $cells)])
            ->groupBy(fn (array $row): string => Str::lower(trim($row['data']['product_title'])));

        foreach ($groups as $key => $groupRows) {
            $firstLine = $groupRows->first()['line'];

            if ($key === '') {
                foreach ($groupRows as $row) {
                    $result['errors'][] = "Row {$row['line']}: product_title is empty.";
                }

                continue;
            }

            if (Str::startsWith($key, Str::lower(self::EXAMPLE_PREFIX))) {
                $result['examples_skipped'] += $groupRows->count();

                continue;
            }

            if ($existingTitles->has($key)) {
                $result['errors'][] = "Row {$firstLine}: “{$groupRows->first()['data']['product_title']}” is already in your store, so it was not added again. Change its stock with the stock sheet instead.";

                continue;
            }

            $plan = $this->validateProduct($groupRows, $lookups);

            if ($plan['errors'] !== []) {
                array_push($result['errors'], ...$plan['errors']);

                continue;
            }

            if ($plan['product']['is_active']) {
                if ($isSuspended || $slotsLeft === 0) {
                    $plan['product']['is_active'] = false;
                    $result['hidden_over_limit'][] = $plan['product']['title'];
                } elseif ($slotsLeft !== null) {
                    $slotsLeft--;
                }
            }

            if (! $dryRun) {
                $product = DB::transaction(fn () => $this->createProduct($seller, $plan['product']));

                if ($plan['product']['is_active']) {
                    try {
                        app(ProductApprovalService::class)->submitProduct($product, $seller);
                        $result['sent_for_review'][] = $plan['product']['title'];
                    } catch (DomainException|AuthorizationException $e) {
                        report($e);
                    }
                }
            }

            $existingTitles->put($key, true);
            $result['products_created']++;
            $result['variations_created'] += count($plan['product']['variations']);
            $result['created'][] = $plan['product']['title'];
        }

        return $result;
    }

    /**
     * Reads the sheet with a real CSV parser, so quoted cells may contain commas and line breaks.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    protected function parse(string $csvContent): array
    {
        $csvContent = (string) preg_replace('/^\x{FEFF}/u', '', $csvContent);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csvContent);
        rewind($handle);

        $header = null;
        $rows = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $cells = array_map(fn (mixed $cell): string => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }

            if ($header === null) {
                // Accept headers such as "Product title *" or "product_title (required)"
                $header = array_map(fn (string $name): string => Str::snake(trim((string) preg_replace('/\(.*?\)|\*/', '', Str::lower($name)))), $cells);

                continue;
            }

            $rows[] = $cells;
        }
        fclose($handle);

        if ($header === null || $rows === []) {
            throw new Exception('The sheet has no product rows. Fill in at least one row under the header.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new Exception('The sheet has '.count($rows).' rows; the limit is '.self::MAX_ROWS.' per upload. Split it into smaller sheets.');
        }

        return [$header, $rows];
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $cells
     * @return array<string, string>
     */
    protected function combine(array $header, array $cells): array
    {
        $data = array_fill_keys(array_keys(self::columns()), '');
        foreach ($header as $index => $column) {
            if (array_key_exists($column, $data)) {
                $data[$column] = $cells[$index] ?? '';
            }
        }

        return $data;
    }

    /**
     * @return array{categories: Collection<string, int>, units: Collection<string, int>, brands: Collection<string, int>, channels: Collection<string, int>}
     */
    protected function lookups(): array
    {
        $byNameAndSlug = fn (Collection $records, string $alt): Collection => $records
            ->flatMap(fn ($record): array => [Str::lower($record->name) => $record->id, Str::lower((string) $record->{$alt}) => $record->id]);

        return [
            'categories' => $byNameAndSlug(Category::where('is_active', true)->get(['id', 'name', 'slug']), 'slug'),
            'units' => $byNameAndSlug(Unit::all(['id', 'name', 'symbol']), 'symbol'),
            'brands' => $byNameAndSlug(Brand::where('is_active', true)->get(['id', 'name', 'slug']), 'slug'),
            'channels' => SalesChannel::where('is_active', true)->pluck('id', 'slug'),
        ];
    }

    /**
     * Checks one product's rows against the "Add product" rules and builds what will be saved.
     *
     * @param  Collection<int, array{line: int, data: array<string, string>}>  $rows
     * @param  array<string, Collection<string, int>>  $lookups
     * @return array{errors: array<int, string>, product: array<string, mixed>}
     */
    protected function validateProduct(Collection $rows, array $lookups): array
    {
        $errors = [];
        $line = $rows->first()['line'];
        // Product-level details come from the first row that fills them in, so option rows can leave them empty
        $value = fn (string $column): string => (string) $rows->map(fn (array $row): string => $row['data'][$column])->first(fn (string $cell): bool => $cell !== '', '');
        $fail = function (string $message, ?int $atLine = null) use (&$errors, $line): void {
            $errors[] = 'Row '.($atLine ?? $line).": {$message}";
        };

        $title = $value('product_title');
        if (mb_strlen($title) > 255) {
            $fail('product_title is longer than 255 characters.');
        }

        $categoryId = $this->resolve($lookups['categories'], $value('category'), 'category', 'Use a category name from the list in the import window.', $fail);
        $unitId = $this->resolve($lookups['units'], $value('unit'), 'unit', 'Use one of: '.Unit::pluck('symbol')->implode(', ').'.', $fail);
        $brandId = $value('brand') === '' ? null : $this->resolve($lookups['brands'], $value('brand'), 'brand', 'Use a brand from the list, or leave it empty.', $fail);

        $sellTo = Str::lower($value('sell_to'));
        $channelId = $lookups['channels'][$sellTo] ?? null;
        if ($channelId === null) {
            $fail($sellTo === '' ? 'sell_to is empty. Use retail, wholesale or both.' : "sell_to “{$value('sell_to')}” is not valid. Use retail, wholesale or both.");
        }

        $prices = [];
        $retailPrice = $value('retail_price');
        if ($retailPrice !== '') {
            if (! is_numeric($retailPrice) || (float) $retailPrice <= 0) {
                $fail("retail_price “{$retailPrice}” must be a number above 0.");
            } else {
                $prices[] = ['channel' => SalesChannel::RETAIL, 'min_quantity' => 1, 'max_quantity' => null, 'unit_price' => (float) $retailPrice];
            }
        } elseif (in_array($sellTo, [SalesChannel::RETAIL, SalesChannel::BOTH], true)) {
            $fail('retail_price is required when sell_to is retail or both: shoppers need a price to buy.');
        }

        $tiersCell = $value('wholesale_tiers');
        if ($tiersCell !== '') {
            [$tiers, $tierError] = self::parseWholesaleTiers($tiersCell);
            if ($tierError !== null) {
                $fail($tierError);
            } else {
                array_push($prices, ...$tiers);
            }
        } elseif (in_array($sellTo, [SalesChannel::WHOLESALE, SalesChannel::BOTH], true)) {
            $fail('wholesale_tiers is required when sell_to is wholesale or both, e.g. 50-99:110; 100+:95.');
        }

        foreach (['compare_at_price', 'cost_price'] as $column) {
            $cell = $value($column);
            if ($cell !== '' && (! is_numeric($cell) || (float) $cell < 0)) {
                $fail("{$column} “{$cell}” must be a number, 0 or more.");
            }
        }

        $images = collect(explode('|', $value('image_urls')))->map(fn (string $url): string => trim($url))->filter()->values();
        foreach ($images as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL) || ! Str::startsWith(Str::lower($url), ['http://', 'https://'])) {
                $fail("image link “{$url}” is not a web address (it must start with https://).");
            }
        }

        $visible = Str::lower($value('visible'));
        if (! in_array($visible, ['', 'yes', 'no', 'y', 'n', 'true', 'false', '1', '0'], true)) {
            $fail("visible “{$value('visible')}” must be yes or no.");
        }

        // Variations: one per row
        $hasOptions = $rows->contains(fn (array $row): bool => $row['data']['options'] !== '');
        if ($rows->count() > 1 && $rows->contains(fn (array $row): bool => $row['data']['options'] === '')) {
            $fail('this product has '.$rows->count().' rows, so every row needs options (e.g. Color: Pink) to tell them apart.', $rows->first(fn (array $row): bool => $row['data']['options'] === '')['line']);
        }

        $variations = [];
        $seenOptions = [];
        foreach ($rows as $row) {
            $stock = $row['data']['stock'];
            if ($stock === '' || ! ctype_digit($stock)) {
                $fail($stock === '' ? 'stock is empty. Use a whole number, 0 or more.' : "stock “{$stock}” must be a whole number, 0 or more.", $row['line']);
            }

            $cost = $row['data']['cost_price'];
            if ($cost !== '' && (! is_numeric($cost) || (float) $cost < 0)) {
                $fail("cost_price “{$cost}” must be a number, 0 or more.", $row['line']);
            }

            [$options, $optionError] = self::parseOptions($row['data']['options']);
            if ($optionError !== null) {
                $fail($optionError, $row['line']);
            }

            $signature = collect($options)->map(fn (string $v, string $k): string => Str::lower("{$k}={$v}"))->sort()->implode('|');
            if ($hasOptions && $signature !== '' && isset($seenOptions[$signature])) {
                $fail("the options “{$row['data']['options']}” appear twice for this product.", $row['line']);
            }
            $seenOptions[$signature] = true;

            $variations[] = [
                'options' => $options,
                'stock_quantity' => (int) $stock,
                'buy_price' => $cost !== '' ? (float) $cost : (float) ($value('cost_price') ?: 0),
            ];
        }

        return [
            'errors' => $errors,
            'product' => [
                'title' => $title,
                'category_id' => $categoryId,
                'unit_id' => $unitId,
                'brand_id' => $brandId,
                'sales_channel_id' => $channelId,
                'has_variations' => $hasOptions,
                'regular_price' => $value('compare_at_price') !== '' ? (float) $value('compare_at_price') : null,
                'buy_price' => (float) ($value('cost_price') ?: 0),
                'model_number' => $value('model_number') ?: null,
                'short_description' => $value('short_description') ?: null,
                'full_description' => $value('full_description') ?: null,
                'features' => collect(explode('|', $value('key_features')))->map(fn (string $f): string => trim($f))->filter()->values()->all() ?: null,
                'warranty_period' => $value('warranty_period') ?: null,
                'delivery_info' => $value('delivery_info') ?: null,
                'is_active' => ! in_array($visible, ['no', 'n', 'false', '0'], true),
                'images' => $images->all(),
                'prices' => $prices,
                'variations' => $variations,
            ],
        ];
    }

    /**
     * @param  Collection<string, int>  $lookup
     */
    protected function resolve(Collection $lookup, string $cell, string $column, string $hint, callable $fail): ?int
    {
        if ($cell === '') {
            $fail("{$column} is empty. {$hint}");

            return null;
        }

        $id = $lookup[Str::lower($cell)] ?? null;
        if ($id === null) {
            $fail("{$column} “{$cell}” was not found. {$hint}");
        }

        return $id;
    }

    /**
     * "50-99:110; 100-249:95; 250+:85" → price tiers. Tiers must climb without gaps overlapping, and only the last
     * may be open-ended, so every quantity maps to exactly one price.
     *
     * @return array{0: array<int, array{channel: string, min_quantity: int, max_quantity: ?int, unit_price: float}>, 1: ?string}
     */
    public static function parseWholesaleTiers(string $cell): array
    {
        $tiers = [];
        foreach (array_filter(array_map('trim', explode(';', $cell))) as $part) {
            if (! preg_match('/^(\d+)\s*(?:-\s*(\d+)|\+)?\s*[:=@]\s*৳?\s*(\d+(?:\.\d{1,2})?)$/u', $part, $match)) {
                return [[], "wholesale tier “{$part}” is not in the form 50-99:110 or 250+:85."];
            }

            $min = (int) $match[1];
            $max = $match[2] !== '' ? (int) $match[2] : null;
            $price = (float) $match[3];

            if ($min < 1 || ($max !== null && $max < $min) || $price <= 0) {
                return [[], "wholesale tier “{$part}”: quantities start at 1, the range must go up, and the price must be above 0."];
            }

            $tiers[] = ['channel' => SalesChannel::WHOLESALE, 'min_quantity' => $min, 'max_quantity' => $max, 'unit_price' => $price];
        }

        if ($tiers === []) {
            return [[], 'wholesale_tiers is empty, e.g. 50-99:110; 100+:95.'];
        }

        usort($tiers, fn (array $a, array $b): int => $a['min_quantity'] <=> $b['min_quantity']);
        foreach ($tiers as $index => $tier) {
            $next = $tiers[$index + 1] ?? null;
            if ($next !== null && ($tier['max_quantity'] === null || $next['min_quantity'] <= $tier['max_quantity'])) {
                return [[], 'wholesale tiers overlap: each tier must start above the previous one’s maximum, and only the last tier may use “+”.'];
            }
        }

        return [$tiers, null];
    }

    /**
     * "Color: Pink, Length: Medium" → ['Color' => 'Pink', 'Length' => 'Medium'].
     *
     * @return array{0: array<string, string>, 1: ?string}
     */
    public static function parseOptions(string $cell): array
    {
        $options = [];
        foreach (array_filter(array_map('trim', explode(',', $cell))) as $pair) {
            if (! str_contains($pair, ':')) {
                return [[], "option “{$pair}” needs a name and a value, e.g. Color: Pink."];
            }

            [$name, $optionValue] = array_map('trim', explode(':', $pair, 2));
            if ($name === '' || $optionValue === '') {
                return [[], "option “{$pair}” needs a name and a value, e.g. Color: Pink."];
            }

            if (mb_strlen($name) > 100 || mb_strlen($optionValue) > 255) {
                return [[], "option “{$pair}” is too long (name up to 100 characters, value up to 255)."];
            }

            if (isset($options[$name])) {
                return [[], "option “{$name}” is listed twice on the same row."];
            }

            $options[$name] = $optionValue;
        }

        return [$options, null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createProduct(User $seller, array $data): Product
    {
        $product = Product::create([
            'seller_id' => $seller->id,
            'slug' => $this->uniqueSlug($data['title']),
            ...collect($data)->except(['images', 'prices', 'variations'])->all(),
        ]);

        $channelIds = SalesChannel::pluck('id', 'slug');
        foreach ($data['prices'] as $price) {
            $product->prices()->create([
                'sales_channel_id' => $channelIds[$price['channel']],
                'min_quantity' => $price['min_quantity'],
                'max_quantity' => $price['max_quantity'],
                'unit_price' => $price['unit_price'],
            ]);
        }

        foreach ($data['images'] as $index => $url) {
            $product->images()->create(['image_url' => $url, 'sort_order' => $index + 1, 'is_primary' => $index === 0]);
        }

        if ($data['has_variations']) {
            foreach ($data['variations'] as $variationData) {
                $variation = $product->variations()->create([
                    'stock_quantity' => $variationData['stock_quantity'],
                    'buy_price' => $variationData['buy_price'],
                    'is_active' => true,
                ]);
                $variation->attributeValues()->sync($this->attributeValueIds($variationData['options']));
            }
        } else {
            // The created hook already made the default variation (PRA-011); give it the sheet's stock
            $product->ensureDefaultVariation()->update(['stock_quantity' => $data['variations'][0]['stock_quantity']]);
        }

        return $product;
    }

    /**
     * Matches option names and values to the shared attribute list, adding any that do not exist yet.
     *
     * @param  array<string, string>  $options
     * @return array<int, int>
     */
    protected function attributeValueIds(array $options): array
    {
        return collect($options)->map(function (string $optionValue, string $name): int {
            $attribute = Attribute::whereRaw('lower(name) = ?', [Str::lower($name)])->first()
                ?? Attribute::create(['name' => $name, 'slug' => $this->uniqueAttributeSlug($name)]);

            return (AttributeValue::where('attribute_id', $attribute->id)->whereRaw('lower(value) = ?', [Str::lower($optionValue)])->first()
                ?? AttributeValue::create(['attribute_id' => $attribute->id, 'value' => $optionValue]))->id;
        })->values()->all();
    }

    protected function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'product';
        $slug = $base;
        $suffix = 2;
        while (Product::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    protected function uniqueAttributeSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'option';
        $slug = $base;
        $suffix = 2;
        while (Attribute::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
