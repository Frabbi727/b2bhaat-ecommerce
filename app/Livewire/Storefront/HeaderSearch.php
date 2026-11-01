<?php

declare(strict_types=1);

namespace App\Livewire\Storefront;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class HeaderSearch extends Component
{
    public string $query = '';

    public ?int $selectedCategory = null;

    public bool $isOpen = false;

    /**
     * @var array<int, string>
     */
    public array $popularSearches = [
        'Haier Mini LED',
        'Sony WH-1000XM5',
        'Galaxy S24 Ultra',
        '4K UHD Google TV',
        'Press-on Nails',
    ];

    public function updatedQuery(): void
    {
        $this->isOpen = strlen(trim($this->query)) >= 2;
    }

    public function selectPopular(string $term): void
    {
        $this->query = $term;
        $this->submitSearch();
    }

    public function submitSearch(): mixed
    {
        $params = [];
        if (! empty(trim($this->query))) {
            $params['search'] = trim($this->query);
        }
        if ($this->selectedCategory) {
            $params['category'] = $this->selectedCategory;
        }

        $this->isOpen = false;

        return $this->redirect(route('catalog.index', $params));
    }

    public function closeDropdown(): void
    {
        $this->isOpen = false;
    }

    public function render()
    {
        $results = new Collection;
        $categoryResults = new Collection;

        $searchTerm = trim($this->query);
        if (strlen($searchTerm) >= 2) {
            $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $results = Product::query()
                ->active()
                ->with(['primaryImage', 'brand', 'retailPrice'])
                ->where(function ($q) use ($like, $searchTerm) {
                    $q->where('title', $like, "%{$searchTerm}%")
                        ->orWhere('sku', $like, "%{$searchTerm}%")
                        ->orWhere('model_number', $like, "%{$searchTerm}%")
                        ->orWhere('short_description', $like, "%{$searchTerm}%");
                })
                ->when($this->selectedCategory, function ($q) {
                    $q->where('category_id', $this->selectedCategory);
                })
                ->take(6)
                ->get();

            $categoryResults = Category::where('is_active', true)
                ->where('name', $like, "%{$searchTerm}%")
                ->take(3)
                ->get();
        }

        $allCategories = Category::where('is_active', true)->whereNull('parent_id')->orderBy('sort_order')->get();

        return view('livewire.storefront.header-search', [
            'results' => $results,
            'categoryResults' => $categoryResults,
            'allCategories' => $allCategories,
        ]);
    }
}
