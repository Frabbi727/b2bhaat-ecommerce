<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\Banner;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use App\Models\Wishlist;
use App\Services\Cart\CartSessionService;
use Exception;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class ProductCatalog extends Component
{
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'category')]
    public ?int $selectedCategoryId = null;

    #[Url(as: 'brand')]
    public ?int $selectedBrandId = null;

    #[Url(as: 'channel')]
    public string $channelFilter = 'all';

    #[Url(as: 'sort')]
    public string $sort = 'newest';

    #[Url(as: 'min_price')]
    public ?float $minPrice = null;

    #[Url(as: 'max_price')]
    public ?float $maxPrice = null;

    #[Url(as: 'flash_sale')]
    public bool $onlyFlashSale = false;

    public string $feedbackMessage = '';

    public string $errorMessage = '';

    public bool $showQuickView = false;

    public ?Product $quickViewProduct = null;

    public ?int $quickViewVariationId = null;

    public int $quickViewQuantity = 1;

    public string $quickViewActiveImageUrl = '';

    public function mount(): void
    {
        // Bind incoming request parameters if present
        if (request()->has('category')) {
            $this->selectedCategoryId = (int) request('category');
        }
        if (request()->has('search')) {
            $this->search = (string) request('search');
        }
    }

    public function openQuickView(int $productId): void
    {
        $this->resetMessages();
        $this->quickViewProduct = Product::with([
            'images',
            'category',
            'brand',
            'seller.sellerProfile',
            'retailPrice',
            'wholesalePrice',
            'prices',
            'variations.attributeValues.attribute',
        ])->find($productId);

        if (! $this->quickViewProduct) {
            $this->errorMessage = 'Product not found.';

            return;
        }

        $this->quickViewActiveImageUrl = $this->quickViewProduct->primaryImage?->image_url
            ?? $this->quickViewProduct->images->first()?->image_url
            ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=800';

        $firstVariation = $this->quickViewProduct->variations->where('is_active', true)->first()
            ?? $this->quickViewProduct->variations->first();
        $this->quickViewVariationId = $firstVariation?->id;
        $this->quickViewQuantity = 1;
        $this->showQuickView = true;
    }

    public function closeQuickView(): void
    {
        $this->showQuickView = false;
        $this->quickViewProduct = null;
        $this->quickViewVariationId = null;
        $this->quickViewQuantity = 1;
        $this->quickViewActiveImageUrl = '';
    }

    public function selectQuickViewImage(string $url): void
    {
        $this->quickViewActiveImageUrl = $url;
    }

    public function selectQuickViewVariation(int $variationId): void
    {
        $this->quickViewVariationId = $variationId;
    }

    public function updateQuickViewQuantity(int $amount): void
    {
        $newQty = $this->quickViewQuantity + $amount;
        if ($newQty >= 1) {
            $this->quickViewQuantity = $newQty;
        }
    }

    public function quickViewAddToCart(CartSessionService $cart): void
    {
        if (! $this->quickViewProduct) {
            return;
        }

        if ($this->quickViewProduct->seller?->sellerProfile?->is_on_vacation) {
            $this->errorMessage = 'This merchant is on vacation mode and cannot accept orders right now.';

            return;
        }

        try {
            $cart->addItem(
                productId: $this->quickViewProduct->id,
                variationId: $this->quickViewVariationId,
                channelSlug: SalesChannel::RETAIL,
                quantity: $this->quickViewQuantity
            );

            $this->feedbackMessage = "Added {$this->quickViewQuantity}x '{$this->quickViewProduct->title}' to your cart.";
            $this->dispatch('cartUpdated');
            $this->closeQuickView();
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function quickViewBuyNow(CartSessionService $cart): void
    {
        if (! $this->quickViewProduct) {
            return;
        }

        if ($this->quickViewProduct->seller?->sellerProfile?->is_on_vacation) {
            $this->errorMessage = 'This merchant is on vacation mode and cannot accept orders right now.';

            return;
        }

        try {
            $cart->addItem(
                productId: $this->quickViewProduct->id,
                variationId: $this->quickViewVariationId,
                channelSlug: SalesChannel::RETAIL,
                quantity: $this->quickViewQuantity
            );

            $this->dispatch('cartUpdated');
            $this->redirect(route('checkout.index'));
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = ($this->selectedCategoryId === $categoryId) ? null : $categoryId;
        $this->resetPage();
    }

    public function selectBrand(?int $brandId): void
    {
        $this->selectedBrandId = ($this->selectedBrandId === $brandId) ? null : $brandId;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedMinPrice(): void
    {
        $this->resetPage();
    }

    public function updatedMaxPrice(): void
    {
        $this->resetPage();
    }

    public function updatedOnlyFlashSale(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->selectedCategoryId = null;
        $this->selectedBrandId = null;
        $this->channelFilter = 'all';
        $this->sort = 'newest';
        $this->minPrice = null;
        $this->maxPrice = null;
        $this->onlyFlashSale = false;
        $this->resetPage();
    }

    public function toggleWishlist(int $productId): void
    {
        if (! auth()->check()) {
            $this->redirect(route('demo.login', 'retail'));

            return;
        }

        $userId = auth()->id();
        $existing = Wishlist::where('user_id', $userId)->where('product_id', $productId)->first();

        if ($existing) {
            $existing->delete();
            $this->feedbackMessage = 'Product removed from your Wishlist.';
        } else {
            Wishlist::create([
                'user_id' => $userId,
                'product_id' => $productId,
            ]);
            $this->feedbackMessage = 'Product saved to your Wishlist!';
        }
    }

    public function quickAddToCart(int $productId): void
    {
        $this->resetMessages();

        $product = Product::with(['retailPrice', 'variations', 'seller.sellerProfile'])->find($productId);
        if (! $product) {
            $this->errorMessage = 'Product not found.';

            return;
        }

        if ($product->seller?->sellerProfile?->is_on_vacation) {
            $this->errorMessage = 'This merchant is on vacation mode and cannot accept orders right now.';

            return;
        }

        // If product has variations, redirect to PDP for exact specification selection
        if ($product->has_variations && $product->variations->count() > 1) {
            $this->redirect(route('catalog.show', $product->slug));

            return;
        }

        $variationId = $product->variations->first()?->id;

        $cart = app(CartSessionService::class);
        try {
            $cart->addItem(
                productId: $product->id,
                variationId: $variationId,
                channelSlug: SalesChannel::RETAIL,
                quantity: 1
            );

            $this->feedbackMessage = "Added '{$product->title}' to your cart.";
            $this->dispatch('cartUpdated');
        } catch (Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function resetMessages(): void
    {
        $this->feedbackMessage = '';
        $this->errorMessage = '';
    }

    public function render()
    {
        $categories = Category::where('is_active', true)->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();
        $brands = Brand::where('is_active', true)->orderBy('name')->get();
        $featuredSellers = SellerProfile::where('is_approved', true)->with('user')->withCount('products')->take(4)->get();
        $heroBanners = Banner::active()->forPosition('hero_slider')->get();
        $promoStripBanners = Banner::active()->forPosition('promo_strip')->get();

        // Flash Sales Section Products
        $flashSaleProducts = Product::query()
            ->active()
            ->flashSale()
            ->with(['seller.sellerProfile', 'category', 'brand', 'primaryImage', 'retailPrice'])
            ->take(6)
            ->get();

        // Featured Products Section
        $featuredProducts = Product::query()
            ->active()
            ->featured()
            ->with(['seller.sellerProfile', 'category', 'brand', 'primaryImage', 'retailPrice'])
            ->take(8)
            ->get();

        $user = auth()->user();
        $isWholesaleUser = $user && $user->isWholesaleBuyer();
        $userWishlistIds = $user ? $user->wishlists()->pluck('product_id')->toArray() : [];

        // Main Query with filters & sorting
        $query = Product::query()
            ->active()
            ->with([
                'seller.sellerProfile',
                'category',
                'brand',
                'salesChannel',
                'unit',
                'primaryImage',
                'prices.salesChannel',
                'retailPrice',
                'variations',
            ]);

        if ($this->search) {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($like) {
                $q->where('title', $like, '%'.$this->search.'%')
                    ->orWhere('sku', $like, '%'.$this->search.'%')
                    ->orWhere('model_number', $like, '%'.$this->search.'%')
                    ->orWhere('short_description', $like, '%'.$this->search.'%');
            });
        }

        if ($this->selectedCategoryId) {
            $query->where(function ($q) {
                $q->where('category_id', $this->selectedCategoryId)
                    ->orWhereHas('category', fn ($c) => $c->where('parent_id', $this->selectedCategoryId));
            });
        }

        if ($this->selectedBrandId) {
            $query->where('brand_id', $this->selectedBrandId);
        }

        if ($this->onlyFlashSale) {
            $query->flashSale();
        }

        if ($this->channelFilter !== 'all') {
            $query->whereHas('salesChannel', function ($q) {
                $q->where('slug', $this->channelFilter)
                    ->orWhere('slug', SalesChannel::BOTH);
            });
        }

        if (! is_null($this->minPrice)) {
            $query->where(function ($q) {
                $q->where('regular_price', '>=', $this->minPrice)
                    ->orWhereHas('prices', fn ($p) => $p->where('unit_price', '>=', $this->minPrice));
            });
        }

        if (! is_null($this->maxPrice)) {
            $query->where(function ($q) {
                $q->where('regular_price', '<=', $this->maxPrice)
                    ->orWhereHas('prices', fn ($p) => $p->where('unit_price', '<=', $this->maxPrice));
            });
        }

        // Sorting
        switch ($this->sort) {
            case 'price_asc':
                $query->orderBy('regular_price', 'asc')->orderBy('id', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('regular_price', 'desc')->orderBy('id', 'desc');
                break;
            case 'popularity':
                $query->orderBy('views_count', 'desc')->orderBy('sales_count', 'desc');
                break;
            case 'discount':
                $query->orderByRaw('(CASE WHEN regular_price > 0 AND flash_sale_price > 0 THEN ((regular_price - flash_sale_price) / regular_price) ELSE 0 END) DESC');
                break;
            case 'newest':
            default:
                $query->latest();
                break;
        }

        $products = $query->paginate(12);

        // Determine if customer is filtering/searching or viewing standard homepage
        $isFiltering = ! empty($this->search)
            || ! is_null($this->selectedCategoryId)
            || ! is_null($this->selectedBrandId)
            || $this->channelFilter !== 'all'
            || ! is_null($this->minPrice)
            || ! is_null($this->maxPrice)
            || $this->onlyFlashSale;

        return view('livewire.storefront.product-catalog', [
            'products' => $products,
            'categories' => $categories,
            'brands' => $brands,
            'featuredSellers' => $featuredSellers,
            'isWholesaleUser' => $isWholesaleUser,
            'heroBanners' => $heroBanners,
            'promoStripBanners' => $promoStripBanners,
            'flashSaleProducts' => $flashSaleProducts,
            'featuredProducts' => $featuredProducts,
            'userWishlistIds' => $userWishlistIds,
            'isFiltering' => $isFiltering,
        ]);
    }
}
