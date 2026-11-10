<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-10">
    <!-- Feedback Alerts -->
    @if ($feedbackMessage)
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <a href="{{ route('cart.index') }}" class="font-bold underline text-emerald-900">View cart &rarr;</a>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2 shadow-xs">
            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    @if (!$isFiltering)
        {{-- Wholesale status strip --}}
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 px-4 py-2.5 rounded-2xl bg-indigo-50/70 border border-indigo-100 text-xs">
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
    <section id="all-products" class="space-y-6 scroll-mt-28" x-data="{ showMobileFilters: false }">
        <!-- Section Header / Active Filter Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">
                    {{ $isFiltering ? 'Search results' : 'All products' }}
                </h2>
                <p class="text-xs text-slate-500">
                    Showing <strong class="text-slate-800">{{ $products->total() }}</strong> available products
                    @if ($search) for "<strong class="text-indigo-600">{{ $search }}</strong>"@endif
                </p>
            </div>

            <!-- Sorting Dropdown & Channel Switcher -->
            <div class="flex items-center gap-3 flex-wrap">
                <!-- Mobile Filters Toggle Button -->
                <button
                    type="button"
                    @click="showMobileFilters = !showMobileFilters"
                    class="lg:hidden inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border border-slate-200 bg-white text-slate-800 text-xs font-bold shadow-xs hover:bg-slate-50 cursor-pointer"
                >
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                    <span>Filters</span>
                    @if ($isFiltering)
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                    @endif
                </button>

                <div class="flex items-center gap-1 bg-white p-1 rounded-xl border border-slate-200 text-xs">
                    <button
                        type="button"
                        wire:click="$set('channelFilter', 'all')"
                        class="px-2.5 py-1 rounded-lg font-semibold transition {{ $channelFilter === 'all' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                    >
                        All
                    </button>
                    <button
                        type="button"
                        wire:click="$set('channelFilter', 'retail')"
                        class="px-2.5 py-1 rounded-lg font-semibold transition {{ $channelFilter === 'retail' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                    >
                        Retail Only
                    </button>
                    <button
                        type="button"
                        wire:click="$set('channelFilter', 'wholesale')"
                        class="px-2.5 py-1 rounded-lg font-semibold transition {{ $channelFilter === 'wholesale' ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:text-slate-900' }}"
                    >
                        Wholesale B2B
                    </button>
                </div>

                <select
                    wire:model.live="sort"
                    class="h-9 pl-3 pr-8 text-xs font-semibold text-slate-700 bg-white border border-slate-200 rounded-xl focus:ring-indigo-500 focus:border-indigo-500"
                >
                    <option value="newest">Sort: Newest First</option>
                    <option value="price_asc">Price: Low to High</option>
                    <option value="price_desc">Price: High to Low</option>
                    <option value="popularity">Popularity / Views</option>
                    <option value="discount">Highest Discount</option>
                </select>

                @if ($isFiltering)
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="px-3 py-1.5 rounded-xl bg-slate-200 hover:bg-slate-300 text-slate-700 text-xs font-bold transition flex items-center gap-1"
                    >
                        <span>Reset</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                @endif
            </div>
        </div>

        <!-- Catalog Layout: Sidebar + Product Grid -->
        @if ($isFiltering)
            <!-- Active Filter Chips -->
            <div class="flex items-center gap-2 flex-wrap text-xs bg-slate-50 p-3 rounded-2xl border border-slate-200">
                <span class="font-bold text-slate-500">Active Filters:</span>
                @if ($search)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-medium">
                        Search: "{{ $search }}"
                        <button type="button" wire:click="$set('search', '')" class="text-indigo-600 hover:text-indigo-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($selectedCategoryId)
                    @php
                        $activeCat = $categories->firstWhere('id', $selectedCategoryId) ?? $categories->flatMap->children->firstWhere('id', $selectedCategoryId);
                    @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-medium">
                        Category: {{ $activeCat?->name ?? $selectedCategoryId }}
                        <button type="button" wire:click="$set('selectedCategoryId', null)" class="text-indigo-600 hover:text-indigo-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($selectedBrandId)
                    @php $activeBrand = $brands->firstWhere('id', $selectedBrandId); @endphp
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-medium">
                        Brand: {{ $activeBrand?->name ?? $selectedBrandId }}
                        <button type="button" wire:click="$set('selectedBrandId', null)" class="text-indigo-600 hover:text-indigo-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($channelFilter !== 'all')
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-medium">
                        Channel: {{ ucfirst($channelFilter) }}
                        <button type="button" wire:click="$set('channelFilter', 'all')" class="text-indigo-600 hover:text-indigo-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if (!is_null($minPrice) || !is_null($maxPrice))
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-indigo-100 text-indigo-800 font-medium">
                        Price: ৳{{ $minPrice ?? '0' }} - {{ $maxPrice ? '৳'.$maxPrice : 'Any' }}
                        <button type="button" wire:click="$set('minPrice', null); $set('maxPrice', null)" class="text-indigo-600 hover:text-indigo-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($onlyFlashSale)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-rose-100 text-rose-800 font-medium">
                        Flash Sale
                        <button type="button" wire:click="$set('onlyFlashSale', false)" class="text-rose-600 hover:text-rose-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($inStockOnly)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-medium">
                        In Stock Only
                        <button type="button" wire:click="$set('inStockOnly', false)" class="text-emerald-600 hover:text-emerald-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($verifiedSupplierOnly)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-teal-100 text-teal-800 font-medium">
                        Verified Suppliers
                        <button type="button" wire:click="$set('verifiedSupplierOnly', false)" class="text-teal-600 hover:text-teal-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                <button type="button" wire:click="resetFilters" class="text-xs text-rose-600 hover:text-rose-800 font-semibold underline ml-2">
                    Clear all
                </button>
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Left Filter Sidebar (Collapsible on mobile) -->
            <div :class="showMobileFilters ? 'block' : 'hidden lg:block'" class="lg:col-span-1 space-y-6">
                <!-- Categories Filter -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-900">Categories</h3>
                    <div class="space-y-1 max-h-64 overflow-y-auto text-xs">
                        <button
                            type="button"
                            wire:click="$set('selectedCategoryId', null)"
                            class="w-full text-left px-2 py-1.5 rounded-lg font-semibold transition {{ is_null($selectedCategoryId) ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50' }}"
                        >
                            All Categories
                        </button>
                        @foreach ($categories as $cat)
                            <div>
                                <button
                                    type="button"
                                    wire:click="selectCategory({{ $cat->id }})"
                                    class="w-full text-left px-2 py-1.5 rounded-lg transition flex items-center justify-between {{ $selectedCategoryId === $cat->id ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50' }}"
                                >
                                    <span class="truncate">{{ $cat->name }}</span>
                                    @if ($cat->children->isNotEmpty())
                                        <span class="text-[10px] text-slate-400">({{ $cat->children->count() }})</span>
                                    @endif
                                </button>
                                @if ($cat->children->isNotEmpty() && ($selectedCategoryId === $cat->id || $cat->children->contains('id', $selectedCategoryId)))
                                    <div class="pl-3 py-1 space-y-0.5 border-l-2 border-indigo-100 ml-2 mt-0.5">
                                        @foreach ($cat->children as $child)
                                            <button
                                                type="button"
                                                wire:click="selectCategory({{ $child->id }})"
                                                class="w-full text-left px-2 py-1 rounded text-[11px] transition flex items-center justify-between {{ $selectedCategoryId === $child->id ? 'bg-indigo-100 text-indigo-800 font-bold' : 'text-slate-500 hover:text-slate-900 hover:bg-slate-50' }}"
                                            >
                                                <span class="truncate">{{ $child->name }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Brands Filter -->
                @if ($brands->isNotEmpty())
                    <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-900">Brands</h3>
                        <div class="space-y-1 max-h-48 overflow-y-auto text-xs">
                            @foreach ($brands as $b)
                                <button
                                    type="button"
                                    wire:click="selectBrand({{ $b->id }})"
                                    class="w-full text-left px-2 py-1.5 rounded-lg transition flex items-center justify-between {{ $selectedBrandId === $b->id ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50' }}"
                                >
                                    <span>{{ $b->name }}</span>
                                    @if ($selectedBrandId === $b->id)
                                        <svg class="w-3.5 h-3.5 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Price Range Filter -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-900">Price Range (৳)</h3>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1">Min Price</label>
                            <input
                                type="number"
                                min="0"
                                placeholder="Min ৳"
                                wire:model.blur="minPrice"
                                class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1">Max Price</label>
                            <input
                                type="number"
                                min="0"
                                placeholder="Max ৳"
                                wire:model.blur="maxPrice"
                                class="w-full px-2.5 py-1.5 text-xs rounded-lg border border-slate-200 focus:ring-indigo-500 focus:border-indigo-500"
                            />
                        </div>
                    </div>
                </div>

                <!-- Stock, Trust & Promotion Filters -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-900">Deals & Availability</h3>
                    <div class="space-y-2">
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                            <input
                                type="checkbox"
                                wire:model.live="onlyFlashSale"
                                class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"
                            />
                            <span>Flash Sale Deals</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                            <input
                                type="checkbox"
                                wire:model.live="inStockOnly"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span>In Stock Only</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                            <input
                                type="checkbox"
                                wire:model.live="verifiedSupplierOnly"
                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            <span>Verified Suppliers Only</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Right Product Grid -->
            <div class="lg:col-span-3">
                @if ($products->isEmpty())
                    <div class="p-12 text-center rounded-3xl bg-white border border-slate-200 space-y-3">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
                        </div>
                        <h3 class="text-base font-bold text-slate-900">No products match your criteria</h3>
                        <p class="text-xs text-slate-500 max-w-sm mx-auto">
                            Try broadening your search term, clearing price thresholds, or resetting category filters.
                        </p>
                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="mt-2 px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs shadow-xs transition"
                        >
                            Reset All Filters
                        </button>
                    </div>
                @else
                    <div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4">
                        @foreach ($products as $item)
                            <x-storefront.product-card
                                :product="$item"
                                :wishlisted="in_array($item->id, $userWishlistIds)"
                                wire:key="catalog-{{ $item->id }}"
                            />
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="mt-8">
                        {{ $products->links() }}
                    </div>
                @endif
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
                                <span>🚚</span>
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
