<?php

namespace App\Livewire\Storefront;

use App\Models\Category;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class SellerStore extends Component
{
    use WithPagination;

    public SellerProfile $sellerProfile;

    public string $search = '';

    public ?int $selectedCategoryId = null;

    public string $channelFilter = 'all';

    public function mount(string $slug): void
    {
        $this->sellerProfile = SellerProfile::with('user')
            ->where('store_slug', $slug)
            ->where('is_approved', true)
            ->firstOrFail();
    }

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = ($this->selectedCategoryId === $categoryId) ? null : $categoryId;
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();
        $isWholesaleUser = $user && $user->isWholesaleBuyer();

        $sellerProductsQuery = $this->sellerProfile->user->products()
            ->active()
            ->with([
                'category',
                'brand',
                'salesChannel',
                'unit',
                'primaryImage',
                'prices.salesChannel',
                'variations',
            ]);

        // Distinct categories within this seller's catalog
        $categoryIds = (clone $sellerProductsQuery)->pluck('category_id')->unique()->filter();
        $storeCategories = Category::whereIn('id', $categoryIds)->get();

        if ($this->search) {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $sellerProductsQuery->where(function ($q) use ($like) {
                $q->where('title', $like, '%'.$this->search.'%')
                    ->orWhere('short_description', $like, '%'.$this->search.'%');
            });
        }

        if ($this->selectedCategoryId) {
            $sellerProductsQuery->where('category_id', $this->selectedCategoryId);
        }

        if ($this->channelFilter !== 'all') {
            $sellerProductsQuery->whereHas('salesChannel', function ($q) {
                $q->where('slug', $this->channelFilter)
                    ->orWhere('slug', SalesChannel::BOTH);
            });
        }

        $products = $sellerProductsQuery->paginate(12);

        return view('livewire.storefront.seller-store', [
            'products' => $products,
            'storeCategories' => $storeCategories,
            'isWholesaleUser' => $isWholesaleUser,
            'totalActiveProducts' => $this->sellerProfile->user->products()->active()->count(),
        ]);
    }
}
