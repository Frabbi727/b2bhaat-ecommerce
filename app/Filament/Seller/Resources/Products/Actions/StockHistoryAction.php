<?php

declare(strict_types=1);

namespace App\Filament\Seller\Resources\Products\Actions;

use App\Models\Product;
use App\Models\StockMovement;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * AR-08: the product's last stock changes from the stock ledger: how much, why, by whom and for which order.
 */
class StockHistoryAction
{
    public const SHOWN = 50;

    public static function make(): Action
    {
        return Action::make('stockHistory')
            ->label('Stock history')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->modalHeading(fn (Product $record): string => "Stock history · {$record->title}")
            ->modalDescription('Every change to this product\'s stock, newest first.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (Product $record) => view('filament.seller.products.stock-history', [
                'movements' => StockMovement::query()
                    ->whereIn('product_variation_id', $record->variations()->select('id'))
                    ->with(['reference', 'user:id,name', 'variation:id,sku'])
                    ->latest('id')
                    ->limit(self::SHOWN)
                    ->get(),
                'shown' => self::SHOWN,
                'hasOptions' => (bool) $record->has_variations,
            ]));
    }
}
