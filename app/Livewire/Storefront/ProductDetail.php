<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Services\Cart\CartSessionService;
use Exception;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class ProductDetail extends Component
{
    public Product $product;

    // Retail purchase state
    public ?int $selectedVariationId = null;

    public int $retailQuantity = 1;

    // Wholesale bulk matrix quantities: [variation_id => quantity]
    public array $matrixQuantities = [];

    public string $feedbackMessage = '';

    public string $errorMessage = '';

    // RFQ (Request for Quotation) state
    public bool $showRfqModal = false;

    public string $rfqBuyerName = '';

    public string $rfqBuyerEmail = '';

    public string $rfqBuyerPhone = '';

    public string $rfqCompanyName = '';

    public int $rfqTargetQuantity = 100;

    public ?float $rfqTargetPrice = null;

    public string $rfqDestinationDistrict = 'Dhaka';

    public string $rfqSpecifications = '';

    public bool $rfqSuccess = false;

    public function mount(string $slug): void
    {
        $this->product = Product::with([
            'seller.sellerProfile',
            'category',
            'brand',
            'salesChannel',
            'unit',
            'images',
            'prices.salesChannel',
            'variations.attributeValues.attribute',
        ])->where('slug', $slug)->firstOrFail();

        // Initialize default selected variation for retail
        if ($this->product->variations->isNotEmpty()) {
            $this->selectedVariationId = $this->product->variations->first()->id;
        }

        // Initialize matrix quantities to 0
        foreach ($this->product->variations as $var) {
            $this->matrixQuantities[$var->id] = 0;
        }

        // Prefill RFQ fields if user is logged in
        $user = auth()->user();
        if ($user) {
            $this->rfqBuyerName = $user->name;
            $this->rfqBuyerEmail = $user->email;
            $this->rfqBuyerPhone = $user->phone ?? '';
            $this->rfqCompanyName = $user->business_name ?? '';
        }
    }

    public function addToCartRetail(): void
    {
        $this->resetMessages();
        $this->retailQuantity = max(1, (int) $this->retailQuantity);

        if ($this->product->variations->isNotEmpty()) {
            if (! $this->selectedVariationId) {
                $this->errorMessage = 'Please select a variation before adding to cart.';

                return;
            }

            $variation = $this->product->variations->firstWhere('id', $this->selectedVariationId);
            if (! $variation || ! $variation->is_active) {
                $this->errorMessage = 'Selected variation is currently unavailable.';

                return;
            }

            if ($this->retailQuantity > $variation->stock_quantity) {
                $this->errorMessage = "Requested quantity ({$this->retailQuantity}) exceeds available stock ({$variation->stock_quantity} units).";

                return;
            }
        }

        $cart = app(CartSessionService::class);

        try {
            $cart->addItem(
                productId: $this->product->id,
                variationId: $this->selectedVariationId,
                channelSlug: SalesChannel::RETAIL,
                quantity: $this->retailQuantity
            );

            $this->feedbackMessage = "Added {$this->retailQuantity} unit(s) to your cart.";
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function addBulkMatrixToCart(): void
    {
        $this->resetMessages();
        $cart = app(CartSessionService::class);

        $wholesalePrice = $this->product->prices
            ->first(fn ($p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && is_null($p->variation_id));

        $moq = $wholesalePrice?->min_quantity ?? 1;

        $totalUnits = array_sum(array_map('intval', $this->matrixQuantities));

        if ($totalUnits < $moq) {
            $this->errorMessage = "Wholesale bulk order requires at least {$moq} units. You currently selected {$totalUnits}.";

            return;
        }

        // Validate stock availability for each variation
        foreach ($this->matrixQuantities as $variationId => $qty) {
            $qty = (int) $qty;
            if ($qty < 0) {
                $this->errorMessage = 'Quantities must be positive numbers.';

                return;
            }

            if ($qty > 0) {
                $var = $this->product->variations->firstWhere('id', (int) $variationId);
                if ($var && $qty > $var->stock_quantity) {
                    $this->errorMessage = "Requested quantity ({$qty}) for SKU '{$var->sku}' exceeds available stock ({$var->stock_quantity} units).";

                    return;
                }
            }
        }

        try {
            $addedCount = 0;
            foreach ($this->matrixQuantities as $variationId => $qty) {
                $qty = (int) $qty;
                if ($qty > 0) {
                    $cart->addItem(
                        productId: $this->product->id,
                        variationId: (int) $variationId,
                        channelSlug: SalesChannel::WHOLESALE,
                        quantity: $qty
                    );
                    $addedCount += $qty;
                }
            }

            // Reset matrix inputs
            foreach ($this->product->variations as $var) {
                $this->matrixQuantities[$var->id] = 0;
            }

            $this->feedbackMessage = "Successfully added wholesale bulk lot ({$addedCount} units) to your cart!";
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function openRfqModal(): void
    {
        $this->showRfqModal = true;
        $this->rfqSuccess = false;
    }

    public function closeRfqModal(): void
    {
        $this->showRfqModal = false;
    }

    public function submitRfq(): void
    {
        $this->validate([
            'rfqBuyerName' => 'required|string|min:3|max:255',
            'rfqBuyerEmail' => 'required|email|max:255',
            'rfqBuyerPhone' => ['required', 'string', 'regex:/^(\+?8801|01)\d{7,9}$/'],
            'rfqCompanyName' => 'nullable|string|max:255',
            'rfqTargetQuantity' => 'required|integer|min:1',
            'rfqTargetPrice' => 'nullable|numeric|min:1',
            'rfqDestinationDistrict' => 'required|string|max:100',
            'rfqSpecifications' => 'nullable|string|max:2000',
        ], [
            'rfqBuyerPhone.regex' => 'Please enter a valid Bangladeshi phone number.',
            'rfqTargetQuantity.min' => 'Target quantity must be at least 1 unit.',
        ]);

        RfqRequest::create([
            'product_id' => $this->product->id,
            'seller_id' => $this->product->seller_id,
            'buyer_id' => auth()->id(),
            'buyer_name' => $this->rfqBuyerName,
            'buyer_email' => $this->rfqBuyerEmail,
            'buyer_phone' => $this->rfqBuyerPhone,
            'company_name' => $this->rfqCompanyName,
            'target_quantity' => $this->rfqTargetQuantity,
            'target_unit_price' => $this->rfqTargetPrice,
            'destination_district' => $this->rfqDestinationDistrict,
            'specifications' => $this->rfqSpecifications,
            'status' => 'pending',
        ]);

        $this->rfqSuccess = true;
    }

    protected function resetMessages(): void
    {
        $this->feedbackMessage = '';
        $this->errorMessage = '';
    }

    public function render()
    {
        $user = auth()->user();
        $isWholesaleUser = $user && $user->isWholesaleBuyer();

        $retailPrice = $this->product->prices
            ->first(fn ($p) => $p->salesChannel?->slug === SalesChannel::RETAIL && is_null($p->variation_id));

        $wholesalePrice = $this->product->prices
            ->first(fn ($p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && is_null($p->variation_id));

        return view('livewire.storefront.product-detail', [
            'isWholesaleUser' => $isWholesaleUser,
            'retailPrice' => $retailPrice,
            'wholesalePrice' => $wholesalePrice,
        ]);
    }
}
