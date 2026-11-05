<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** Stock shown when the form was opened. */
    public ?int $loadedSimpleStock = null;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();
        $data['simple_stock_quantity'] = (int) ($product->variations()->orderBy('id')->value('stock_quantity') ?? 0);
        $this->loadedSimpleStock = $data['simple_stock_quantity'];

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $submittedStock = (int) ($this->data['simple_stock_quantity'] ?? 0);

        // Write stock only when the seller changed it, so units sold while the form was open are not restored (M3)
        if (! $product->has_variations && $submittedStock !== $this->loadedSimpleStock) {
            $product->ensureDefaultVariation()->update(['stock_quantity' => $submittedStock]);
        }
    }
}
