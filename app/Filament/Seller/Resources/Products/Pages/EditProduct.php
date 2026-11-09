<?php

namespace App\Filament\Seller\Resources\Products\Pages;

use App\Enums\ProductApprovalStatus;
use App\Exceptions\ListingActivationRefusedException;
use App\Filament\Seller\Resources\Products\ProductResource;
use App\Filament\Seller\Resources\Products\Schemas\ProductForm;
use App\Filament\Seller\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use App\Services\Catalog\PriceSet;
use App\Services\Catalog\ProductApprovalService;
use App\Services\Monetization\MonetizationEngineService;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** Stock shown when the form was opened. */
    public ?int $loadedSimpleStock = null;

    /** True while saving a product that was approved when the save began; its prices then wait for review. */
    protected bool $holdingPriceChanges = false;

    /** @var array{rows: list<array<string, mixed>>, regular_price: mixed, sales_channel_id: int, category_id: int, note: ?string}|null */
    protected ?array $submittedPricing = null;

    /** Id of the rejected price change whose red callout the seller dismissed. */
    public ?int $dismissedRejectedChangeId = null;

    protected ?string $savedNotificationTitle = null;

    protected ?string $savedNotificationBody = null;

    public function isHoldingPriceChanges(): bool
    {
        return $this->holdingPriceChanges;
    }

    /**
     * Reloads the record and refills the form, so the callout and the pricing fields reflect a withdrawn request.
     */
    public function reloadAfterChangeDecision(): void
    {
        $record = $this->getRecord();
        $record->refresh();
        $record->unsetRelation('pendingChange');

        $this->fillForm();
    }

    public function getSubheading(): ?string
    {
        /** @var Product $product */
        $product = $this->getRecord();

        return match (true) {
            (bool) $product->blocked_at => 'Hidden by the marketplace',
            $product->approval_status === ProductApprovalStatus::PendingReview => 'Waiting for review'.($product->submitted_at ? ' since '.$product->submitted_at->timezone('Asia/Dhaka')->format('j M') : ''),
            $product->approval_status === ProductApprovalStatus::Rejected && filled($product->rejection_reason) => 'Not approved: '.$product->rejection_reason,
            $product->is_active => 'Live on your storefront · last saved '.$product->updated_at?->diffForHumans(),
            default => 'Hidden draft · last saved '.$product->updated_at?->diffForHumans(),
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductsTable::viewOnStorefrontAction()
                // Shown (but disabled) before approval so the seller sees why buyers can't reach the page yet
                ->visible(fn (Product $record): bool => $record->approval_status !== ProductApprovalStatus::Approved || ($record->is_active && ! $record->blocked_at))
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

        if ($this->holdingPriceChanges) {
            unset($data['regular_price'], $data['sales_channel_id'], $data['category_id'], $data['change_note']);
        }

        return $data;
    }

    /**
     * Filament writes relationships (the prices repeater) while it reads the form state, before
     * `mutateFormDataBeforeSave` runs and before it reloads the repeater from the database. So the decision to hold
     * and the submitted pricing must both be captured here, from the database and the raw form state.
     */
    protected function beforeSave(): void
    {
        $this->beginSave();
    }

    /**
     * Runs from `beforeSave`, before Filament writes the prices repeater, so a refused activation saves nothing.
     * Visibility is read from the database, not the loaded record, so a product hidden elsewhere meanwhile is guarded again.
     */
    protected function assertListingMayBecomeVisible(Product $product): void
    {
        if (empty($this->data['is_active']) || (bool) $product->fresh()?->is_active) {
            return;
        }

        try {
            app(MonetizationEngineService::class)->assertCanActivate(auth()->user());
        } catch (ListingActivationRefusedException $refusal) {
            Notification::make()
                ->title('Product not published')
                ->body($refusal->getMessage().' Nothing was saved; untick "Visible" to save your changes as a draft.')
                ->warning()
                ->persistent()
                ->send();
            $this->halt();
        }
    }

    protected function beginSave(): void
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $this->savedNotificationTitle = null;
        $this->savedNotificationBody = null;
        $this->submittedPricing = null;

        $this->assertListingMayBecomeVisible($product);

        // Decided from the database, never from form data: a stale page must not leak prices live
        $product->refresh();
        $this->holdingPriceChanges = $product->approval_status === ProductApprovalStatus::Approved;

        if ($this->holdingPriceChanges) {
            $this->submittedPricing = [
                'rows' => $this->submittedPriceRows(),
                'regular_price' => $this->data['regular_price'] ?? null,
                'sales_channel_id' => (int) ($this->data['sales_channel_id'] ?? $product->sales_channel_id),
                'category_id' => (int) ($this->data['category_id'] ?? $product->category_id),
                'note' => filled($this->data['change_note'] ?? null) ? (string) $this->data['change_note'] : null,
            ];
        }
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

        if ($this->holdingPriceChanges) {
            $this->proposeSubmittedPricing($product);
        } else {
            $this->syncReviewStatus($product);
        }
    }

    /**
     * The form's base price rows plus the live variation-specific rows, which the form cannot show and so are
     * carried over unchanged.
     *
     * @return list<array<string, mixed>>
     */
    protected function submittedPriceRows(): array
    {
        /** @var Product $product */
        $product = $this->getRecord();

        $formRows = collect(array_values($this->data['prices'] ?? []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(fn (array $row): array => [
                'sales_channel_id' => $row['sales_channel_id'] ?? null,
                'variation_id' => null,
                'unit_price' => $row['unit_price'] ?? null,
                'min_quantity' => $row['min_quantity'] ?? null,
                'max_quantity' => $row['max_quantity'] ?? null,
            ]);

        $variationRows = $product->prices()->whereNotNull('variation_id')->get()
            ->map(fn ($price): array => [
                'sales_channel_id' => $price->sales_channel_id,
                'variation_id' => $price->variation_id,
                'unit_price' => $price->unit_price,
                'min_quantity' => $price->min_quantity,
                'max_quantity' => $price->max_quantity,
            ]);

        return $formRows->concat($variationRows)->values()->all();
    }

    protected function proposeSubmittedPricing(Product $product): void
    {
        $pricing = $this->submittedPricing;
        $this->submittedPricing = null;

        if ($pricing === null) {
            return;
        }

        // An unrelated save re-submits the live prices the form was refilled with; that must not withdraw a pending
        // request. Withdrawing is the explicit Withdraw action only.
        $submitted = PriceSet::fromInput($pricing['rows'], $pricing['regular_price']);
        if ($submitted->equals(PriceSet::fromProduct($product))
            && $pricing['sales_channel_id'] === $product->sales_channel_id
            && $pricing['category_id'] === $product->category_id) {
            $this->fillForm();

            return;
        }

        try {
            $request = app(ProductApprovalService::class)->proposeChange(
                $product,
                auth()->user(),
                $submitted,
                $pricing['sales_channel_id'],
                $pricing['category_id'],
                $pricing['note'],
            );

            if ($request !== null) {
                $this->savedNotificationTitle = 'Changes saved. Your new prices are waiting for approval; buyers still pay the current prices.';
            }
        } catch (DomainException|AuthorizationException $e) {
            Notification::make()
                ->danger()
                ->title('Your price changes weren\'t sent')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }

        // Show what is live, not what was typed: the proposal waits for review
        $this->fillForm();
    }

    /**
     * An unapproved product goes to review when made visible, and is pulled back when hidden while it waits.
     */
    protected function syncReviewStatus(Product $product): void
    {
        $status = $product->approval_status;
        $service = app(ProductApprovalService::class);

        try {
            if ($product->is_active && in_array($status, [ProductApprovalStatus::Draft, ProductApprovalStatus::Rejected], true)) {
                $service->submitProduct($product, auth()->user());
                $this->savedNotificationBody = 'Sent for review. Buyers will see it once an admin approves it.';
            } elseif (! $product->is_active && $status === ProductApprovalStatus::PendingReview) {
                $service->withdrawProduct($product, auth()->user());
            }
        } catch (DomainException|AuthorizationException $e) {
            $product->update(['is_active' => false]);
            $this->savedNotificationTitle = 'Saved, but not sent for review';
            $this->savedNotificationBody = 'Couldn\'t send for review. The product is still hidden. Please try again from the product list.';
        }
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return $this->savedNotificationTitle ?? 'Changes saved';
    }

    protected function getSavedNotification(): ?Notification
    {
        return parent::getSavedNotification()?->body($this->savedNotificationBody);
    }
}
