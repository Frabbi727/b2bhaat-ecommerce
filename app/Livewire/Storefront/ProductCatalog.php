<?php

namespace App\Livewire\Storefront;

use App\Models\Category;
use App\Models\Product;
use App\Models\SalesChannel;
use App\Models\SellerProfile;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.storefront')]
class ProductCatalog extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $selectedCategoryId = null;

    public string $channelFilter = 'all';

    public function selectCategory(?int $categoryId): void
    {
        $this->selectedCategoryId = ($this->selectedCategoryId === $categoryId) ? null : $categoryId;
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

    public function updatedChannelFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $categories = Category::where('is_active', true)->whereNull('parent_id')->with('children')->get();
        $featuredSellers = SellerProfile::where('is_approved', true)->with('user')->withCount('products')->take(4)->get();

        $user = auth()->user();
        $isWholesaleUser = $user && $user->isWholesaleBuyer();

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
                'variations',
            ]);

        if ($this->search) {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($q) use ($like) {
                $q->where('title', $like, '%'.$this->search.'%')
                    ->orWhere('short_description', $like, '%'.$this->search.'%');
            });
        }

        if ($this->selectedCategoryId) {
            $query->where(function ($q) {
                $q->where('category_id', $this->selectedCategoryId)
                    ->orWhereHas('category', fn ($c) => $c->where('parent_id', $this->selectedCategoryId));
            });
        }

        if ($this->channelFilter !== 'all') {
            $query->whereHas('salesChannel', function ($q) {
                $q->where('slug', $this->channelFilter)
                    ->orWhere('slug', SalesChannel::BOTH);
            });
        }

        $products = $query->paginate(12);

        return view('livewire.storefront.product-catalog', [
            'products' => $products,
            'categories' => $categories,
            'featuredSellers' => $featuredSellers,
            'isWholesaleUser' => $isWholesaleUser,
        ]);
    }
}
