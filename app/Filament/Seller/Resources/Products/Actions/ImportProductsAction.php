<?php

namespace App\Filament\Seller\Resources\Products\Actions;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Unit;
use App\Services\Inventory\ProductBulkImportService;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * "Import products": creates new products from a spreadsheet. The window teaches the format in place (steps, a
 * worked example, every column, the allowed values) so a seller can fill the sheet without leaving the page.
 */
class ImportProductsAction
{
    public static function make(): Action
    {
        return Action::make('import_products')
            ->label('Import products')
            ->icon(Heroicon::OutlinedTableCells)
            ->color('gray')
            ->modalHeading('Import products from a spreadsheet')
            ->modalDescription('Add many new products at once. Each product follows the same rules as “Add product”.')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Import products')
            ->schema([
                Callout::make('How it works')
                    ->info()
                    ->description(new HtmlString(
                        '1. Download the template. It has example rows showing how to fill it in.<br>'
                        .'2. Replace the EXAMPLE rows with your products: one row per product, or one row per option (size, colour…).<br>'
                        .'3. Save it as CSV and upload it below. Tick “Check only” first if you want a dry run.'
                    ))
                    ->actions([
                        Action::make('download_product_template')
                            ->label('Download template')
                            ->icon(Heroicon::OutlinedArrowDownTray)
                            ->url(route('seller.products.import.template')),
                    ]),

                Callout::make('Filled in automatically')
                    ->success()
                    ->icon(Heroicon::OutlinedSparkles)
                    ->description('Every product gets its SKU, EAN-13 barcode, product URL and option SKUs from the system. Leave them out of the sheet; if a sheet contains them they are ignored.'),

                Section::make('Example: how rows become products')
                    ->description('Rows with the same product_title become one product with options. Option rows only need options and stock.')
                    ->collapsible()
                    ->schema([Html::make(fn (): HtmlString => self::exampleTable())]),

                Section::make('All columns')
                    ->description('Only the required columns must be filled. Leave the others empty if you do not need them.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([Html::make(fn (): HtmlString => self::columnsTable())]),

                Section::make('Allowed values')
                    ->description('category, unit and brand must match one of these exactly (capital letters do not matter).')
                    ->collapsible()
                    ->collapsed()
                    ->schema([Html::make(fn (): HtmlString => self::allowedValues())]),

                FileUpload::make('csv_file')
                    ->label('Product sheet (CSV)')
                    ->helperText('In Excel or Google Sheets choose File → Save as / Download → CSV. Up to '.ProductBulkImportService::MAX_ROWS.' rows.')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('temp_csv_imports')
                    ->required(),

                Toggle::make('check_only')
                    ->label('Check only: report problems without creating any products')
                    ->default(false),
            ])
            ->action(function (array $data, ProductBulkImportService $service): void {
                $path = $data['csv_file'] ?? null;

                try {
                    $result = $service->import(auth()->user(), (string) Storage::disk('local')->get($path), (bool) ($data['check_only'] ?? false));

                    self::resultNotification($result)->send();
                } catch (Exception $e) {
                    Notification::make()
                        ->title('The sheet could not be read')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();
                } finally {
                    // Never keep uploaded sheets around
                    if ($path) {
                        Storage::disk('local')->delete($path);
                    }
                }
            });
    }

    /**
     * @param  array{total_rows: int, products_created: int, variations_created: int, created: array<int, string>, sent_for_review?: array<int, string>, examples_skipped: int, ignored_columns: array<int, string>, errors: array<int, string>, hidden_over_limit?: array<int, string>, dry_run: bool}  $result
     */
    public static function resultNotification(array $result): Notification
    {
        $created = $result['products_created'];
        $errors = $result['errors'];
        $products = $created === 1 ? '1 product' : "{$created} products";
        $lines = [];

        if ($result['dry_run']) {
            $title = $errors === [] ? ($created > 0 ? "Sheet looks good: {$products} ready to import" : 'No products found in the sheet') : "{$products} ready, ".count($errors).' problem(s) to fix';
            if ($created > 0 && $errors === []) {
                $lines[] = 'Nothing was created yet. Untick “Check only” and upload the sheet again to add them.';
            }
        } elseif ($created > 0) {
            $title = $errors === [] ? "{$products} added" : "{$products} added, ".count($errors).' row problem(s)';
            $lines[] = collect($result['created'])->take(5)->implode(', ').($created > 5 ? ' and '.($created - 5).' more.' : '.');
            $lines[] = 'Open a product to add photos, specifications or promotions.';
        } else {
            $title = $errors === [] ? 'No products found in the sheet' : 'No products were added';
        }

        if ($created === 0 && $errors === [] && $result['examples_skipped'] > 0) {
            $lines[] = 'The sheet only has the template’s EXAMPLE rows. Replace them with your own products, then upload again.';
        }

        if ($errors !== []) {
            $lines[] = '<strong>Fix these rows and upload them again'.($created > 0 && ! $result['dry_run'] ? ' (products already added are skipped)' : '').':</strong>';
            array_push($lines, ...collect($errors)->take(8)->map(fn (string $error): string => e($error))->all());
            if (count($errors) > 8) {
                $lines[] = '…and '.(count($errors) - 8).' more.';
            }
        }

        $sentForReview = $result['sent_for_review'] ?? [];
        if ($sentForReview !== []) {
            $lines[] = '<strong>'.count($sentForReview).' sent for review.</strong> Buyers will see them once an admin approves them.';
        }

        $hidden = $result['hidden_over_limit'] ?? [];
        if ($hidden !== []) {
            $lines[] = '<strong>'.count($hidden).' '.($result['dry_run'] ? 'would be' : 'were').' saved as hidden drafts</strong> because your plan\'s listing limit is reached or your store is suspended: '
                .e(collect($hidden)->take(5)->implode(', ')).(count($hidden) > 5 ? ' and '.(count($hidden) - 5).' more.' : '.');
        }

        if ($result['ignored_columns'] !== []) {
            $lines[] = 'Ignored column(s) '.e(implode(', ', $result['ignored_columns'])).': the system generates these.';
        }

        $status = match (true) {
            $errors === [] && $created > 0 => 'success',
            $errors === [] => 'info',
            $created > 0 => 'warning',
            default => 'danger',
        };

        return Notification::make()
            ->title($title)
            ->body(implode('<br>', $lines) ?: null)
            ->status($status)
            ->when($errors !== [], fn (Notification $notification) => $notification->persistent());
    }

    protected static function exampleTable(): HtmlString
    {
        $columns = ['product_title', 'sell_to', 'retail_price', 'wholesale_tiers', 'options', 'stock'];
        $body = collect(app(ProductBulkImportService::class)->exampleRows())->map(fn (array $row): string => '<tr>'.implode('', array_map(
            fn (string $column): string => '<td style="'.self::cellStyle().'">'.($row[$column] === '' ? '<span style="opacity:.45">empty</span>' : e($row[$column])).'</td>',
            $columns,
        )).'</tr>')->implode('');

        return new HtmlString(
            self::tableOpen($columns).$body.'</tbody></table></div>'
            .'<p style="margin-top:.75rem;font-size:.875rem;opacity:.8">Result: <strong>Cotton Polo Shirt</strong> becomes one product with 120 in stock, sold in the shop at ৳450 and wholesale at ৳380 (20–49 units) or ৳340 (50+). '
            .'<strong>Press-On Nails Set</strong> becomes one wholesale product with two options, Pink/Medium (200) and Black/Long (150), with a minimum order of 50. '
            .'Rows whose name starts with “EXAMPLE” are never imported.</p>'
        );
    }

    protected static function columnsTable(): HtmlString
    {
        $need = fn (array $column): string => match ($column['need']) {
            'required' => '<strong>Required</strong>',
            'conditional' => 'When '.e($column['when'] ?? ''),
            default => '<span style="opacity:.7">Optional</span>',
        };

        $body = collect(ProductBulkImportService::columns())->map(fn (array $column, string $name): string => '<tr>'
            .'<td style="'.self::cellStyle().'"><code>'.e($name).'</code></td>'
            .'<td style="'.self::cellStyle().'">'.$need($column).'</td>'
            .'<td style="'.self::cellStyle().'">'.e($column['help']).($column['example'] !== '' ? '<br><span style="opacity:.7">e.g. '.e($column['example']).'</span>' : '').'</td>'
            .'</tr>')->implode('');

        return new HtmlString(self::tableOpen(['Column', 'Needed', 'What to enter']).$body.'</tbody></table></div>');
    }

    protected static function allowedValues(): HtmlString
    {
        $list = fn (string $label, iterable $values): string => '<p style="margin:0 0 .75rem;font-size:.875rem"><strong>'.e($label).':</strong> '
            .collect($values)->map(fn (string $value): string => '<code>'.e($value).'</code>')->implode(', ').'</p>';

        $categories = Category::where('is_active', true)->orderBy('name')->pluck('name');
        $brands = Brand::where('is_active', true)->orderBy('name')->pluck('name');

        return new HtmlString(
            $list('sell_to', ['retail', 'wholesale', 'both'])
            .$list('category', $categories)
            .$list('unit', Unit::orderBy('id')->get()->map(fn (Unit $unit): string => "{$unit->symbol} ({$unit->name})"))
            .$list('brand', $brands->isEmpty() ? ['none yet'] : $brands)
            .'<p style="margin:0;font-size:.875rem;opacity:.8">Options (e.g. Color, Size, Length) can use any name and value; new ones are added for you. Need a category or unit that is not listed? Ask marketplace support to add it.</p>'
        );
    }

    /**
     * @param  array<int, string>  $headings
     */
    protected static function tableOpen(array $headings): string
    {
        return '<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse;font-size:.8125rem;text-align:left"><thead><tr>'
            .implode('', array_map(fn (string $heading): string => '<th style="'.self::cellStyle().'font-weight:600;white-space:nowrap">'.e($heading).'</th>', $headings))
            .'</tr></thead><tbody>';
    }

    protected static function cellStyle(): string
    {
        return 'padding:.4rem .6rem;border-bottom:1px solid color-mix(in oklab, currentColor 14%, transparent);vertical-align:top;';
    }
}
