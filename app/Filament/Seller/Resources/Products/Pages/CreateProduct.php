<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Exceptions\ListingActivationRefusedException;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Services\Catalog\ProductApprovalService;
use App\Services\Monetization\MonetizationEngineService;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected static bool $canCreateAnother = false;

    protected bool $sentForReview = false;

    protected bool $reviewFailed = false;

    public function getTitle(): string
    {
        return 'Add product';
    }

    public function getSubheading(): ?string
    {
        return 'Fields marked * are required. New products appear in the shop after our team approves them.';
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction()
                ->label('Send for review'),

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

        if ($product->is_active) {
            $this->sendForReview($product);
        }
    }

    protected function sendForReview(Product $product): void
    {
        try {
            app(ProductApprovalService::class)->submitProduct($product, auth()->user());
            $this->sentForReview = true;
        } catch (DomainException|AuthorizationException $e) {
            $product->update(['is_active' => false]);
            $this->reviewFailed = true;
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        if ($this->sentForReview) {
            return Notification::make()
                ->success()
                ->title('Sent for review')
                ->body('Buyers will see it once an admin approves it.');
        }

        if ($this->reviewFailed) {
            return Notification::make()
                ->danger()
                ->title('Saved, but not sent for review')
                ->body('Couldn\'t send for review. The product is still hidden. Please try again from the product list.');
        }

        return parent::getCreatedNotification();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Draft saved. It stays hidden until you turn on “Visible on storefront”.';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
