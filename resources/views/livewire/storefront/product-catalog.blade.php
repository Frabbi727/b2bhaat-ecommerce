<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <!-- Hero / Scope Notification Banner -->
    <div class="p-6 sm:p-8 rounded-3xl bg-gradient-to-r from-slate-950 via-indigo-950 to-slate-900 text-white shadow-xl relative overflow-hidden border border-slate-800">
        <div class="relative z-10 max-w-3xl">
            @if ($isWholesaleUser)
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 mb-4">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Wholesale Business Account Active
                </span>
                <h1 class="text-2xl sm:text-4xl font-black tracking-tight">Factory-Direct Wholesale Sourcing</h1>
                <p class="mt-3 text-sm sm:text-base text-slate-300 leading-relaxed">
                    Bulk procurement mode active. Tier volume rates, Minimum Order Quantities (MOQs), and multi-variation matrix ordering are unlocked for your verified business profile.
                </p>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-indigo-500/20 text-indigo-300 border border-indigo-500/30 mb-4">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Retail & Consumer Market Mode
                </span>
                <h1 class="text-2xl sm:text-4xl font-black tracking-tight">Bangladesh's Hybrid B2B & Retail Marketplace</h1>
                <p class="mt-3 text-sm sm:text-base text-slate-300 leading-relaxed">
                    Purchase individual items with fast courier delivery across Bangladesh, or upgrade to a wholesale account to unlock direct manufacturer pricing in BDT (৳).
                </p>
                <div class="mt-6 flex flex-wrap gap-3">
                    <a href="{{ route('wholesale.upgrade') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-indigo-600 hover:bg-indigo-500 text-white shadow-sm transition">
                        <span>Apply for Wholesale Tier (KYC)</span>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </a>
                    <a href="{{ route('seller.register') }}" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition">
                        <span>List Your Factory or Imports</span>
                    </a>
                </div>
            @endif
        </div>
        <!-- Decorative Glow -->
        <div class="absolute -right-20 -bottom-20 w-80 h-80 bg-indigo-600/10 rounded-full blur-3xl pointer-events-none"></div>
    </div>

    <!-- Verified Factories & Suppliers Spotlight -->
    @if ($featuredSellers->isNotEmpty())
        <div class="bg-gradient-to-br from-slate-50 to-indigo-50/30 border border-slate-200 rounded-2xl p-5 shadow-xs">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-800">Verified Factories & Direct Importers</h2>
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">Vetted suppliers operating with valid Bangladeshi Trade Licenses & BIN certificates</p>
                </div>
                <a href="{{ route('seller.register') }}" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition flex items-center gap-1 self-start sm:self-auto">
                    <span>Register as Manufacturer</span>
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                @foreach ($featuredSellers as $sellerProfile)
                    <a href="{{ route('store.show', $sellerProfile->store_slug) }}" class="group bg-white rounded-xl p-4 border border-slate-200/80 shadow-xs hover:border-indigo-500 hover:shadow-md transition flex flex-col justify-between">
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-indigo-600 transition line-clamp-1">{{ $sellerProfile->store_name }}</h3>
                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0">
                                    <svg class="w-3 h-3 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Vetted
                                </span>
                            </div>
                            <div class="mt-2 text-[11px] text-slate-500 space-y-1">
                                <div class="flex items-center gap-1 font-mono text-[10px] text-slate-400">
                                    <span>Lic:</span>
                                    <span>{{ $sellerProfile->trade_license_number ?? 'Verified Partner' }}</span>
                                </div>
                                <div class="text-slate-600">
                                    <span>{{ $sellerProfile->products_count }} Live Product Catalog Item(s)</span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-t border-slate-100 flex items-center justify-between text-[11px]">
                            <span class="text-indigo-600 font-semibold group-hover:underline">Visit Store &rarr;</span>
                            <span class="text-slate-400">Dhaka Hub</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Industry Category Navigation Chips -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-600">Browse by Industry Sector</h2>
            @if ($selectedCategoryId)
                <button wire:click="selectCategory(null)" class="text-xs text-rose-600 font-semibold hover:underline">
                    Clear Category Filter (Show All)
                </button>
            @endif
        </div>
        <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
            <button 
                wire:click="selectCategory(null)" 
                class="px-4 py-2 rounded-xl text-xs font-bold shrink-0 transition {{ is_null($selectedCategoryId) ? 'bg-slate-900 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
            >
                All Industries
            </button>
            @foreach ($categories as $cat)
                <button 
                    wire:click="selectCategory({{ $cat->id }})" 
                    class="px-4 py-2 rounded-xl text-xs font-bold shrink-0 transition flex items-center gap-2 {{ $selectedCategoryId === $cat->id ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' }}"
                >
                    @if ($cat->icon_url)
                        <img src="{{ $cat->icon_url }}" alt="" class="w-4 h-4 rounded-full object-cover"/>
                    @endif
                    <span>{{ $cat->name }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <!-- Search & Channel Filters -->
    <div class="flex flex-col md:flex-row gap-4 justify-between items-stretch md:items-center pb-4 border-b border-slate-200">
        <div class="relative flex-1 max-w-md">
            <input 
                type="text" 
                wire:model.live.debounce.300ms="search" 
                placeholder="Search products, brands, or categories..." 
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
                            <a href="{{ route('store.show', $product->seller?->sellerProfile?->store_slug) }}" class="font-medium text-slate-700 hover:text-indigo-600 transition underline-offset-2 hover:underline">
                                {{ $product->seller?->sellerProfile?->store_name }}
                            </a>
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

                <!-- Pricing Block with Dynamic Role Masking -->
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
                        <!-- Retail User or Guest Presentation -->
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

                        <!-- Wholesale Teaser Banner -->
                        @if (in_array($product->salesChannel?->slug, ['wholesale', 'both']))
                            <div class="p-2 rounded-lg bg-indigo-50/80 border border-indigo-100 text-[11px] text-indigo-700 flex items-center justify-between">
                                <span>Wholesale rate available (Bulk MOQ)</span>
                                <a href="{{ route('wholesale.upgrade') }}" class="font-bold underline text-indigo-900">Unlock Tier</a>
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
            <div class="col-span-full py-12 text-center text-slate-500">
                No products found matching your search criteria.
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-8">
        {{ $products->links() }}
    </div>
</div>
