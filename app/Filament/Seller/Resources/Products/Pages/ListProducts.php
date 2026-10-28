<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Services\Inventory\StockBulkUpdateService;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_stock_csv')
                ->label('Export Stock Sheet')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(route('seller.products.stock.export'))
                ->openUrlInNewTab(),

            Action::make('bulk_update_stock')
                ->label('Bulk Update Stock')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('indigo')
                ->modalHeading('Bulk Update Product Variations Stock (CSV)')
                ->modalDescription('Upload an updated CSV file or paste tabular data with variation_id or sku, and new_stock_quantity.')
                ->modalSubmitActionLabel('Update Stock Now')
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

                        // Clean up temporary uploaded file if any
                        if (! empty($data['csv_file'])) {
                            Storage::disk('local')->delete($data['csv_file']);
                        }

                        $warningText = ! empty($result['errors']) ? ' ('.count($result['errors']).' skipped with errors)' : '';

                        Notification::make()
                            ->title('Stock update complete!')
                            ->body("Successfully updated {$result['updated']} variation(s), {$result['unchanged']} unchanged{$warningText}.")
                            ->success()
                            ->send();
                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Bulk stock update failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            CreateAction::make(),
        ];
    }
}
