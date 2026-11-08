<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Exceptions\ListingActivationRefusedException;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Monetization\MonetizationEngineService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'Add product';
    }

    public function getSubheading(): ?string
    {
        return 'Fields marked * are required. You can save a hidden draft and finish it later.';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Publish product'),

            $this->saveDraftAction(),

            $this->getCancelFormAction(),
        ];
    }

    public function saveDraftAction(): Action
    {
        return Action::make('saveDraft')
            ->label('Save as hidden draft')
            ->color('gray')
            ->action(function (): void {
                $this->data['is_active'] = false;
                $this->create();
            });
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $seller = auth()->user();
        $isActive = (bool) ($data['is_active'] ?? true);

        if ($isActive && $seller) {
            try {
                app(MonetizationEngineService::class)->assertCanActivate($seller);
            } catch (ListingActivationRefusedException $refusal) {
                Notification::make()
                    ->title('Product not published')
                    ->body($refusal->getMessage().' You can save it as a hidden draft instead.')
                    ->warning()
                    ->send();
                $this->halt();
            }
        }

        $data['seller_id'] = auth()->id();
        $data['specifications'] = ProductForm::groupsToSpecifications($data['specification_groups'] ?? null);
        unset($data['specification_groups']);

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        if (! $product->has_variations) {
            $product->ensureDefaultVariation()->update([
                'stock_quantity' => (int) ($this->data['simple_stock_quantity'] ?? 0),
            ]);
        }

        $product->syncPrimaryImageFlag();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->getRecord()->is_active ? 'Product published' : 'Draft saved. It stays hidden until you turn on “Visible on storefront”.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
