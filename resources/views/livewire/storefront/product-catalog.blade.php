<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-10">
    <!-- Feedback Alerts -->
    {{-- Shown to the shopper as a toast (see the storefront layout); kept here as text for screen readers and tests. --}}
    @if ($feedbackMessage)
        <p class="sr-only">{{ $feedbackMessage }}</p>
    @endif

    {{-- Shown to the shopper as a toast (see the storefront layout); kept here as text for screen readers and tests. --}}
    @if ($errorMessage)
        <p class="sr-only">{{ $errorMessage }}</p>
    @endif

    @if (!$isFiltering)
        <!-- ================= HOMEPAGE HERO BANNER SLIDER ================= -->
        @if ($heroBanners->isNotEmpty())
            <div
                x-data="{
                    active: 0,
                    touchX: 0,
                    total: {{ $heroBanners->count() }},
                    timer: null,
                    start() {
                        this.timer = setInterval(() => { this.next() }, 5000);
                    },
                    stop() {
                        clearInterval(this.timer);
                    },
                    next() {
                        this.active = (this.active + 1) % this.total;
                    },
                    prev() {
                        this.active = (this.active - 1 + this.total) % this.total;
                    }
                }"
                x-init="start()"
                @mouseenter="stop()"
                @mouseleave="start()"
                @touchstart.passive="touchX = $event.touches[0].clientX; stop()"
                @touchend="const dx = $event.changedTouches[0].clientX - touchX; if (Math.abs(dx) > 40) { dx < 0 ? next() : prev() } start()"
                class="relative rounded-3xl overflow-hidden shadow-xl border border-slate-200/80 bg-slate-900 aspect-4/3 sm:aspect-21/9"
            >
                @foreach ($heroBanners as $index => $banner)
                    <div
                        x-show="active === {{ $index }}"
                        x-transition:enter="transition ease-out duration-500"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-300"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute inset-0 w-full h-full"
                    >
                        <!-- Banner Image -->
                        <picture class="block w-full h-full">
                        @if ($banner->mobile_image_src)
                            <source media="(max-width: 639px)" srcset="{{ $banner->mobile_image_src }}">
                        @endif
                        <img
                            src="{{ $banner->desktop_image_src }}"
                            alt="{{ $banner->title }}"
                            class="w-full h-full object-cover object-center brightness-90"
                            loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                        />
                        </picture>

                        <!-- Gradient Overlay with Content -->
                        <div class="absolute inset-0 bg-gradient-to-t sm:bg-gradient-to-r from-slate-950/90 via-slate-900/60 to-transparent flex items-end sm:items-center">
                            <div class="max-w-2xl px-5 sm:px-12 pt-8 pb-10 sm:py-8 text-white space-y-2 sm:space-y-3">
                                @if ($banner->badge_text)
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-400 text-slate-950 shadow-sm">
                                        {{ $banner->badge_text }}
                                    </span>
                                @endif
                                <h2 class="text-xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-tight line-clamp-2">
                                    {{ $banner->title }}
                                </h2>
                                @if ($banner->subtitle)
                                    <p class="text-xs sm:text-base text-slate-200 line-clamp-2 max-w-lg">
                                        {{ $banner->subtitle }}
                                    </p>
                                @endif
                                <div class="pt-2">
                                    <a
                                        href="{{ $banner->link_url }}"
                                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs sm:text-sm tracking-wide shadow-lg transition transform hover:-translate-y-0.5"
                                    >
                                        <span>{{ $banner->button_text ?? 'Shop Now' }}</span>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                <!-- Prev & Next Arrow Controls -->
                <button
                    @click="prev()"
                    type="button"
                    class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/60 hover:bg-slate-900 text-white hidden sm:flex items-center justify-center backdrop-blur-sm transition z-20"
                    aria-label="Previous Slide"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button
                    @click="next()"
                    type="button"
                    class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/60 hover:bg-slate-900 text-white hidden sm:flex items-center justify-center backdrop-blur-sm transition z-20"
                    aria-label="Next Slide"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </button>

                <!-- Carousel Navigation Dots -->
                <div class="absolute bottom-3 sm:bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2 z-20">
                    @foreach ($heroBanners as $index => $banner)
                        <button
                            @click="active = {{ $index }}"
                            type="button"
                            class="h-2 rounded-full transition-all"
                            :class="active === {{ $index }} ? 'w-8 bg-amber-400' : 'w-2 bg-white/50 hover:bg-white'"
                            aria-label="Go to slide {{ $index + 1 }}"
                        ></button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Wholesale status strip --}}
        <div class="{{ $heroBanners->isNotEmpty() ? '-mt-4 sm:-mt-6' : '' }} flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-3 rounded-2xl bg-white border border-slate-200 text-xs sm:text-sm">
            @if ($isWholesaleUser)
                <span class="font-bold text-emerald-800">Wholesale prices are on for your account.</span>
                <span class="text-slate-600">Buy more to pay less per piece.</span>
            @elseif ($isWholesalePending)
                <span class="font-bold text-slate-800">Your wholesale application is under review.</span>
                <span class="text-slate-600">Until it is approved you see shop prices. Wholesale prices appear here as soon as it is.</span>
            @else
                <span class="text-slate-700"><strong class="text-slate-900">Buying for a business?</strong> Get lower prices when you buy in bulk.</span>
                <a href="{{ route('wholesale.upgrade') }}" class="font-bold text-indigo-600 hover:text-indigo-800 hover:underline shrink-0">
                    Apply for wholesale prices &rarr;
                </a>
            @endif
        </div>

        <!-- ================= CATEGORY SHOWCASE ================= -->
        @if ($categories->isNotEmpty())
            <section aria-labelledby="home-categories-heading">
                <div class="flex items-end justify-between gap-4 mb-4">
                    <div>
                        <h2 id="home-categories-heading" class="text-lg sm:text-xl font-extrabold tracking-tight text-slate-900">Browse by Industry Sector</h2>
                        <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Explore authentic electronics, gadgets, appliances & wholesale goods</p>
                    </div>
                    <a href="#all-products" class="shrink-0 text-xs sm:text-sm font-bold text-indigo-600 hover:text-indigo-800 transition">See all products &rarr;</a>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-3">
                    @foreach ($categories->take(6) as $cat)
                        <button
                            type="button"
                            wire:click="selectCategory({{ $cat->id }})"
                            class="group flex items-center gap-3 p-3 rounded-2xl bg-white border border-slate-200 text-left transition hover:border-indigo-400 hover:shadow-md hover:shadow-slate-900/5 cursor-pointer focus-visible:outline-2 focus-visible:outline-indigo-500"
                        >
                            <span class="grid place-items-center w-12 h-12 sm:w-14 sm:h-14 shrink-0 rounded-xl bg-indigo-50 overflow-hidden">
                                @if ($cat->icon_url)
                                    <img src="{{ $cat->icon_url }}" alt="" class="w-full h-full object-cover transition group-hover:scale-105"/>
                                @else
                                    <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                @endif
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold leading-snug text-slate-900 group-hover:text-indigo-600 line-clamp-2">{{ $cat->name }}</span>
                                <span class="block mt-0.5 text-[11px] font-medium text-slate-500">
                                    {{ $cat->children->isNotEmpty() ? $cat->children->count().' '.str('sub-category')->plural($cat->children->count()) : 'Shop now' }}
                                </span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endif

        <!-- ================= FLASH SALE SECTION WITH LIVE COUNTDOWN ================= -->
        @if ($flashSaleProducts->isNotEmpty() && isset($activeFlashSale) && $activeFlashSale)
            <section
                id="flash-sales"
                aria-labelledby="home-flash-heading"
                class="scroll-mt-28 rounded-3xl bg-gradient-to-br from-rose-950 via-slate-900 to-indigo-950 p-4 sm:p-6 lg:p-8 text-white shadow-xl shadow-slate-900/10 lg:grid lg:grid-cols-[18rem_1fr] lg:gap-8"
            >
                <div class="flex flex-col gap-4 mb-5 lg:mb-0">
                    <div class="flex items-start gap-3 lg:flex-col">
                        <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-rose-600 text-white shadow-md shadow-rose-950/40">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M13 2L4.5 13.5H11L10 22l8.5-11.5H12L13 2z"/></svg>
                        </span>
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 id="home-flash-heading" class="text-xl sm:text-2xl font-black tracking-tight text-white text-balance">{{ $activeFlashSale->name }}</h2>
                                <span class="px-2 py-0.5 rounded-full bg-rose-500/25 border border-rose-400/40 text-rose-200 text-[10px] font-bold uppercase tracking-wider">Live now</span>
                            </div>
                            <p class="mt-1 text-xs sm:text-sm text-rose-100/80">{{ $activeFlashSale->description ?: 'Massive limited-time price drops on flagship tech & essentials' }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <x-storefront.flash-countdown :ends-at="$activeFlashSale->ends_at" label="Ends in:" />
                        @if (isset($liveCampaignsCount) && $liveCampaignsCount > 1)
                            <a href="{{ route('flash-sales.index') }}" class="text-xs font-bold text-rose-200 hover:text-white underline underline-offset-4">
                                See all {{ $liveCampaignsCount }} flash sales &rarr;
                            </a>
                        @endif
                    </div>
                </div>

                <div class="grid grid-cols-2 {{ $flashSaleProducts->count() >= 3 ? 'xl:grid-cols-3' : '' }} gap-3 sm:gap-4">
                    @foreach ($flashSaleProducts as $product)
                        @php
                            $offer = $product->liveFlashOffer();
                            $stockLimit = $offer?->stock_limit;
                            $remainingUnits = $stockLimit !== null ? max(0, $stockLimit - $offer->unitsSold()) : null;
                        @endphp
                        <x-storefront.product-card
                            :product="$product"
                            :wishlisted="in_array($product->id, $userWishlistIds)"
                            :flash="true"
                            :remaining-units="$remainingUnits"
                            wire:key="flash-{{ $product->id }}"
                            class="border-transparent shadow-md shadow-slate-950/30"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        <!-- ================= FEATURED PRODUCTS SECTION ================= -->
        @if ($featuredProducts->isNotEmpty())
            <section aria-labelledby="home-featured-heading">
                <div class="flex items-end justify-between gap-4 mb-4">
                    <div>
                        <h2 id="home-featured-heading" class="text-lg sm:text-xl font-extrabold tracking-tight text-slate-900">Featured Products</h2>
                        <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Curated top-selling electronics, lifestyle & consumer favorites</p>
                    </div>
                    <a href="#all-products" class="shrink-0 text-xs sm:text-sm font-bold text-indigo-600 hover:text-indigo-800 transition">See all products &rarr;</a>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
                    @foreach ($featuredProducts as $item)
                        <x-storefront.product-card
                            :product="$item"
                            :wishlisted="in_array($item->id, $userWishlistIds)"
                            wire:key="featured-{{ $item->id }}"
                        />
                    @endforeach
                </div>
            </section>
        @endif

        <!-- ================= PROMOTIONAL STRIP BANNERS ================= -->
        @if ($promoStripBanners->isNotEmpty())
            <div class="grid grid-cols-1 md:grid-cols-{{ min(2, $promoStripBanners->count()) }} gap-4">
                @foreach ($promoStripBanners as $banner)
                    <a href="{{ $banner->link_url }}" class="group relative rounded-2xl overflow-hidden block border border-slate-200 shadow-xs hover:shadow-md transition">
                        <img src="{{ $banner->desktop_image_src }}" alt="{{ $banner->title }}" class="w-full h-36 sm:h-44 object-cover group-hover:scale-102 transition duration-500">
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/80 via-slate-950/20 to-transparent flex flex-col justify-end p-4 text-white">
                            @if ($banner->badge_text)
                                <span class="inline-block text-[10px] font-extrabold uppercase px-2 py-0.5 rounded bg-rose-600 text-white w-max mb-1">
                                    {{ $banner->badge_text }}
                                </span>
                            @endif
                            <h3 class="text-sm sm:text-base font-extrabold">{{ $banner->title }}</h3>
                            @if ($banner->subtitle)
                                <p class="text-xs text-slate-200 line-clamp-1">{{ $banner->subtitle }}</p>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        @endif

        <!-- ================= VERIFIED FACTORIES & DIRECT IMPORTERS ================= -->
        @if ($featuredSellers->isNotEmpty())
            <section aria-labelledby="home-factories-heading">
                <div class="flex items-end justify-between gap-4 mb-4">
                    <div>
                        <h2 id="home-factories-heading" class="text-lg sm:text-xl font-extrabold tracking-tight text-slate-900">Verified Factories & Direct Importers</h2>
                        <p class="mt-0.5 text-xs sm:text-sm text-slate-500">Vetted suppliers operating with verified Bangladeshi Trade Licenses & BIN certificates</p>
                    </div>
                    <a href="{{ route('seller.register') }}" class="shrink-0 text-xs sm:text-sm font-bold text-indigo-600 hover:text-indigo-800 transition">Register as Manufacturer &rarr;</a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-[repeat(auto-fit,minmax(15rem,1fr))] gap-3">
                    @foreach ($featuredSellers as $sellerProfile)
                        <a href="{{ route('store.show', $sellerProfile->store_slug) }}" class="group flex items-center gap-3 p-4 rounded-2xl bg-white border border-slate-200 transition hover:border-indigo-400 hover:shadow-md hover:shadow-slate-900/5 focus-visible:outline-2 focus-visible:outline-indigo-500">
                            <span class="grid place-items-center w-11 h-11 shrink-0 rounded-xl bg-slate-900 text-white text-base font-black uppercase" aria-hidden="true">
                                {{ mb_substr($sellerProfile->store_name, 0, 1) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-1.5 min-w-0">
                                    <span class="text-sm font-bold text-slate-900 group-hover:text-indigo-600 truncate">{{ $sellerProfile->store_name }}</span>
                                    <svg class="w-4 h-4 shrink-0 text-emerald-600" fill="currentColor" viewBox="0 0 20 20" role="img" aria-label="Verified"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                </span>
                                <span class="block mt-0.5 text-xs text-slate-500">
                                    {{ $sellerProfile->products_count }} {{ str('product')->plural($sellerProfile->products_count) }}
                                    @if ($sellerProfile->trade_license_number)
                                        <span class="text-slate-300" aria-hidden="true">·</span>
                                        <span class="tabular-nums">Lic. {{ $sellerProfile->trade_license_number }}</span>
                                    @endif
                                </span>
                            </span>
                            <svg class="w-4 h-4 shrink-0 text-slate-300 group-hover:text-indigo-600 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

    <!-- ================= FULL CATALOG & FILTERING EXPLORER ================= -->
    @php
        $activeFilterCount = (int) ! is_null($selectedCategoryId)
            + (int) ! is_null($selectedBrandId)
            + (int) ($channelFilter !== 'all')
            + (int) (! is_null($minPrice) || ! is_null($maxPrice))
            + (int) $onlyFlashSale
            + (int) $inStockOnly
            + (int) $verifiedSupplierOnly;

        $activeCat = $selectedCategoryId
            ? ($categories->firstWhere('id', $selectedCategoryId) ?? $categories->flatMap->children->firstWhere('id', $selectedCategoryId))
            : null;
        $activeCatParent = $activeCat && $activeCat->parent_id ? $categories->firstWhere('id', $activeCat->parent_id) : null;
        $subcategoryRail = $activeCatParent?->children ?? $activeCat?->children ?? collect();
        $activeBrand = $selectedBrandId ? $brands->firstWhere('id', $selectedBrandId) : null;
    @endphp

    <section
        id="all-products"
        aria-labelledby="all-products-heading"
        class="scroll-mt-28"
        x-data="{ filtersOpen: false, catQuery: '', brandQuery: '' }"
        @keydown.escape.window="filtersOpen = false"
    >
        <!-- Heading & toolbar -->
        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3 mb-4">
            <div class="min-w-0">
                <h2 id="all-products-heading" class="text-lg sm:text-xl font-extrabold tracking-tight text-slate-900">
                    @if ($search)
                        Results for &ldquo;{{ $search }}&rdquo;
                    @elseif ($activeCat)
                        {{ $activeCat->name }}
                    @else
                        {{ $isFiltering ? 'Search results' : 'All products' }}
                    @endif
                </h2>
                <p class="mt-0.5 text-xs sm:text-sm text-slate-500">
                    <span class="tabular-nums font-semibold text-slate-700">{{ number_format($products->total()) }}</span>
                    {{ str('product')->plural($products->total()) }} available
                </p>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="filtersOpen = true"
                    class="inline-flex items-center gap-2 h-10 px-3.5 rounded-xl border border-slate-200 bg-white text-sm font-semibold text-slate-800 hover:border-slate-300 hover:bg-slate-50 transition cursor-pointer focus-visible:outline-2 focus-visible:outline-indigo-500"
                    aria-haspopup="dialog"
                    :aria-expanded="filtersOpen"
                >
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M7 12h10M10 18h4"/></svg>
                    <span>Filters</span>
                    @if ($activeFilterCount > 0)
                        <span class="grid place-items-center min-w-5 h-5 px-1 rounded-full bg-indigo-600 text-white text-[11px] font-bold tabular-nums">{{ $activeFilterCount }}</span>
                    @endif
                </button>

                <label for="catalog-sort" class="sr-only">Sort products</label>
                <select
                    id="catalog-sort"
                    wire:model.live="sort"
                    class="h-10 pl-3 pr-9 text-sm font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl hover:border-slate-300 focus:ring-indigo-500 focus:border-indigo-500 cursor-pointer"
                >
                    <option value="newest">Newest first</option>
                    <option value="popularity">Most popular</option>
                    <option value="price_asc">Price: low to high</option>
                    <option value="price_desc">Price: high to low</option>
                    <option value="discount">Biggest discount</option>
                </select>
            </div>
        </div>

        <!-- Sub-categories of the chosen category: one tap to narrow down -->
        @if ($subcategoryRail->isNotEmpty())
            <div class="-mx-4 px-4 sm:mx-0 sm:px-0 mb-4 flex gap-2 overflow-x-auto no-scrollbar" role="list" aria-label="Sub-categories">
                @php $railParent = $activeCatParent ?? $activeCat; @endphp
                <button
                    type="button"
                    role="listitem"
                    wire:click="$set('selectedCategoryId', {{ $railParent->id }})"
                    class="shrink-0 h-9 px-3.5 rounded-full text-xs font-semibold border transition cursor-pointer {{ $selectedCategoryId === $railParent->id ? 'bg-slate-900 border-slate-900 text-white' : 'bg-white border-slate-200 text-slate-700 hover:border-slate-400' }}"
                >
                    All {{ $railParent->name }}
                </button>
                @foreach ($subcategoryRail as $sub)
                    <button
                        type="button"
                        role="listitem"
                        wire:click="$set('selectedCategoryId', {{ $sub->id }})"
                        @if ($selectedCategoryId === $sub->id) x-init="$el.parentElement.scrollLeft = $el.offsetLeft - $el.parentElement.offsetLeft - 16" @endif
                        class="shrink-0 h-9 px-3.5 rounded-full text-xs font-semibold border transition cursor-pointer {{ $selectedCategoryId === $sub->id ? 'bg-slate-900 border-slate-900 text-white' : 'bg-white border-slate-200 text-slate-700 hover:border-slate-400' }}"
                    >
                        {{ $sub->name }}
                    </button>
                @endforeach
            </div>
        @endif

        <!-- Active filters: each one removable on its own -->
        @if ($isFiltering)
            <div class="mb-5 flex flex-wrap items-center gap-2 text-xs">
                @if ($search)
                    <x-storefront.filter-chip wire:click="$set('search', '')">Search: {{ $search }}</x-storefront.filter-chip>
                @endif
                @if ($activeCat)
                    <x-storefront.filter-chip wire:click="$set('selectedCategoryId', null)">{{ $activeCat->name }}</x-storefront.filter-chip>
                @endif
                @if ($activeBrand)
                    <x-storefront.filter-chip wire:click="$set('selectedBrandId', null)">{{ $activeBrand->name }}</x-storefront.filter-chip>
                @endif
                @if ($channelFilter !== 'all')
                    <x-storefront.filter-chip wire:click="$set('channelFilter', 'all')">{{ $channelFilter === 'wholesale' ? 'Wholesale' : 'Retail' }}</x-storefront.filter-chip>
                @endif
                @if (! is_null($minPrice) || ! is_null($maxPrice))
                    <x-storefront.filter-chip wire:click="$set('minPrice', null); $set('maxPrice', null)">৳{{ number_format((float) ($minPrice ?? 0)) }} – {{ $maxPrice ? '৳'.number_format((float) $maxPrice) : 'any' }}</x-storefront.filter-chip>
                @endif
                @if ($onlyFlashSale)
                    <x-storefront.filter-chip wire:click="$set('onlyFlashSale', false)">Flash Sale</x-storefront.filter-chip>
                @endif
                @if ($inStockOnly)
                    <x-storefront.filter-chip wire:click="$set('inStockOnly', false)">In Stock Only</x-storefront.filter-chip>
                @endif
                @if ($verifiedSupplierOnly)
                    <x-storefront.filter-chip wire:click="$set('verifiedSupplierOnly', false)">Verified Suppliers</x-storefront.filter-chip>
                @endif
                <button type="button" wire:click="resetFilters" class="h-8 px-2 font-semibold text-slate-500 hover:text-rose-600 underline underline-offset-4 cursor-pointer">
                    Clear all
                </button>
            </div>
        @endif

        <!-- Product grid -->
        @if ($products->isEmpty())
            <div class="py-16 px-6 text-center rounded-3xl bg-white border border-slate-200">
                <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 grid place-items-center mx-auto">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                </div>
                <h3 class="mt-4 text-base font-bold text-slate-900">No products match your criteria</h3>
                <p class="mt-1 text-sm text-slate-500 max-w-sm mx-auto">Try a shorter search, a wider price range, or remove a filter.</p>
                <button
                    type="button"
                    wire:click="resetFilters"
                    class="mt-5 h-10 px-5 rounded-xl bg-slate-900 hover:bg-indigo-600 text-white font-bold text-sm transition cursor-pointer"
                >
                    Reset All Filters
                </button>
            </div>
        @else
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4" wire:loading.class="opacity-60" wire:target="sort,selectedCategoryId,selectedBrandId,channelFilter,minPrice,maxPrice,onlyFlashSale,inStockOnly,verifiedSupplierOnly,selectCategory,selectBrand,resetFilters,search,gotoPage,nextPage,previousPage">
                @foreach ($products as $item)
                    <x-storefront.product-card
                        :product="$item"
                        :wishlisted="in_array($item->id, $userWishlistIds)"
                        wire:key="catalog-{{ $item->id }}"
                    />
                @endforeach
            </div>

            <div class="mt-8">
                {{ $products->links() }}
            </div>
        @endif

        <!-- ================= FILTER PANEL (drawer on desktop, bottom sheet on phones) ================= -->
        <div x-show="filtersOpen" x-cloak class="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-labelledby="filters-heading">
            <div
                x-show="filtersOpen"
                x-transition.opacity.duration.200ms
                @click="filtersOpen = false"
                class="absolute inset-0 bg-slate-950/40"
            ></div>

            <div
                x-show="filtersOpen"
                x-trap.noscroll="filtersOpen"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="translate-y-full sm:translate-y-0 sm:translate-x-full"
                x-transition:enter-end="translate-y-0 sm:translate-x-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="translate-y-0 sm:translate-x-0"
                x-transition:leave-end="translate-y-full sm:translate-y-0 sm:translate-x-full"
                class="absolute inset-x-0 bottom-0 max-h-[88dvh] rounded-t-3xl sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-full sm:max-w-sm sm:rounded-none bg-white shadow-2xl flex flex-col"
            >
                <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100">
                    <h2 id="filters-heading" class="text-base font-extrabold text-slate-900">Filters</h2>
                    <button type="button" @click="filtersOpen = false" class="grid place-items-center w-9 h-9 rounded-xl text-slate-500 hover:bg-slate-100 hover:text-slate-900 cursor-pointer" aria-label="Close filters">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto overscroll-contain px-5 py-5 space-y-7">
                    <!-- Category (searchable) -->
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-900">Category</legend>
                        <label for="filter-category-search" class="sr-only">Find a category</label>
                        <div class="relative mt-2.5">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                            <input id="filter-category-search" type="search" x-model="catQuery" placeholder="Find a category" autocomplete="off" class="w-full h-10 pl-9 pr-3 text-sm rounded-xl border border-slate-200 placeholder:text-slate-400 focus:ring-indigo-500 focus:border-indigo-500"/>
                        </div>
                        <ul class="mt-2 max-h-56 overflow-y-auto -mx-1 px-1 space-y-0.5 text-sm">
                            <li x-show="!catQuery">
                                <button type="button" wire:click="$set('selectedCategoryId', null)" class="w-full flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-left transition cursor-pointer {{ is_null($selectedCategoryId) ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-700 hover:bg-slate-50' }}">
                                    All Categories
                                </button>
                            </li>
                            @foreach ($categories as $cat)
                                @foreach (collect([$cat])->concat($cat->children) as $option)
                                    @php $isChild = $option->parent_id !== null; @endphp
                                    <li x-show="!catQuery || @js(mb_strtolower($option->name)).includes(catQuery.toLowerCase().trim())">
                                        <button
                                            type="button"
                                            wire:click="$set('selectedCategoryId', {{ $option->id }})"
                                            class="w-full flex items-center justify-between gap-2 py-2 pr-3 rounded-lg text-left transition cursor-pointer {{ $isChild ? 'pl-7 text-[13px]' : 'pl-3' }} {{ $selectedCategoryId === $option->id ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-700 hover:bg-slate-50' }}"
                                        >
                                            <span class="truncate">{{ $option->name }}</span>
                                            @if ($selectedCategoryId === $option->id)
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    </fieldset>

                    <!-- Brand (searchable) -->
                    @if ($brands->isNotEmpty())
                        <fieldset>
                            <legend class="text-sm font-bold text-slate-900">Brand</legend>
                            <label for="filter-brand-search" class="sr-only">Find a brand</label>
                            <div class="relative mt-2.5">
                                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                                <input id="filter-brand-search" type="search" x-model="brandQuery" placeholder="Find a brand" autocomplete="off" class="w-full h-10 pl-9 pr-3 text-sm rounded-xl border border-slate-200 placeholder:text-slate-400 focus:ring-indigo-500 focus:border-indigo-500"/>
                            </div>
                            <ul class="mt-2 max-h-48 overflow-y-auto -mx-1 px-1 space-y-0.5 text-sm">
                                @foreach ($brands as $b)
                                    <li x-show="!brandQuery || @js(mb_strtolower($b->name)).includes(brandQuery.toLowerCase().trim())">
                                        <button
                                            type="button"
                                            wire:click="selectBrand({{ $b->id }})"
                                            class="w-full flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-left transition cursor-pointer {{ $selectedBrandId === $b->id ? 'bg-indigo-50 text-indigo-700 font-semibold' : 'text-slate-700 hover:bg-slate-50' }}"
                                            aria-pressed="{{ $selectedBrandId === $b->id ? 'true' : 'false' }}"
                                        >
                                            <span class="truncate">{{ $b->name }}</span>
                                            @if ($selectedBrandId === $b->id)
                                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            @endif
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </fieldset>
                    @endif

                    <!-- Price -->
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-900">Price (৳)</legend>
                        <div class="mt-2.5 grid grid-cols-2 gap-3">
                            <div>
                                <label for="filter-min-price" class="block text-xs font-medium text-slate-500 mb-1">Min</label>
                                <input id="filter-min-price" type="number" min="0" inputmode="numeric" placeholder="0" wire:model.blur="minPrice" class="w-full h-10 px-3 text-sm rounded-xl border border-slate-200 tabular-nums focus:ring-indigo-500 focus:border-indigo-500"/>
                            </div>
                            <div>
                                <label for="filter-max-price" class="block text-xs font-medium text-slate-500 mb-1">Max</label>
                                <input id="filter-max-price" type="number" min="0" inputmode="numeric" placeholder="Any" wire:model.blur="maxPrice" class="w-full h-10 px-3 text-sm rounded-xl border border-slate-200 tabular-nums focus:ring-indigo-500 focus:border-indigo-500"/>
                            </div>
                        </div>
                    </fieldset>

                    <!-- Buying type -->
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-900">Buying for</legend>
                        <div class="mt-2.5 grid grid-cols-3 gap-1 p-1 rounded-xl bg-slate-100 text-xs font-semibold">
                            @foreach (['all' => 'Everything', 'retail' => 'Retail', 'wholesale' => 'Wholesale'] as $channelValue => $channelLabel)
                                <button
                                    type="button"
                                    wire:click="$set('channelFilter', '{{ $channelValue }}')"
                                    class="h-9 rounded-lg transition cursor-pointer {{ $channelFilter === $channelValue ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900' }}"
                                    aria-pressed="{{ $channelFilter === $channelValue ? 'true' : 'false' }}"
                                >
                                    {{ $channelLabel }}
                                </button>
                            @endforeach
                        </div>
                    </fieldset>

                    <!-- Deals & availability -->
                    <fieldset>
                        <legend class="text-sm font-bold text-slate-900">Deals &amp; availability</legend>
                        <div class="mt-2.5 space-y-1">
                            @foreach ([
                                'onlyFlashSale' => 'Flash Sale Deals',
                                'inStockOnly' => 'In Stock Only',
                                'verifiedSupplierOnly' => 'Verified Suppliers Only',
                            ] as $toggleProperty => $toggleLabel)
                                <label class="flex items-center justify-between gap-3 py-2 cursor-pointer text-sm text-slate-700">
                                    <span>{{ $toggleLabel }}</span>
                                    <input type="checkbox" wire:model.live="{{ $toggleProperty }}" class="w-5 h-5 rounded-md border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer"/>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                </div>

                <div class="flex items-center gap-3 px-5 py-4 border-t border-slate-100 bg-white">
                    <button type="button" wire:click="resetFilters" class="h-11 px-4 rounded-xl text-sm font-semibold text-slate-700 hover:bg-slate-100 transition cursor-pointer">
                        Clear all
                    </button>
                    <button type="button" @click="filtersOpen = false; $nextTick(() => document.getElementById('all-products')?.scrollIntoView({ behavior: 'smooth' }))" class="flex-1 h-11 rounded-xl bg-slate-900 hover:bg-indigo-600 text-white text-sm font-bold transition cursor-pointer">
                        Show {{ number_format($products->total()) }} {{ str('product')->plural($products->total()) }}
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- ================= QUICK VIEW MODAL ================= -->
    @if ($showQuickView && $quickViewProduct)
        <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/75 backdrop-blur-xs flex items-center justify-center p-4">
            <div 
                class="bg-white rounded-3xl max-w-4xl w-full p-6 sm:p-8 shadow-2xl border border-slate-200 relative animate-in fade-in duration-200 my-8"
                @click.away="$wire.closeQuickView()"
            >
                <!-- Close Button -->
                <button
                    type="button"
                    wire:click="closeQuickView"
                    class="absolute top-5 right-5 p-2 rounded-full text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition z-10 cursor-pointer"
                    title="Close"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 items-start">
                    <!-- Left: Product Image Gallery -->
                    <div class="space-y-4">
                        <div class="relative aspect-4/3 sm:aspect-square bg-slate-100 rounded-2xl overflow-hidden border border-slate-200">
                            <img 
                                src="{{ $quickViewActiveImageUrl }}" 
                                alt="{{ $quickViewProduct->title }}" 
                                class="w-full h-full object-cover transition duration-300"
                            />

                            @if ($quickViewProduct->discount_percentage)
                                <span class="absolute top-3 left-3 px-2.5 py-1 rounded-lg bg-rose-600 text-white font-black text-xs shadow-md">
                                    -{{ $quickViewProduct->discount_percentage }}% OFF
                                </span>
                            @endif

                            @if ($quickViewProduct->seller?->sellerProfile?->is_on_vacation)
                                <div class="absolute bottom-3 inset-x-3 p-2 bg-amber-500/90 backdrop-blur-xs text-white text-xs font-bold text-center rounded-xl shadow-xs">
                                    Seller Currently on Vacation
                                </div>
                            @endif
                        </div>

                        <!-- Thumbnails -->
                        @if ($quickViewProduct->images->count() > 1)
                            <div class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                                @foreach ($quickViewProduct->images as $img)
                                    <button
                                        type="button"
                                        wire:click="selectQuickViewImage('{{ $img->public_url }}')"
                                        class="shrink-0 w-16 h-16 rounded-xl border-2 overflow-hidden transition cursor-pointer {{ $quickViewActiveImageUrl === $img->public_url ? 'border-indigo-600 ring-2 ring-indigo-500/20 shadow-xs' : 'border-slate-200 hover:border-slate-300 opacity-70 hover:opacity-100' }}"
                                    >
                                        <img src="{{ $img->public_url }}" alt="Thumbnail" class="w-full h-full object-cover"/>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Right: Product Information & Purchase Controls -->
                    <div class="space-y-4 text-xs">
                        <!-- Brand & Meta -->
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-indigo-100 text-indigo-700">
                                    {{ $quickViewProduct->category?->name ?? 'Electronics' }}
                                </span>
                                @if ($quickViewProduct->brand)
                                    <span class="text-slate-400 font-bold uppercase">{{ $quickViewProduct->brand->name }}</span>
                                @endif
                                @if ($quickViewProduct->model_number)
                                    <span class="text-slate-300">•</span>
                                    <span class="font-mono text-slate-400">Mod: {{ $quickViewProduct->model_number }}</span>
                                @endif
                            </div>

                            <h2 class="text-lg sm:text-xl font-black text-slate-900 leading-snug tracking-tight">
                                <a href="{{ route('catalog.show', $quickViewProduct->slug) }}" class="hover:text-indigo-600 transition">
                                    {{ $quickViewProduct->title }}
                                </a>
                            </h2>

                            <div class="flex items-center gap-2 text-slate-500 pt-0.5">
                                <span>Sold by: <strong class="text-slate-700 font-semibold">{{ $quickViewProduct->seller?->sellerProfile?->store_name ?? 'Factory Direct' }}</strong></span>
                                @if ($quickViewProduct->seller?->sellerProfile?->is_approved)
                                    <span class="text-emerald-600 flex items-center gap-0.5 font-semibold text-[11px]">
                                        <svg class="w-3.5 h-3.5 fill-current" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                        Verified
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Price Block -->
                        <div class="p-3.5 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-baseline justify-between gap-4">
                            <div>
                                <span class="text-slate-400 text-[11px] block">Special Selling Price</span>
                                <div class="flex items-baseline gap-2.5">
                                    <span class="text-2xl font-black text-slate-900">৳{{ number_format($quickViewProduct->effective_selling_price, 2) }}</span>
                                    @if ($quickViewProduct->regular_price && (float) $quickViewProduct->regular_price > $quickViewProduct->effective_selling_price)
                                        <span class="text-sm text-slate-400 line-through">৳{{ number_format((float) $quickViewProduct->regular_price, 2) }}</span>
                                    @endif
                                </div>
                            </div>

                            @if ($quickViewProduct->savings_amount > 0)
                                <div class="text-right">
                                    <span class="px-2 py-1 rounded-lg text-xs font-black bg-emerald-100 text-emerald-800 inline-block shadow-2xs">
                                        Save ৳{{ number_format($quickViewProduct->savings_amount, 0) }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <!-- Highlights & Warranty -->
                        <div class="grid grid-cols-2 gap-2 text-[11px]">
                            @if ($quickViewProduct->warranty_period)
                                <div class="p-2 rounded-xl bg-slate-50 border border-slate-100 flex items-center gap-1.5 text-slate-700">
                                    <svg class="w-3.5 h-3.5 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                    <span class="font-medium truncate">{{ $quickViewProduct->warranty_period }}</span>
                                </div>
                            @endif
                            <div class="p-2 rounded-xl bg-slate-50 border border-slate-100 flex items-center gap-1.5 text-slate-700">
                                <x-storefront.icon name="truck" class="w-3.5 h-3.5 text-slate-400" />
                                <span class="font-medium truncate">{{ $quickViewProduct->delivery_info ?? '24-48 Hours Express Delivery' }}</span>
                            </div>
                        </div>

                        <!-- Variations Selector -->
                        @if ($quickViewProduct->variations->isNotEmpty())
                            <div class="space-y-1.5 pt-1">
                                <label class="block font-bold text-slate-800 uppercase tracking-wider text-[10px]">Select Variation</label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($quickViewProduct->variations as $var)
                                        @php
                                            $attrSummary = $var->attributeValues->pluck('value')->implode(' / ') ?: $var->sku;
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="selectQuickViewVariation({{ $var->id }})"
                                            class="px-3 py-1.5 rounded-xl border text-xs cursor-pointer transition {{ $quickViewVariationId === $var->id ? 'border-indigo-600 bg-indigo-50/70 text-indigo-900 font-bold ring-2 ring-indigo-500/20 shadow-xs' : 'border-slate-200 bg-white hover:bg-slate-50 text-slate-700' }}"
                                        >
                                            {{ $attrSummary }}
                                            <span class="text-[10px] text-slate-400 font-mono ml-1">({{ $var->stock_quantity }} left)</span>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <!-- Quantity & Stock Status -->
                        <div class="flex items-center justify-between pt-2">
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-slate-700">Quantity:</span>
                                <div class="flex items-center border border-slate-300 rounded-xl overflow-hidden bg-slate-50">
                                    <button
                                        type="button"
                                        wire:click="updateQuickViewQuantity(-1)"
                                        class="px-3 py-1.5 text-slate-700 hover:bg-slate-200 font-bold cursor-pointer"
                                    >
                                        -
                                    </button>
                                    <span class="px-4 py-1.5 font-bold text-slate-900 text-xs min-w-8 text-center bg-white">
                                        {{ $quickViewQuantity }}
                                    </span>
                                    <button
                                        type="button"
                                        wire:click="updateQuickViewQuantity(1)"
                                        class="px-3 py-1.5 text-slate-700 hover:bg-slate-200 font-bold cursor-pointer"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>

                            <span class="inline-flex items-center gap-1.5 text-emerald-700 font-bold text-[11px] bg-emerald-50 px-2.5 py-1 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                In Stock Ready to Dispatch
                            </span>
                        </div>

                        <!-- Actions -->
                        <div class="space-y-2 pt-2 border-t border-slate-100">
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    wire:click="quickViewAddToCart"
                                    class="py-3 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs flex items-center justify-center gap-2 shadow-xs transition cursor-pointer"
                                >
                                    <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                                    <span>Add to Cart</span>
                                </button>

                                <button
                                    type="button"
                                    wire:click="quickViewBuyNow"
                                    class="py-3 px-4 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs flex items-center justify-center gap-2 shadow-md transition cursor-pointer"
                                >
                                    <span>Instant Buy Now &rarr;</span>
                                </button>
                            </div>

                            <div class="text-center pt-1">
                                <a 
                                    href="{{ route('catalog.show', $quickViewProduct->slug) }}" 
                                    class="text-xs font-bold text-indigo-600 hover:text-indigo-800 underline inline-flex items-center gap-1"
                                >
                                    <span>View Full Product Specifications, Tabs & Reviews</span>
                                    <span>&rarr;</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
