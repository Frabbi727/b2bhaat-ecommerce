<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <!-- Breadcrumb -->
    <nav class="flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('catalog.index') }}" class="hover:underline">Marketplace Catalog</a>
        <span>/</span>
        <span class="text-slate-400">Verified Factories & Suppliers</span>
        <span>/</span>
        <span class="text-slate-800 font-semibold">{{ $sellerProfile->store_name }}</span>
    </nav>

    <!-- Factory Profile Header Card -->
    <div class="rounded-3xl bg-gradient-to-r from-slate-950 via-slate-900 to-indigo-950 text-white p-6 sm:p-10 border border-slate-800 shadow-xl relative overflow-hidden">
        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="space-y-3 max-w-2xl">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                        <svg class="w-3.5 h-3.5 text-emerald-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                        Govt. Trade Verified Supplier
                    </span>
                    <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30">
                        Direct Factory Hub
                    </span>
                </div>

                <h1 class="text-2xl sm:text-4xl font-black tracking-tight text-white">
                    {{ $sellerProfile->store_name }}
                </h1>

                <p class="text-xs sm:text-sm text-slate-300 leading-relaxed">
                    Verified supplier and manufacturer operating in Bangladesh. Directly fulfilling factory wholesale bulk orders and consumer retail packages under the B2B Haat escrow protocol.
                </p>

                <!-- Credentials Badges -->
                <div class="flex flex-wrap items-center gap-4 pt-2 text-xs text-slate-300">
                    @if ($sellerProfile->trade_license_number)
                        <div class="flex items-center gap-1.5 font-mono bg-slate-800/80 px-2.5 py-1 rounded-lg border border-slate-700">
                            <span class="text-slate-400">Trade License:</span>
                            <strong class="text-slate-100">{{ $sellerProfile->trade_license_number }}</strong>
                        </div>
                    @endif
                    @if ($sellerProfile->tax_id_number)
                        <div class="flex items-center gap-1.5 font-mono bg-slate-800/80 px-2.5 py-1 rounded-lg border border-slate-700">
                            <span class="text-slate-400">TIN:</span>
                            <strong class="text-slate-100">{{ $sellerProfile->tax_id_number }}</strong>
                        </div>
                    @endif
                    <div class="flex items-center gap-1.5">
                        <span class="text-slate-400">Authorized:</span>
                        <span class="text-slate-200">{{ $sellerProfile->user?->name }}</span>
                    </div>
                </div>
            </div>

            <!-- Stats Column -->
            <div class="grid grid-cols-2 gap-3 sm:gap-4 shrink-0 bg-white/5 p-4 rounded-2xl border border-white/10 text-center">
                <div class="p-3">
                    <div class="text-2xl sm:text-3xl font-black text-emerald-400 font-mono">{{ $totalActiveProducts }}</div>
                    <div class="text-[11px] text-slate-400 mt-1 uppercase font-semibold">Active Catalog Items</div>
                </div>
                <div class="p-3 border-l border-white/10">
                    <div class="text-2xl sm:text-3xl font-black text-indigo-400 font-mono">100%</div>
                    <div class="text-[11px] text-slate-400 mt-1 uppercase font-semibold">Verified Escrow</div>
                </div>
            </div>
        </div>

        <div class="absolute -right-16 -top-16 w-64 h-64 bg-indigo-500/10 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <!-- Wholesale Buyer Incentive Banner -->
    @if (! $isWholesaleUser)
        <div class="p-4 rounded-2xl bg-indigo-50 border border-indigo-200 text-xs text-indigo-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Sourcing in bulk for your salon or boutique from <strong>{{ $sellerProfile->store_name }}</strong>? Apply for B2B wholesale access to unlock tier volume pricing and matrix ordering.</span>
            </div>
            <a href="{{ route('wholesale.upgrade') }}" class="font-bold underline text-indigo-950 shrink-0 hover:text-indigo-700">
                Apply for Wholesale Verification &rarr;
            </a>
        </div>
    @endif

    <!-- Category Pills within this Store -->
    @if ($storeCategories->isNotEmpty())
        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600">Categories from this Supplier</h2>
                @if ($selectedCategoryId)
                    <button wire:click="selectCategory(null)" class="text-xs text-rose-600 font-semibold hover:underline">
                        Show All Categories
                    </button>
                @endif
            </div>
            <div class="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
                <button 
                    wire:click="selectCategory(null)" 
                    class="px-3.5 py-1.5 rounded-xl text-xs font-bold shrink-0 transition {{ is_null($selectedCategoryId) ? 'bg-slate-900 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
                >
                    All Categories ({{ $totalActiveProducts }})
                </button>
                @foreach ($storeCategories as $cat)
                    <button 
                        wire:click="selectCategory({{ $cat->id }})" 
                        class="px-3.5 py-1.5 rounded-xl text-xs font-bold shrink-0 transition {{ $selectedCategoryId === $cat->id ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
                    >
                        {{ $cat->name }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Search & Channel Filters -->
    <div class="flex flex-col md:flex-row gap-4 justify-between items-stretch md:items-center pb-4 border-b border-slate-200">
        <div class="relative flex-1 max-w-md">
            <input 
                type="text" 
                wire:model.live.debounce.300ms="search" 
                placeholder="Search products in {{ $sellerProfile->store_name }}..." 
                class="w-full pl-10 pr-4 py-2 text-sm bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 shadow-xs"
            />
            <svg class="w-4 h-4 text-slate-400 absolute left-3.5 top-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-slate-500">Channel View:</span>
            <button 
                wire:click="$set('channelFilter', 'all')" 
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $channelFilter === 'all' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
            >
                All Products
            </button>
            <button 
                wire:click="$set('channelFilter', 'retail')" 
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $channelFilter === 'retail' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
            >
                Retail Single Units
            </button>
            <button 
                wire:click="$set('channelFilter', 'wholesale')" 
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition {{ $channelFilter === 'wholesale' ? 'bg-indigo-600 text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
            >
                Wholesale Bulk Lots
            </button>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @forelse ($products as $product)
            @php
                $retailPrice = $product->prices->first(fn($p) => $p->salesChannel?->slug === 'retail' && is_null($p->variation_id));
                $wholesalePrice = $product->prices->first(fn($p) => $p->salesChannel?->slug === 'wholesale' && is_null($p->variation_id));
                $primaryImg = $product->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
            @endphp
            <div class="group bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-xs hover:shadow-lg transition-all flex flex-col justify-between">
                <div>
                    <!-- Image & Badge Container -->
                    <div class="relative aspect-4/3 bg-slate-100 overflow-hidden">
                        <img 
                            src="{{ $primaryImg }}" 
                            alt="{{ $product->title }}" 
                            class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                        />

                        <!-- Channel Badge -->
                        <div class="absolute top-3 left-3 flex flex-col gap-1">
                            @if ($product->salesChannel?->slug === 'both')
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-indigo-600 text-white shadow-xs">
                                    Dual Channel (B2B + B2C)
                                </span>
                            @elseif ($product->salesChannel?->slug === 'wholesale')
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-emerald-600 text-white shadow-xs">
                                    Wholesale Only
                                </span>
                            @else
                                <span class="px-2 py-0.5 rounded-md text-[10px] font-bold uppercase tracking-wide bg-slate-800 text-white shadow-xs">
                                    Retail Only
                                </span>
                            @endif
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="p-4 space-y-2">
                        <div class="flex items-center justify-between text-xs text-slate-500">
                            <span>{{ $product->category?->name }}</span>
                            <span class="font-mono text-[10px] text-slate-400">{{ $product->brand?->name ?? 'Factory Direct' }}</span>
                        </div>

                        <h3 class="font-bold text-slate-900 group-hover:text-indigo-600 transition line-clamp-1">
                            <a href="{{ route('catalog.show', $product->slug) }}">
                                {{ $product->title }}
                            </a>
                        </h3>

                        <p class="text-xs text-slate-500 line-clamp-2">
                            {{ $product->short_description }}
                        </p>
                    </div>
                </div>

                <!-- Pricing Block -->
                <div class="p-4 pt-0 border-t border-slate-100 mt-2 space-y-3">
                    @if ($isWholesaleUser)
                        @if ($wholesalePrice)
                            <div class="flex items-baseline justify-between">
                                <div>
                                    <span class="text-xs text-emerald-600 font-semibold uppercase">Wholesale Price</span>
                                    <div class="text-lg font-black text-slate-900">
                                        ৳{{ number_format($wholesalePrice->unit_price, 2) }}
                                        <span class="text-xs font-normal text-slate-500">/ {{ $product->unit?->symbol }}</span>
                                    </div>
                                </div>
                                <span class="text-xs px-2 py-1 rounded bg-emerald-50 text-emerald-700 font-bold border border-emerald-200">
                                    MOQ: {{ $wholesalePrice->min_quantity }} {{ $product->unit?->symbol }}
                                </span>
                            </div>
                        @endif
                        @if ($retailPrice)
                            <div class="text-[11px] text-slate-400">
                                Retail MSRP: ৳{{ number_format($retailPrice->unit_price, 2) }}
                            </div>
                        @endif
                    @else
                        @if ($retailPrice)
                            <div class="flex items-baseline justify-between">
                                <div>
                                    <span class="text-xs text-slate-500 font-semibold">Retail Price</span>
                                    <div class="text-lg font-black text-slate-900">
                                        ৳{{ number_format($retailPrice->unit_price, 2) }}
                                        <span class="text-xs font-normal text-slate-500">/ {{ $product->unit?->symbol }}</span>
                                    </div>
                                </div>
                                <span class="text-xs text-slate-400">Min: 1 pc</span>
                            </div>
                        @endif

                        @if (in_array($product->salesChannel?->slug, ['wholesale', 'both']))
                            <div class="p-2 rounded-lg bg-indigo-50/80 border border-indigo-100 text-[11px] text-indigo-700 flex items-center justify-between">
                                <span>Bulk wholesale pricing available</span>
                                <a href="{{ route('wholesale.upgrade') }}" class="font-bold underline text-indigo-900">Unlock</a>
                            </div>
                        @endif
                    @endif

                    <a 
                        href="{{ route('catalog.show', $product->slug) }}" 
                        class="block w-full text-center py-2 px-3 rounded-xl text-xs font-bold transition shadow-xs {{ $isWholesaleUser ? 'bg-emerald-600 hover:bg-emerald-700 text-white' : 'bg-indigo-600 hover:bg-indigo-700 text-white' }}"
                    >
                        {{ $isWholesaleUser ? 'Open B2B Bulk Order Matrix' : 'View Product & Buy' }}
                    </a>
                </div>
            </div>
        @empty
            <div class="col-span-full py-12 text-center text-slate-500 bg-white rounded-2xl border border-slate-200 p-8">
                No products found matching your search criteria in this supplier's store.
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-8">
        {{ $products->links() }}
    </div>
</div>
