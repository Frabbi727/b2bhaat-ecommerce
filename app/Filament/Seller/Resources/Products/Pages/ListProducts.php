<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\Actions\ImportProductsAction;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Services\Inventory\StockBulkUpdateService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export_stock_csv')
                    ->label('Export stock sheet')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(route('seller.products.stock.export'))
                    ->openUrlInNewTab(),

                Action::make('bulk_update_stock')
                    ->label('Import stock sheet')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->modalHeading('Update stock from a sheet')
                    ->modalDescription('Updates stock for products already in your store; it cannot add new products. Export the stock sheet, change only the new_stock_quantity column, then upload it here. Rows are matched by variation_id or sku.')
                    ->modalSubmitActionLabel('Update stock')
                    ->form([
                        Textarea::make('csv_text')
                            ->label('Paste CSV or Tabular Data')
                            ->placeholder("variation_id,sku,new_stock_quantity\n1,NAILS-PINK-S,150\n2,NAILS-PINK-M,200")
                            ->rows(6)
                            ->helperText('Include headers: variation_id (or sku) and new_stock_quantity.'),

                        FileUpload::make('csv_file')
                            ->label('Or Upload CSV File')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->disk('local')
                            ->directory('temp_csv_imports'),
                    ])
                    ->action(function (array $data, StockBulkUpdateService $service) {
                        try {
                            $input = null;
                            if (! empty($data['csv_file'])) {
                                $path = Storage::disk('local')->path($data['csv_file']);
                                $input = $path;
                            } elseif (! empty($data['csv_text'])) {
                                $input = $data['csv_text'];
                            } else {
                                Notification::make()
                                    ->title('Input required')
                                    ->body('Please provide either CSV file upload or paste CSV text.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $result = $service->importCsvForSeller(auth()->user(), $input);

                            self::importResultNotification($result)->send();
                        } catch (Exception $e) {
                            Notification::make()
                                ->title('The stock sheet could not be read')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        } finally {
                            // Never keep uploaded sheets around, even when the import fails
                            if (! empty($data['csv_file'])) {
                                Storage::disk('local')->delete($data['csv_file']);
                            }
                        }
                    }),
            ])
                ->label('Stock sheet')
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->button(),

            ImportProductsAction::make(),

            CreateAction::make()
                ->label('Add product')
                ->icon(Heroicon::OutlinedPlus),
        ];
    }

    /**
     * Tell the seller what the sheet actually did. Rows that match nothing are the common mistake (a sheet listing
     * products that were never added), so name them and say how to add products instead of reporting success.
     *
     * @param  array{total_rows: int, updated: int, unchanged: int, errors: array<int, string>}  $result
     */
    public static function importResultNotification(array $result): Notification
    {
        $skipped = count($result['errors']);
        $updated = $result['updated'];
        $unchanged = $result['unchanged'];

        if ($skipped === 0) {
            return $updated > 0
                ? Notification::make()
                    ->title("Stock updated for {$updated} variation(s)")
                    ->body($unchanged > 0 ? "{$unchanged} row(s) already matched your current stock." : null)
                    ->success()
                : Notification::make()
                    ->title('Nothing to update')
                    ->body('Every quantity in the sheet already matches your current stock. Change the new_stock_quantity column, then upload again.')
                    ->info();
        }

        $examples = collect($result['errors'])->take(5)->map(fn (string $error): string => e($error))->implode('<br>');
        $more = $skipped > 5 ? '<br>…and '.($skipped - 5).' more.' : '';
        $summary = $updated > 0
            ? "Updated {$updated} variation(s). {$skipped} row(s) were skipped:"
            : ($unchanged > 0 ? "{$unchanged} row(s) already matched your stock. {$skipped} row(s) were skipped:" : "{$skipped} row(s) were skipped:");

        return Notification::make()
            ->title($updated > 0 ? 'Stock partly updated' : 'No stock was changed')
            ->body("{$summary}<br>{$examples}{$more}<br><br>This sheet only updates stock for products already in your store. To add new products use “Import products” (or “Add product”), then export a fresh stock sheet.")
            ->warning()
            ->persistent();
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $count = fn (callable $scope): int => $scope(ProductResource::getEloquentQuery())->count();

        return [
            'all' => Tab::make('All'),
            'visible' => Tab::make('Visible')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', true)->whereNull('blocked_at')),
            'hidden' => Tab::make('Hidden')
                ->badge($count(fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('is_active', false)->orWhereNotNull('blocked_at'))) ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn (Builder $w) => $w->where('is_active', false)->orWhereNotNull('blocked_at'))),
            'out_of_stock' => Tab::make('Out of stock')
                ->badge($count(fn (Builder $q) => $q->whereDoesntHave('variations', fn (Builder $v) => $v->where('stock_quantity', '>', 0))) ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDoesntHave('variations', fn (Builder $v) => $v->where('stock_quantity', '>', 0))),
        ];
    }
}
