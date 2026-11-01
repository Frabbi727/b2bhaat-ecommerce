<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-10">
    <!-- Feedback Alerts -->
    @if ($feedbackMessage)
        <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-semibold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-emerald-600 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <a href="{{ route('cart.index') }}" class="font-bold underline text-emerald-900">View Cart & Checkout &rarr;</a>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-semibold flex items-center gap-2 shadow-xs">
            <svg class="w-4 h-4 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    @if (!$isFiltering)
        <!-- Channel Mode Indicator -->
        <div class="flex items-center justify-between gap-3 px-4 py-2.5 rounded-2xl bg-indigo-50/70 border border-indigo-100 text-xs">
            <div class="flex items-center gap-2">
                @if ($isWholesaleUser)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-emerald-100 text-emerald-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        Wholesale Business Account Active
                    </span>
                    <span class="text-slate-600 hidden sm:inline">Bulk procurement unlocked: Tiered MOQ pricing in effect.</span>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-indigo-100 text-indigo-800">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-pulse"></span>
                        Consumer Market Mode
                    </span>
                    <span class="text-slate-600 hidden sm:inline">Single-unit retail purchasing with express islandwide delivery.</span>
                @endif
            </div>
            @if (!$isWholesaleUser)
                <a href="{{ route('wholesale.upgrade') }}" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 hover:underline shrink-0">
                    Upgrade to B2B Wholesale &rarr;
                </a>
            @endif
        </div>

        <!-- ================= HOMEPAGE HERO BANNER SLIDER ================= -->
        @if ($heroBanners->isNotEmpty())
            <div
                x-data="{
                    active: 0,
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
                class="relative rounded-3xl overflow-hidden shadow-xl border border-slate-200/80 bg-slate-900 aspect-16/9 sm:aspect-21/9"
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
                        <img
                            src="{{ $banner->desktop_image_url }}"
                            alt="{{ $banner->title }}"
                            class="w-full h-full object-cover object-center brightness-90"
                            loading="{{ $index === 0 ? 'eager' : 'lazy' }}"
                        />

                        <!-- Gradient Overlay with Content -->
                        <div class="absolute inset-0 bg-gradient-to-r from-slate-950/85 via-slate-900/60 to-transparent flex items-center">
                            <div class="max-w-2xl px-6 sm:px-12 py-8 text-white space-y-3">
                                @if ($banner->badge_text)
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-400 text-slate-950 shadow-sm">
                                        ⚡ {{ $banner->badge_text }}
                                    </span>
                                @endif
                                <h2 class="text-2xl sm:text-4xl lg:text-5xl font-black tracking-tight leading-tight">
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
                    class="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/60 hover:bg-slate-900 text-white flex items-center justify-center backdrop-blur-sm transition z-20"
                    aria-label="Previous Slide"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <button
                    @click="next()"
                    type="button"
                    class="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-slate-900/60 hover:bg-slate-900 text-white flex items-center justify-center backdrop-blur-sm transition z-20"
                    aria-label="Next Slide"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </button>

                <!-- Carousel Navigation Dots -->
                <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-2 z-20">
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

        <!-- ================= CATEGORY ICON SHOWCASE ================= -->
        @if ($categories->isNotEmpty())
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg sm:text-xl font-extrabold text-slate-900">Browse by Industry Sector</h2>
                        <p class="text-xs text-slate-500">Explore authentic electronics, gadgets, appliances & wholesale goods</p>
                    </div>
                    <button
                        wire:click="$set('selectedCategoryId', null)"
                        class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition"
                    >
                        Browse All &rarr;
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 sm:gap-4">
                    @foreach ($categories->take(6) as $cat)
                        <button
                            type="button"
                            wire:click="selectCategory({{ $cat->id }})"
                            class="group p-4 rounded-2xl bg-white border border-slate-200/80 hover:border-indigo-500 hover:shadow-md transition text-center flex flex-col items-center justify-center gap-3 aspect-square"
                        >
                            <div class="w-16 h-16 rounded-2xl bg-indigo-50/50 group-hover:bg-indigo-100 flex items-center justify-center overflow-hidden transition p-2">
                                @if ($cat->icon_url)
                                    <img src="{{ $cat->icon_url }}" alt="{{ $cat->name }}" class="w-full h-full object-cover rounded-xl group-hover:scale-110 transition"/>
                                @else
                                    <svg class="w-8 h-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
                                @endif
                            </div>
                            <div>
                                <span class="text-xs font-bold text-slate-900 group-hover:text-indigo-600 line-clamp-1 block">
                                    {{ $cat->name }}
                                </span>
                                <span class="text-[10px] text-slate-400 font-medium">Explore &rarr;</span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- ================= VERIFIED FACTORIES & DIRECT IMPORTERS ================= -->
        @if ($featuredSellers->isNotEmpty())
            <div class="bg-gradient-to-br from-slate-50 to-indigo-50/40 border border-slate-200 rounded-3xl p-6 shadow-xs">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            <h2 class="text-sm sm:text-base font-black uppercase tracking-wider text-slate-900">Verified Factories & Direct Importers</h2>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">Vetted suppliers operating with verified Bangladeshi Trade Licenses & BIN certificates</p>
                    </div>
                    <a href="{{ route('seller.register') }}" class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition flex items-center gap-1 self-start sm:self-auto">
                        <span>Register as Manufacturer</span>
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    @foreach ($featuredSellers as $sellerProfile)
                        <a href="{{ route('store.show', $sellerProfile->store_slug) }}" class="group bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:border-indigo-500 hover:shadow-md transition flex flex-col justify-between">
                            <div>
                                <div class="flex items-start justify-between gap-2">
                                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-indigo-600 transition line-clamp-1">{{ $sellerProfile->store_name }}</h3>
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200 shrink-0">
                                        <svg class="w-3 h-3 text-emerald-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                        Verified
                                    </span>
                                </div>
                                <div class="mt-2 text-[11px] text-slate-500 space-y-1">
                                    <div class="flex items-center gap-1 font-mono text-[10px] text-slate-400">
                                        <span>Lic:</span>
                                        <span>{{ $sellerProfile->trade_license_number ?? 'Verified Partner' }}</span>
                                    </div>
                                    <div class="text-[11px] text-slate-600">
                                        {{ $sellerProfile->products_count }} active product listings
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between text-xs text-indigo-600 font-semibold group-hover:text-indigo-700">
                                <span>Visit Factory Store</span>
                                <span>&rarr;</span>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- ================= FLASH SALE SECTION WITH LIVE COUNTDOWN ================= -->
        @if ($flashSaleProducts->isNotEmpty())
            <div
                id="flash-sales"
                x-data="{
                    hours: 14,
                    minutes: 32,
                    seconds: 45,
                    tick() {
                        if (this.seconds > 0) {
                            this.seconds--;
                        } else {
                            this.seconds = 59;
                            if (this.minutes > 0) {
                                this.minutes--;
                            } else {
                                this.minutes = 59;
                                if (this.hours > 0) this.hours--;
                            }
                        }
                    }
                }"
                x-init="setInterval(() => tick(), 1000)"
                class="rounded-3xl bg-gradient-to-r from-rose-950 via-slate-900 to-indigo-950 p-6 sm:p-8 text-white shadow-xl border border-rose-900/40 space-y-6"
            >
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-rose-900/30 pb-5">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-xl font-black shadow-md shadow-rose-600/40">
                            ⚡
                        </span>
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="text-xl sm:text-2xl font-black tracking-tight text-white">Flash Sale</h2>
                                <span class="px-2 py-0.5 rounded-full bg-rose-500/30 border border-rose-400/40 text-rose-300 text-[10px] font-bold uppercase tracking-wider animate-pulse">
                                    Live Now
                                </span>
                            </div>
                            <p class="text-xs text-rose-200/80">Massive limited-time price drops on flagship tech & essentials</p>
                        </div>
                    </div>

                    <!-- Countdown Clock -->
                    <div class="flex items-center gap-2 self-start sm:self-auto bg-slate-950/60 px-4 py-2 rounded-2xl border border-rose-800/40">
                        <span class="text-xs text-rose-200 font-semibold mr-1">Ends in:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(hours).padStart(2, '0')">14</div>
                        <span class="font-bold text-rose-400">:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(minutes).padStart(2, '0')">32</div>
                        <span class="font-bold text-rose-400">:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(seconds).padStart(2, '0')">45</div>
                    </div>
                </div>

                <!-- Flash Products Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    @foreach ($flashSaleProducts as $product)
                        <div class="bg-white rounded-2xl p-4 text-slate-800 border border-slate-200 shadow-md hover:shadow-xl transition flex flex-col justify-between group">
                            <div>
                                <!-- Image with discount pill & wishlist button -->
                                <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-3">
                                    @php
                                        $pImg = $product->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600';
                                    @endphp
                                    <a href="{{ route('catalog.show', $product->slug) }}">
                                        <img src="{{ $pImg }}" alt="{{ $product->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy"/>
                                    </a>

                                    @if ($product->discount_percentage)
                                        <span class="absolute top-2.5 left-2.5 px-2.5 py-1 rounded-lg bg-rose-600 text-white font-black text-xs shadow-md">
                                            -{{ $product->discount_percentage }}%
                                        </span>
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="toggleWishlist({{ $product->id }})"
                                        class="absolute top-2.5 right-2.5 p-2 rounded-xl bg-white/90 backdrop-blur-sm text-slate-600 hover:text-rose-600 shadow-xs transition"
                                        title="Save to Wishlist"
                                    >
                                        <svg class="w-4 h-4 {{ in_array($product->id, $userWishlistIds) ? 'fill-rose-500 text-rose-500' : 'currentColor' }}" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                    </button>
                                </div>

                                <!-- Brand & Warranty Badge -->
                                <div class="flex items-center gap-2 mb-1 text-[11px]">
                                    <span class="font-bold text-indigo-600 uppercase">{{ $product->brand?->name ?? 'Official Brand' }}</span>
                                    @if ($product->warranty_period)
                                        <span class="text-slate-300">•</span>
                                        <span class="text-emerald-700 font-medium truncate">{{ $product->warranty_period }}</span>
                                    @endif
                                </div>

                                <!-- Product Title -->
                                <h3 class="text-sm font-bold text-slate-900 group-hover:text-indigo-600 line-clamp-2 transition mb-2">
                                    <a href="{{ route('catalog.show', $product->slug) }}">
                                        {{ $product->title }}
                                    </a>
                                </h3>
                            </div>

                            <!-- Pricing Block & Quick Add -->
                            <div class="pt-3 border-t border-slate-100">
                                <div class="flex items-baseline justify-between mb-3">
                                    <div>
                                        <div class="text-base sm:text-lg font-black text-slate-900">
                                            ৳{{ number_format($product->effective_selling_price, 0) }}
                                        </div>
                                        @if ($product->regular_price && (float) $product->regular_price > $product->effective_selling_price)
                                            <div class="flex items-center gap-1.5 text-xs">
                                                <span class="text-slate-400 line-through">৳{{ number_format((float) $product->regular_price, 0) }}</span>
                                                <span class="text-emerald-700 font-semibold">Save ৳{{ number_format($product->savings_amount, 0) }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    @if ($product->model_number)
                                        <span class="text-[10px] font-mono text-slate-400 uppercase">Mod: {{ $product->model_number }}</span>
                                    @endif
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <a
                                        href="{{ route('catalog.show', $product->slug) }}"
                                        class="py-2 text-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition"
                                    >
                                        Details
                                    </a>
                                    <button
                                        type="button"
                                        wire:click="quickAddToCart({{ $product->id }})"
                                        class="py-2 text-center rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition shadow-xs flex items-center justify-center gap-1"
                                    >
                                        <span>Add to Cart</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <!-- ================= FEATURED PRODUCTS SECTION ================= -->
        @if ($featuredProducts->isNotEmpty())
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-3">
                    <div>
                        <h2 class="text-lg sm:text-xl font-extrabold text-slate-900">Featured Products</h2>
                        <p class="text-xs text-slate-500">Curated top-selling electronics, lifestyle & consumer favorites</p>
                    </div>
                    <button
                        wire:click="$set('channelFilter', 'all')"
                        class="text-xs font-bold text-indigo-600 hover:text-indigo-800 transition"
                    >
                        View Full Catalog &rarr;
                    </button>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 sm:gap-6">
                    @foreach ($featuredProducts as $item)
                        <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs hover:border-indigo-400 hover:shadow-lg transition flex flex-col justify-between group">
                            <div>
                                <!-- Image wrapper with aspect-square -->
                                <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-3">
                                    @php
                                        $itemImg = $item->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
                                    @endphp
                                    <a href="{{ route('catalog.show', $item->slug) }}">
                                        <img src="{{ $itemImg }}" alt="{{ $item->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy"/>
                                    </a>

                                    @if ($item->discount_percentage)
                                        <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-rose-500 text-white font-extrabold text-[11px] shadow-xs">
                                            -{{ $item->discount_percentage }}%
                                        </span>
                                    @endif

                                    <button
                                        type="button"
                                        wire:click="toggleWishlist({{ $item->id }})"
                                        class="absolute top-2 right-2 p-1.5 rounded-lg bg-white/90 text-slate-600 hover:text-rose-600 shadow-xs transition"
                                        title="Wishlist"
                                    >
                                        <svg class="w-4 h-4 {{ in_array($item->id, $userWishlistIds) ? 'fill-rose-500 text-rose-500' : 'currentColor' }}" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                    </button>
                                </div>

                                <div class="text-[11px] font-bold text-indigo-600 uppercase mb-1">
                                    {{ $item->brand?->name ?? 'Verified Direct' }}
                                </div>

                                <h3 class="text-xs sm:text-sm font-bold text-slate-900 group-hover:text-indigo-600 line-clamp-2 transition mb-2">
                                    <a href="{{ route('catalog.show', $item->slug) }}">
                                        {{ $item->title }}
                                    </a>
                                </h3>
                            </div>

                            <div class="pt-3 border-t border-slate-100">
                                <div class="flex items-baseline justify-between mb-2">
                                    <div>
                                        <div class="text-sm sm:text-base font-extrabold text-slate-900">
                                            ৳{{ number_format($item->effective_selling_price, 0) }}
                                        </div>
                                        @if ($item->regular_price && (float) $item->regular_price > $item->effective_selling_price)
                                            <div class="text-[11px] text-slate-400 line-through">
                                                ৳{{ number_format((float) $item->regular_price, 0) }}
                                            </div>
                                        @endif
                                    </div>
                                    <span class="text-[10px] text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded font-semibold">
                                        In Stock
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    wire:click="quickAddToCart({{ $item->id }})"
                                    class="w-full py-2 text-center rounded-xl bg-slate-900 hover:bg-indigo-600 text-white text-xs font-bold transition shadow-xs"
                                >
                                    {{ $item->has_variations && $item->variations->count() > 1 ? 'Select Options' : 'Add to Cart' }}
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <!-- ================= FULL CATALOG & FILTERING EXPLORER ================= -->
    <div class="space-y-6 pt-4">
        <!-- Section Header / Active Filter Bar -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-200 pb-4">
            <div>
                <h2 class="text-xl font-extrabold text-slate-900">
                    {{ $isFiltering ? 'Search & Filter Results' : 'Complete Marketplace Catalog' }}
                </h2>
                <p class="text-xs text-slate-500">
                    Showing <strong class="text-slate-800">{{ $products->total() }}</strong> available products
                    @if ($search) for "<strong class="text-indigo-600">{{ $search }}</strong>"@endif
                </p>
            </div>

            <!-- Sorting Dropdown & Channel Switcher -->
            <div class="flex items-center gap-3 flex-wrap">
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
        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Left Filter Sidebar -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Categories Filter -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs space-y-3">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-900">Categories</h3>
                    <div class="space-y-1 max-h-60 overflow-y-auto text-xs">
                        <button
                            type="button"
                            wire:click="$set('selectedCategoryId', null)"
                            class="w-full text-left px-2 py-1.5 rounded-lg font-semibold transition {{ is_null($selectedCategoryId) ? 'bg-indigo-50 text-indigo-700 font-bold' : 'text-slate-600 hover:bg-slate-50' }}"
                        >
                            All Categories
                        </button>
                        @foreach ($categories as $cat)
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

                <!-- Flash Sale Filter Checkbox -->
                <div class="p-4 rounded-2xl bg-white border border-slate-200 shadow-xs">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                        <input
                            type="checkbox"
                            wire:model.live="onlyFlashSale"
                            class="rounded border-slate-300 text-rose-600 focus:ring-rose-500"
                        />
                        <span>⚡ Flash Sale Deals Only</span>
                    </label>
                </div>
            </div>

            <!-- Right Product Grid -->
            <div class="lg:col-span-3">
                @if ($products->isEmpty())
                    <div class="p-12 text-center rounded-3xl bg-white border border-slate-200 space-y-3">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center mx-auto text-xl">
                            🔍
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                        @foreach ($products as $item)
                            <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs hover:border-indigo-400 hover:shadow-lg transition flex flex-col justify-between group">
                                <div>
                                    <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-3">
                                        @php
                                            $catImg = $item->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
                                        @endphp
                                        <a href="{{ route('catalog.show', $item->slug) }}">
                                            <img src="{{ $catImg }}" alt="{{ $item->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300" loading="lazy"/>
                                        </a>

                                        @if ($item->discount_percentage)
                                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-rose-600 text-white font-black text-xs shadow-xs">
                                                -{{ $item->discount_percentage }}%
                                            </span>
                                        @endif

                                        <button
                                            type="button"
                                            wire:click="toggleWishlist({{ $item->id }})"
                                            class="absolute top-2 right-2 p-1.5 rounded-lg bg-white/90 text-slate-600 hover:text-rose-600 shadow-xs transition"
                                            title="Wishlist"
                                        >
                                            <svg class="w-4 h-4 {{ in_array($item->id, $userWishlistIds) ? 'fill-rose-500 text-rose-500' : 'currentColor' }}" viewBox="0 0 24 24"><path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
                                        </button>
                                    </div>

                                    <div class="flex items-center gap-1.5 text-[11px] mb-1">
                                        <span class="font-bold text-indigo-600 uppercase">{{ $item->brand?->name ?? 'Direct' }}</span>
                                        @if ($item->salesChannel?->slug === 'both')
                                            <span class="text-slate-300">•</span>
                                            <span class="text-[10px] font-bold text-emerald-700 bg-emerald-50 px-1 py-0.2 rounded">B2B + Retail</span>
                                        @endif
                                    </div>

                                    <h3 class="text-sm font-bold text-slate-900 group-hover:text-indigo-600 line-clamp-2 transition mb-2">
                                        <a href="{{ route('catalog.show', $item->slug) }}">
                                            {{ $item->title }}
                                        </a>
                                    </h3>
                                </div>

                                <div class="pt-3 border-t border-slate-100">
                                    <div class="flex items-baseline justify-between mb-3">
                                        <div>
                                            <div class="text-base font-black text-slate-900">
                                                ৳{{ number_format($item->effective_selling_price, 0) }}
                                            </div>
                                            @if ($item->regular_price && (float) $item->regular_price > $item->effective_selling_price)
                                                <div class="text-[11px] text-slate-400 line-through">
                                                    ৳{{ number_format((float) $item->regular_price, 0) }}
                                                </div>
                                            @endif
                                        </div>
                                        @if ($item->warranty_period)
                                            <span class="text-[10px] text-slate-500 truncate max-w-[120px]" title="{{ $item->warranty_period }}">
                                                🛡️ {{ $item->warranty_period }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <a
                                            href="{{ route('catalog.show', $item->slug) }}"
                                            class="py-2 text-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition"
                                        >
                                            View
                                        </a>
                                        <button
                                            type="button"
                                            wire:click="quickAddToCart({{ $item->id }})"
                                            class="py-2 text-center rounded-xl bg-slate-900 hover:bg-indigo-600 text-white text-xs font-bold transition shadow-xs"
                                        >
                                            Add
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <!-- Pagination -->
                    <div class="mt-8">
                        {{ $products->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
