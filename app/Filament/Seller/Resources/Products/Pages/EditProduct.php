<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\Products\Schemas\ProductForm;
use App\Filament\Seller\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** Stock shown when the form was opened. */
    public ?int $loadedSimpleStock = null;

    public function getSubheading(): ?string
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return match (true) {
            (bool) $product->blocked_at => 'Hidden by the marketplace',
            $product->is_active => 'Live on your storefront · last saved '.$product->updated_at?->diffForHumans(),
            default => 'Hidden draft · last saved '.$product->updated_at?->diffForHumans(),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductsTable::viewOnStorefrontAction()
                ->button()
                ->color('gray'),

            ActionGroup::make([
                ProductsTable::duplicateAction(),
                ProductsTable::deleteAction()
                    ->successRedirectUrl(ProductResource::getUrl('index')),
            ])
                ->label('More')
                ->icon('heroicon-m-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Save changes'),
            $this->getCancelFormAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Product $product */
        $product = $this->getRecord();
        $data['simple_stock_quantity'] = (int) ($product->variations()->orderBy('id')->value('stock_quantity') ?? 0);
        $this->loadedSimpleStock = $data['simple_stock_quantity'];
        $data['specification_groups'] = ProductForm::specificationsToGroups($product->specifications);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['specifications'] = ProductForm::groupsToSpecifications($data['specification_groups'] ?? null);
        unset($data['specification_groups']);

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
            $this->loadedSimpleStock = $submittedStock;
        }

        $product->syncPrimaryImageFlag();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Changes saved';
    }
}
