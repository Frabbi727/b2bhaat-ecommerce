<?php

namespace App\Livewire\Storefront;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductDailyView;
use App\Models\ProductReview;
use App\Models\RfqRequest;
use App\Models\SalesChannel;
use App\Models\Wishlist;
use App\Services\Cart\CartSessionService;
use App\Services\Promotions\FlashSalePricing;
use App\Services\Rfq\RfqNotificationService;
use App\Services\Settings\StoreConfigurationService;
use Exception;
use Illuminate\Database\Eloquent\Builder;
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

    // Customer Reviews state
    public int $reviewRating = 5;

    public string $reviewTitle = '';

    public string $reviewComment = '';

    public string $reviewFilterRating = 'all';

    public string $reviewSuccessMessage = '';

    public string $reviewErrorMessage = '';

    public bool $isInWishlist = false;

    // Gallery & UI State
    public int $activeImageIndex = 0;

    public string $activeTab = 'specifications';

    public bool $showFullscreenGallery = false;

    /**
     * PERF-3: what the page shows for a product. Livewire does not bring nested relations back between requests,
     * so they are loaded again on every update instead of one query per price or variation.
     */
    private const PRODUCT_RELATIONS = [
        'seller.sellerProfile',
        'category',
        'brand',
        'salesChannel',
        'unit',
        'images',
        'prices.salesChannel',
        'variations.attributeValues.attribute',
    ];

    public function hydrate(): void
    {
        $this->product->loadMissing(self::PRODUCT_RELATIONS);
    }

    public function mount(string $slug): void
    {
        $this->product = Product::with(self::PRODUCT_RELATIONS)->sellable()->where('slug', $slug)->firstOrFail();

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
            $this->isInWishlist = $user->wishlists()->where('product_id', $this->product->id)->exists();
        }

        // Track recently viewed products in session
        $recent = session()->get('recently_viewed_products', []);
        $recent = array_diff($recent, [$this->product->id]);
        array_unshift($recent, $this->product->id);
        session()->put('recently_viewed_products', array_slice($recent, 0, 6));

        // Increment view count
        $this->product->increment('views_count');
        ProductDailyView::record($this->product->id);
    }

    public function selectImage(int $index): void
    {
        $this->activeImageIndex = $index;
    }

    public function toggleFullscreenGallery(): void
    {
        $this->showFullscreenGallery = ! $this->showFullscreenGallery;
    }

    public function buyNowRetail(): mixed
    {
        $this->addToCartRetail();

        if (empty($this->errorMessage)) {
            return $this->redirect(route('checkout.index'));
        }

        return null;
    }

    public function addBundleToCart(int $secondaryProductId): void
    {
        $this->resetMessages();

        $secondary = Product::with(['retailPrice', 'variations', 'seller.sellerProfile'])->sellable()->find($secondaryProductId);
        if (! $secondary) {
            $this->errorMessage = 'Bundle product not found.';
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

            return;
        }

        // Add main product
        $this->addToCartRetail();
        if (! empty($this->errorMessage)) {
            return;
        }

        // Add secondary bundle product
        $cart = app(CartSessionService::class);
        $varId = $secondary->variations->first()?->id;

        try {
            $cart->addItem(
                productId: $secondary->id,
                variationId: $varId,
                channelSlug: SalesChannel::RETAIL,
                quantity: 1
            );

            $this->feedbackMessage = "Added bundle lot ({$this->product->title} + {$secondary->title}) to your cart!";
            $this->dispatch('toast', message: $this->feedbackMessage, tone: 'success', cart: true);
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);
        }
    }

    public function getIsSellerOnVacationProperty(): bool
    {
        return (bool) $this->product->seller?->sellerProfile?->is_on_vacation;
    }

    public function addToCartRetail(): void
    {
        $this->resetMessages();

        if ($this->isSellerOnVacation) {
            $this->errorMessage = 'This merchant is currently on vacation mode. Ordering is temporarily disabled.';
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

            return;
        }

        $this->retailQuantity = max(1, (int) $this->retailQuantity);

        if ($this->product->variations->isNotEmpty()) {
            if (! $this->selectedVariationId) {
                $this->errorMessage = 'Please select a variation before adding to cart.';
                $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

                return;
            }

            $variation = $this->product->variations->firstWhere('id', $this->selectedVariationId);
            if (! $variation || ! $variation->is_active) {
                $this->errorMessage = 'Selected variation is currently unavailable.';
                $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

                return;
            }

            $allowBackorders = app(StoreConfigurationService::class)->isBackorderAllowed();
            if (! $allowBackorders && $this->retailQuantity > $variation->stock_quantity) {
                $this->errorMessage = "Requested quantity ({$this->retailQuantity}) exceeds available stock ({$variation->stock_quantity} units).";
                $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

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
            $this->dispatch('toast', message: $this->feedbackMessage, tone: 'success', cart: true);
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);
        }
    }

    public function addBulkMatrixToCart(): void
    {
        $this->resetMessages();

        $user = auth()->user();
        if (! $user || ! $user->canSeeWholesalePrices()) {
            $this->errorMessage = 'Wholesale volume tier sourcing is reserved for verified wholesale business accounts. Please upgrade your account.';
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

            return;
        }

        if ($this->isSellerOnVacation) {
            $this->errorMessage = 'This merchant is currently on vacation mode. Ordering is temporarily disabled.';
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

            return;
        }

        $cart = app(CartSessionService::class);

        // The cart's rule: the lowest wholesale tier, all options together (UX-7)
        $moq = $cart->wholesaleMinimumQuantity($this->product->id) ?? 1;

        $totalUnits = array_sum(array_map('intval', $this->matrixQuantities));

        if ($totalUnits < $moq) {
            $this->errorMessage = "Wholesale bulk order requires at least {$moq} units. You currently selected {$totalUnits}.";
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

            return;
        }

        // Validate stock availability for each variation
        foreach ($this->matrixQuantities as $variationId => $qty) {
            $qty = (int) $qty;
            if ($qty < 0) {
                $this->errorMessage = 'Quantities must be positive numbers.';
                $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

                return;
            }

            if ($qty > 0) {
                $allowBackorders = app(StoreConfigurationService::class)->isBackorderAllowed();
                $var = $this->product->variations->firstWhere('id', (int) $variationId);
                if (! $allowBackorders && $var && $qty > $var->stock_quantity) {
                    $this->errorMessage = "Requested quantity ({$qty}) for SKU '{$var->sku}' exceeds available stock ({$var->stock_quantity} units).";
                    $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);

                    return;
                }
            }
        }

        try {
            // MOQ and tier price are resolved on the whole lot by the cart (D7, PRA-025)
            $cart->addWholesaleLot($this->product->id, $this->matrixQuantities);
            $addedCount = $totalUnits;

            // Reset matrix inputs
            foreach ($this->product->variations as $var) {
                $this->matrixQuantities[$var->id] = 0;
            }

            $this->feedbackMessage = "Successfully added wholesale bulk lot ({$addedCount} units) to your cart!";
            $this->dispatch('toast', message: $this->feedbackMessage, tone: 'success', cart: true);
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
            $this->dispatch('toast', message: $this->errorMessage, tone: 'error', cart: false);
        }
    }

    public function toggleWishlist(): void
    {
        if (! auth()->check()) {
            session()->flash('error', 'Please sign in to save products to your wishlist.');
            $this->redirect(route('login'));

            return;
        }

        $userId = auth()->id();
        $existing = Wishlist::where('user_id', $userId)
            ->where('product_id', $this->product->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $this->isInWishlist = false;
            $this->feedbackMessage = 'Product removed from your Wishlist.';
            $this->dispatch('toast', message: $this->feedbackMessage, tone: 'success', cart: false);
        } else {
            Wishlist::create([
                'user_id' => $userId,
                'product_id' => $this->product->id,
            ]);
            $this->isInWishlist = true;
            $this->feedbackMessage = 'Product added to your Wishlist!';
            $this->dispatch('toast', message: $this->feedbackMessage, tone: 'success', cart: false);
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

        $rfq = RfqRequest::create([
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

        app(RfqNotificationService::class)->notifySellerOfNewInquiry($rfq);

        $this->rfqSuccess = true;
    }

    public function setFilterRating(string $filter): void
    {
        $this->reviewFilterRating = $filter;
    }

    public function submitReview(): void
    {
        $this->reviewSuccessMessage = '';
        $this->reviewErrorMessage = '';

        if (! auth()->check()) {
            $this->reviewErrorMessage = 'You must be logged in to submit a review.';

            return;
        }

        $this->validate([
            'reviewRating' => 'required|integer|min:1|max:5',
            'reviewTitle' => 'nullable|string|max:150',
            'reviewComment' => 'required|string|min:5|max:2000',
        ], [
            'reviewRating.min' => 'Please select at least 1 star.',
            'reviewRating.max' => 'Maximum rating is 5 stars.',
            'reviewComment.min' => 'Please provide at least 5 characters in your feedback.',
        ]);

        $userId = auth()->id();

        // Check if verified purchase
        $hasPurchased = OrderItem::where('product_id', $this->product->id)
            ->whereHas('order', fn ($q) => $q->where('customer_id', $userId))
            ->exists();

        ProductReview::updateOrCreate(
            [
                'product_id' => $this->product->id,
                'user_id' => $userId,
            ],
            [
                'rating' => $this->reviewRating,
                'title' => $this->reviewTitle,
                'comment' => $this->reviewComment,
                'is_verified_purchase' => $hasPurchased,
                'is_approved' => true,
            ]
        );

        $this->reviewTitle = '';
        $this->reviewComment = '';
        $this->reviewRating = 5;
        $this->reviewSuccessMessage = $hasPurchased
            ? 'Thank you! Your verified purchase review has been published.'
            : 'Thank you! Your customer review has been published.';
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
        $canSeeWholesalePrices = $user?->canSeeWholesalePrices() ?? false;

        $retailPrice = $this->product->prices
            ->first(fn ($p) => $p->salesChannel?->slug === SalesChannel::RETAIL && is_null($p->variation_id));

        $wholesalePrice = $this->product->prices
            ->first(fn ($p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && is_null($p->variation_id));

        $wholesaleTiers = $this->product->prices
            ->filter(fn ($p) => $p->salesChannel?->slug === SalesChannel::WHOLESALE && is_null($p->variation_id))
            ->sortBy('min_quantity')
            ->values();

        $wholesaleMinimum = app(CartSessionService::class)->wholesaleMinimumQuantity($this->product->id);

        // Reviews calculations
        $approvedReviewsQuery = $this->product->reviews()->where('is_approved', true);
        $totalReviews = (clone $approvedReviewsQuery)->count();
        $averageRating = $totalReviews > 0 ? round((float) (clone $approvedReviewsQuery)->avg('rating'), 1) : 0;

        $ratingDistribution = [];
        if ($totalReviews > 0) {
            $counts = (clone $approvedReviewsQuery)
                ->selectRaw('rating, count(*) as count')
                ->groupBy('rating')
                ->pluck('count', 'rating');

            foreach ([5, 4, 3, 2, 1] as $star) {
                $count = (int) $counts->get($star, 0);
                $ratingDistribution[$star] = [
                    'count' => $count,
                    'percentage' => round(($count / $totalReviews) * 100),
                ];
            }
        } else {
            foreach ([5, 4, 3, 2, 1] as $star) {
                $ratingDistribution[$star] = ['count' => 0, 'percentage' => 0];
            }
        }

        // Filtered reviews list
        $reviewsListQuery = (clone $approvedReviewsQuery)
            ->with(['user', 'product.seller.sellerProfile'])
            ->latest('id');

        if (in_array($this->reviewFilterRating, ['1', '2', '3', '4', '5'])) {
            $reviewsListQuery->where('rating', (int) $this->reviewFilterRating);
        } elseif ($this->reviewFilterRating === 'verified') {
            $reviewsListQuery->where('is_verified_purchase', true);
        }

        $reviews = $reviewsListQuery->get();

        $userHasPurchased = auth()->check()
            ? OrderItem::where('product_id', $this->product->id)
                ->whereHas('order', fn ($q) => $q->where('customer_id', auth()->id()))
                ->exists()
            : false;

        $existingUserReview = auth()->check()
            ? $this->product->reviews()->where('user_id', auth()->id())->first()
            : null;

        // Recommendations & Discovery (Pickaboo reference standard)
        $relatedProducts = Product::query()
            ->listable()
            ->where('id', '!=', $this->product->id)
            ->where(function (Builder $q) {
                $q->where('category_id', $this->product->category_id)
                    ->orWhere('brand_id', $this->product->brand_id);
            })
            ->with(['primaryImage', 'brand', 'retailPrice', 'variations', 'seller.sellerProfile', 'salesChannel'])
            ->take(4)
            ->get();

        $frequentlyBoughtTogether = Product::query()
            ->listable()
            ->where('id', '!=', $this->product->id)
            ->where('category_id', $this->product->category_id)
            ->with(['primaryImage', 'brand', 'retailPrice'])
            ->take(2)
            ->get();

        $recentIds = array_diff(session()->get('recently_viewed_products', []), [$this->product->id]);
        $recentlyViewed = ! empty($recentIds)
            ? Product::query()->listable()->whereIn('id', $recentIds)->with(['primaryImage', 'brand', 'retailPrice'])->take(4)->get()
            : collect();

        app(FlashSalePricing::class)->primeFor([$this->product, ...$relatedProducts, ...$frequentlyBoughtTogether, ...$recentlyViewed]);

        return view('livewire.storefront.product-detail', [
            'isWholesaleUser' => $isWholesaleUser,
            'isWholesalePending' => (bool) $user?->isWholesalePending(),
            'canSeeWholesalePrices' => $canSeeWholesalePrices,
            'retailPrice' => $retailPrice,
            'wholesalePrice' => $wholesalePrice,
            'wholesaleTiers' => $wholesaleTiers,
            'wholesaleMinimum' => $wholesaleMinimum,
            'totalReviews' => $totalReviews,
            'averageRating' => $averageRating,
            'ratingDistribution' => $ratingDistribution,
            'reviews' => $reviews,
            'userHasPurchased' => $userHasPurchased,
            'existingUserReview' => $existingUserReview,
            'relatedProducts' => $relatedProducts,
            'frequentlyBoughtTogether' => $frequentlyBoughtTogether,
            'recentlyViewed' => $recentlyViewed,
        ]);
    }
}
