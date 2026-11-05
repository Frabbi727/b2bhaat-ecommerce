<?php

namespace App\Services\Inventory;

use App\Models\ProductVariation;
use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StockBulkUpdateService
{
    /**
     * Generate streamed CSV export of seller's variations.
     */
    public function exportCsvForSeller(User $seller): StreamedResponse
    {
        $profile = $seller->sellerProfile;
        $storeSlug = $profile?->store_slug ?? 'store-'.$seller->id;
        $timestamp = now()->format('Ymd-His');
        $filename = "b2bhaat-stock-inventory-{$storeSlug}-{$timestamp}.csv";

        $variations = ProductVariation::with(['product.unit', 'attributeValues.attribute'])
            ->whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        return response()->stream(function () use ($variations) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Microsoft Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Header row
            fputcsv($handle, [
                'variation_id',
                'sku',
                'product_title',
                'attributes',
                'unit',
                'current_stock_quantity',
                'new_stock_quantity',
            ]);

            foreach ($variations as $var) {
                $attrs = $var->attributeValues
                    ->map(fn ($av) => "{$av->attribute->name}: {$av->value}")
                    ->implode(', ');

                fputcsv($handle, [
                    $var->id,
                    $var->sku,
                    $var->product?->title,
                    $attrs ?: 'Default',
                    $var->product?->unit?->symbol ?? 'pcs',
                    $var->stock_quantity,
                    $var->stock_quantity, // Pre-filled for quick adjustment
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }

    /**
     * Process bulk stock update from CSV text or uploaded file.
     *
     * @return array{
     *     total_rows: int,
     *     updated: int,
     *     unchanged: int,
     *     errors: array<int, string>
     * }
     */
    public function importCsvForSeller(User $seller, string|UploadedFile $csvInput): array
    {
        if ($csvInput instanceof UploadedFile) {
            $content = file_get_contents($csvInput->getRealPath());
        } elseif (is_string($csvInput) && file_exists($csvInput)) {
            $content = file_get_contents($csvInput);
        } else {
            $content = (string) $csvInput;
        }

        // Strip UTF-8 BOM if present
        $bom = pack('H*', 'EFBBBF');
        $content = preg_replace("/^{$bom}/", '', $content);

        // Normalize line breaks
        $rawLines = explode("\n", str_replace(["\r\n", "\r"], "\n", trim($content)));

        $rows = [];
        foreach ($rawLines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parsed = str_getcsv($line, ',', '"', '');
            if (! empty($parsed)) {
                $rows[] = $parsed;
            }
        }

        if (empty($rows)) {
            throw new Exception('The uploaded CSV file is empty.');
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($rows));

        // Locate column indices
        $variationIdIdx = array_search('variation_id', $header);
        $skuIdx = array_search('sku', $header);
        $newStockIdx = array_search('new_stock_quantity', $header);

        if ($newStockIdx === false) {
            $newStockIdx = array_search('new_stock', $header);
        }
        if ($newStockIdx === false) {
            $newStockIdx = array_search('stock_quantity', $header);
        }
        if ($newStockIdx === false) {
            $newStockIdx = array_search('stock', $header);
        }

        if ($variationIdIdx === false && $skuIdx === false) {
            throw new Exception("Missing required identifier column. CSV must contain either 'variation_id' or 'sku'.");
        }

        if ($newStockIdx === false) {
            throw new Exception("Missing target stock column. CSV must contain 'new_stock_quantity' or 'stock_quantity'.");
        }

        $updated = 0;
        $unchanged = 0;
        $errors = [];

        DB::transaction(function () use ($seller, $rows, $variationIdIdx, $skuIdx, $newStockIdx, &$updated, &$unchanged, &$errors) {
            foreach ($rows as $rowIndex => $row) {
                $rowNumber = $rowIndex + 2; // +1 for 0-index, +1 for header
                $varId = ($variationIdIdx !== false && isset($row[$variationIdIdx])) ? trim((string) $row[$variationIdIdx]) : null;
                $sku = ($skuIdx !== false && isset($row[$skuIdx])) ? trim((string) $row[$skuIdx]) : null;
                $stockVal = isset($row[$newStockIdx]) ? trim((string) $row[$newStockIdx]) : null;

                if (! is_numeric($stockVal) || (int) $stockVal < 0) {
                    $errors[] = "Row {$rowNumber}: '{$stockVal}' is not a valid quantity (use a whole number, 0 or more).";

                    continue;
                }

                $newQty = (int) $stockVal;

                // Find variation belonging to this seller
                $variation = null;
                if ($varId) {
                    $variation = ProductVariation::whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
                        ->find($varId);
                }

                if (! $variation && $sku) {
                    $variation = ProductVariation::whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))
                        ->where('sku', $sku)
                        ->first();
                }

                if (! $variation) {
                    $identifier = $sku ?: ($varId ? "ID {$varId}" : 'unknown');
                    $errors[] = "Row {$rowNumber}: '{$identifier}' not found in your catalog.";

                    continue;
                }

                if ($variation->stock_quantity === $newQty) {
                    $unchanged++;
                } else {
                    $variation->update(['stock_quantity' => $newQty]);
                    $updated++;
                }
            }
        });

        return [
            'total_rows' => count($rows),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'errors' => $errors,
        ];
    }
}
