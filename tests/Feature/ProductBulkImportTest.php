<?php

namespace Tests\Feature;

use App\Filament\Seller\Resources\Products\Actions\ImportProductsAction;
use App\Filament\Seller\Resources\Products\Pages\ListProducts;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\User;
use App\Services\Inventory\ProductBulkImportService;
use App\Services\Inventory\ProductCodeGenerator;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\SystemLookupSeeder;
use Exception;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ProductBulkImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $seller;

    protected string $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemLookupSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->seller = User::where('email', 'seller@nailsfactory.com')->firstOrFail();
        $this->category = Category::where('is_active', true)->firstOrFail()->name;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    protected function sheet(array $rows): string
    {
        $columns = array_keys(ProductBulkImportService::columns());
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $column): string => $row[$column] ?? '', $columns), ',', '"', '');
        }
        rewind($handle);

        return (string) stream_get_contents($handle);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    protected function row(array $overrides = []): array
    {
        return array_merge([
            'product_title' => 'Nude Minimalist Press-On Nails Set',
            'category' => $this->category,
            'sell_to' => 'both',
            'unit' => 'pc',
            'stock' => '200',
            'retail_price' => '350',
            'wholesale_tiers' => '50-99:110; 100-249:95; 250+:85',
        ], $overrides);
    }

    protected function import(string $csv, bool $dryRun = false): array
    {
        return app(ProductBulkImportService::class)->import($this->seller, $csv, $dryRun);
    }

    public function test_template_has_every_column_and_examples_but_no_generated_codes(): void
    {
        $this->actingAs($this->seller);

        $response = $this->get(route('seller.products.import.template'))->assertOk();

        $this->assertStringContainsString('b2bhaat-product-import-template.csv', $response->headers->get('Content-Disposition'));
        $lines = preg_split('/\r?\n/', trim(preg_replace('/^\x{FEFF}/u', '', $response->getContent())));
        $header = str_getcsv($lines[0], ',', '"', '');

        $this->assertSame(array_keys(ProductBulkImportService::columns()), $header);
        $this->assertEmpty(array_intersect($header, ProductBulkImportService::GENERATED_COLUMNS));
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('EXAMPLE - Press-On Nails Set', $lines[2]);
    }

    public function test_template_is_only_for_sellers(): void
    {
        $this->get(route('seller.products.import.template'))->assertRedirect();

        $this->actingAs(User::where('email', 'customer@retail.com')->firstOrFail())
            ->get(route('seller.products.import.template'))
            ->assertForbidden();
    }

    public function test_uploading_the_untouched_template_creates_nothing(): void
    {
        $before = Product::count();

        $result = $this->import(app(ProductBulkImportService::class)->templateCsv());

        $this->assertSame(0, $result['products_created']);
        $this->assertSame(3, $result['examples_skipped']);
        $this->assertSame([], $result['errors']);
        $this->assertSame($before, Product::count());
        $this->assertStringContainsString('EXAMPLE rows', ImportProductsAction::resultNotification($result)->getBody());
    }

    public function test_simple_product_is_created_with_generated_codes_prices_and_stock_and_is_listed(): void
    {
        $result = $this->import($this->sheet([$this->row([
            'compare_at_price' => '450',
            'cost_price' => '60',
            'key_features' => 'Reusable | Includes glue',
            'image_urls' => 'https://example.com/nude-1.jpg | https://example.com/nude-2.jpg',
        ])]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['products_created']);

        $product = Product::where('seller_id', $this->seller->id)->where('title', 'Nude Minimalist Press-On Nails Set')->firstOrFail();

        $this->assertMatchesRegularExpression('/^[A-Z]{3}-\d{6}$/', $product->sku);
        $this->assertSame(ProductCodeGenerator::barcode($product->id), $product->barcode);
        $this->assertSame('nude-minimalist-press-on-nails-set', $product->slug);
        $this->assertFalse($product->has_variations);
        $this->assertTrue($product->is_active);
        $this->assertSame(['Reusable', 'Includes glue'], $product->features);
        $this->assertSame('60.00', $product->buy_price);
        $this->assertSame('450.00', $product->regular_price);
        $this->assertSame(200, $product->variations()->sole()->stock_quantity);
        $this->assertSame('https://example.com/nude-1.jpg', $product->primaryImage->image_url);

        $this->assertSame('350.00', $product->retailPrice->unit_price);
        $this->assertSame([[50, 99, '110.00'], [100, 249, '95.00'], [250, null, '85.00']], $product->wholesalePrices->map(fn ($p) => [$p->min_quantity, $p->max_quantity, $p->unit_price])->all());

        $this->approveProducts($product);

        $this->assertTrue(Product::listable()->whereKey($product->id)->exists(), 'An imported product with a retail price shows on the storefront');
    }

    public function test_rows_with_the_same_title_become_one_product_with_options(): void
    {
        $result = $this->import($this->sheet([
            $this->row(['options' => 'Color: Nude Sheer, Length: Short', 'stock' => '300']),
            ['product_title' => 'Nude Minimalist Press-On Nails Set', 'options' => 'Color: Nude Sheer, Length: Long', 'stock' => '150', 'cost_price' => '55'],
        ]));

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['products_created']);
        $this->assertSame(2, $result['variations_created']);

        $product = Product::where('title', 'Nude Minimalist Press-On Nails Set')->firstOrFail();
        $variations = $product->variations()->with('attributeValues.attribute')->orderBy('id')->get();

        $this->assertTrue($product->has_variations);
        $this->assertCount(2, $variations);
        $this->assertSame(["{$product->sku}-01", "{$product->sku}-02"], $variations->pluck('sku')->all());
        $this->assertSame([300, 150], $variations->pluck('stock_quantity')->all());
        $this->assertSame('55.00', $variations[1]->buy_price);
        $this->assertEqualsCanonicalizing(
            ['Color: Nude Sheer', 'Length: Long'],
            $variations[1]->attributeValues->map(fn ($v) => "{$v->attribute->name}: {$v->value}")->all(),
        );
        $this->assertSame(1, AttributeValue::whereRaw('lower(value) = ?', ['nude sheer'])->count(), 'A new value is added once under an existing option and reused');
        $this->assertSame(1, Attribute::whereRaw('lower(name) = ?', ['color'])->count(), 'Existing option names are matched, not duplicated');
    }

    public function test_an_unknown_option_name_is_reported_and_not_added_to_the_shared_list(): void
    {
        // INV-10: option names (Color, Length…) are a platform-wide list; a seller's sheet can't add to it.
        $result = $this->import($this->sheet([
            $this->row(['product_title' => 'Shaped Nails', 'options' => 'Color: Pink, Shape: Almond']),
            ['product_title' => 'Shaped Nails', 'options' => 'Color: Red, Shape: Coffin', 'stock' => '5'],
        ]));

        $this->assertSame([], $result['created']);
        $this->assertStringContainsString('option “Shape” is not in the option list', implode("\n", $result['errors']));
        $this->assertSame(0, Attribute::whereRaw('lower(name) = ?', ['shape'])->count());
        $this->assertFalse(Product::where('title', 'Shaped Nails')->exists());
    }

    public function test_each_product_follows_the_add_product_rules_and_bad_ones_are_reported_not_created(): void
    {
        $result = $this->import($this->sheet([
            $this->row(['product_title' => 'Good Product']),
            $this->row(['product_title' => 'No Retail Price', 'retail_price' => '']),
            $this->row(['product_title' => 'Unknown Category', 'category' => 'Spaceships']),
            $this->row(['product_title' => 'Bad Unit', 'unit' => 'barrel']),
            $this->row(['product_title' => 'Overlapping Tiers', 'wholesale_tiers' => '50+:110; 100+:95']),
            $this->row(['product_title' => 'Bad Stock', 'stock' => '-4']),
            $this->row(['product_title' => 'Bad Channel', 'sell_to' => 'everyone']),
            $this->row(['product_title' => 'Duplicate Options', 'options' => 'Color: Pink']),
            ['product_title' => 'Duplicate Options', 'options' => 'color: pink', 'stock' => '1'],
            $this->row(['product_title' => 'Missing Options', 'options' => 'Color: Red']),
            ['product_title' => 'Missing Options', 'stock' => '5'],
        ]));

        $this->assertSame(['Good Product'], $result['created']);
        $errors = implode("\n", $result['errors']);
        $this->assertStringContainsString('Row 3: retail_price is required', $errors);
        $this->assertStringContainsString('Row 4: category “Spaceships” was not found', $errors);
        $this->assertStringContainsString('Row 5: unit “barrel” was not found', $errors);
        $this->assertStringContainsString('Row 6: wholesale tiers overlap', $errors);
        $this->assertStringContainsString('Row 7: stock “-4” must be a whole number', $errors);
        $this->assertStringContainsString('Row 8: sell_to “everyone” is not valid', $errors);
        $this->assertStringContainsString('Row 10: the options “color: pink” appear twice', $errors);
        $this->assertStringContainsString('Row 12: this product has 2 rows, so every row needs options', $errors);

        $this->assertSame(1, Product::where('seller_id', $this->seller->id)->whereIn('title', ['Good Product', 'No Retail Price', 'Unknown Category', 'Bad Unit', 'Overlapping Tiers', 'Bad Stock', 'Bad Channel', 'Duplicate Options', 'Missing Options'])->count());
    }

    public function test_wholesale_only_product_needs_tiers_but_no_retail_price(): void
    {
        $result = $this->import($this->sheet([
            $this->row(['product_title' => 'Bulk Only', 'sell_to' => 'wholesale', 'retail_price' => '']),
            $this->row(['product_title' => 'Bulk Without Tiers', 'sell_to' => 'wholesale', 'retail_price' => '', 'wholesale_tiers' => '']),
        ]));

        $this->assertSame(['Bulk Only'], $result['created']);
        $this->assertStringContainsString('wholesale_tiers is required', $result['errors'][0]);
        $this->assertSame(SalesChannel::WHOLESALE, Product::where('title', 'Bulk Only')->firstOrFail()->salesChannel->slug);
    }

    public function test_importing_the_same_sheet_twice_does_not_duplicate_products_and_ignores_generated_columns(): void
    {
        $csv = $this->sheet([$this->row()]);
        $this->import($csv);

        $again = $this->import($csv);
        $this->assertSame(0, $again['products_created']);
        $this->assertStringContainsString('already in your store', $again['errors'][0]);
        $this->assertSame(1, Product::where('title', 'Nude Minimalist Press-On Nails Set')->count());

        $withCodes = "sku,barcode,product_title,category,sell_to,unit,stock,retail_price\nMY-SKU,123,Typed Code Product,{$this->category},retail,pc,5,99\n";
        $result = $this->import($withCodes);
        $this->assertSame(['sku', 'barcode'], $result['ignored_columns']);
        $this->assertNotSame('MY-SKU', Product::where('title', 'Typed Code Product')->value('sku'));
    }

    public function test_check_only_reports_without_creating(): void
    {
        $before = Product::count();

        $result = $this->import($this->sheet([$this->row()]), dryRun: true);

        $this->assertSame(1, $result['products_created']);
        $this->assertSame($before, Product::count());
        $this->assertSame('Sheet looks good: 1 product ready to import', ImportProductsAction::resultNotification($result)->getTitle());
    }

    public function test_a_stock_sheet_uploaded_here_gets_a_clear_message(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This looks like a stock sheet');

        $this->import("variation_id,sku,product_title,attributes,unit,current_stock_quantity,new_stock_quantity\n1,NAIL-PNK-MED,Nails,Color: Pink,pc,5,6\n");
    }

    public function test_quoted_cells_may_contain_commas_and_line_breaks(): void
    {
        $result = $this->import($this->sheet([$this->row(['full_description' => "Line one, with a comma.\nLine two."])]));

        $this->assertSame([], $result['errors']);
        $this->assertSame("Line one, with a comma.\nLine two.", Product::where('title', 'Nude Minimalist Press-On Nails Set')->value('full_description'));
    }

    public function test_seller_imports_from_the_products_page_into_their_own_store(): void
    {
        $this->actingAs($this->seller);
        Filament::setCurrentPanel(Filament::getPanel('seller'));

        Livewire::test(ListProducts::class)
            ->mountAction('import_products')
            ->assertMountedActionModalSee(['How it works', 'Filled in automatically', 'EXAMPLE - Press-On Nails Set', $this->category])
            ->setActionData(['csv_file' => UploadedFile::fake()->createWithContent('products.csv', $this->sheet([$this->row()]))])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('1 product added');

        $this->assertSame($this->seller->id, Product::where('title', 'Nude Minimalist Press-On Nails Set')->value('seller_id'));
    }

    public function test_wholesale_tier_formats(): void
    {
        [$tiers, $error] = ProductBulkImportService::parseWholesaleTiers('100+ : ৳95 ; 50-99@110');
        $this->assertNull($error);
        $this->assertSame([[50, 99, 110.0], [100, null, 95.0]], array_map(fn ($t) => [$t['min_quantity'], $t['max_quantity'], $t['unit_price']], $tiers));

        $this->assertNotNull(ProductBulkImportService::parseWholesaleTiers('99-50:10')[1]);
        $this->assertNotNull(ProductBulkImportService::parseWholesaleTiers('fifty:10')[1]);
        $this->assertNotNull(ProductBulkImportService::parseWholesaleTiers('50-99:0')[1]);
    }
}
