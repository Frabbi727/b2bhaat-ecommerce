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
                @if ($flashSaleEndsAt)
                data-ends-at="{{ $flashSaleEndsAt->getTimestampMs() }}"
                x-data="{
                    endsAt: {{ $flashSaleEndsAt->getTimestampMs() }},
                    hours: 0,
                    minutes: 0,
                    seconds: 0,
                    tick() {
                        const left = Math.max(0, Math.floor((this.endsAt - Date.now()) / 1000));
                        this.hours = Math.floor(left / 3600);
                        this.minutes = Math.floor((left % 3600) / 60);
                        this.seconds = left % 60;
                    }
                }"
                x-init="tick(); setInterval(() => tick(), 1000)"
                @endif
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
                    @if ($flashSaleEndsAt)
                    <div class="flex items-center gap-2 self-start sm:self-auto bg-slate-950/60 px-4 py-2 rounded-2xl border border-rose-800/40">
                        <span class="text-xs text-rose-200 font-semibold mr-1">Ends in:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(hours).padStart(2, '0')">{{ str_pad((string) intdiv((int) max(0, now()->diffInSeconds($flashSaleEndsAt, false)), 3600), 2, '0', STR_PAD_LEFT) }}</div>
                        <span class="font-bold text-rose-400">:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(minutes).padStart(2, '0')">{{ str_pad((string) intdiv((int) max(0, now()->diffInSeconds($flashSaleEndsAt, false)) % 3600, 60), 2, '0', STR_PAD_LEFT) }}</div>
                        <span class="font-bold text-rose-400">:</span>
                        <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(seconds).padStart(2, '0')">{{ str_pad((string) ((int) max(0, now()->diffInSeconds($flashSaleEndsAt, false)) % 60), 2, '0', STR_PAD_LEFT) }}</div>
                    </div>
                    @endif
                </div>

                <!-- Flash Products Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    @foreach ($flashSaleProducts as $product)
                        <div class="bg-white rounded-2xl p-4 text-slate-800 border border-slate-200 shadow-md hover:shadow-xl transition flex flex-col justify-between group">
                            <div>
                                <!-- Image with discount pill & wishlist button -->
                                <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-3">
                                    @php
                                        $pImg = $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600';
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

                                    <!-- Quick View Hover Action -->
                                    <button
                                        type="button"
                                        wire:click="openQuickView({{ $product->id }})"
                                        class="absolute inset-x-3 bottom-3 py-2 px-3 rounded-xl bg-slate-900/90 hover:bg-slate-950 text-white text-xs font-bold backdrop-blur-xs opacity-0 group-hover:opacity-100 transition duration-200 flex items-center justify-center gap-1.5 shadow-md cursor-pointer z-10"
                                        title="Quick View"
                                    >
                                        <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <span>Quick View</span>
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
                                    <button
                                        type="button"
                                        wire:click="openQuickView({{ $product->id }})"
                                        class="py-2 text-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition flex items-center justify-center gap-1 cursor-pointer"
                                    >
                                        <span>Quick View</span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="quickAddToCart({{ $product->id }})"
                                        class="py-2 text-center rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold transition shadow-xs flex items-center justify-center gap-1 cursor-pointer"
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
                                        $itemImg = $item->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
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

                                    <!-- Quick View Hover Action -->
                                    <button
                                        type="button"
                                        wire:click="openQuickView({{ $item->id }})"
                                        class="absolute inset-x-2.5 bottom-2.5 py-1.5 px-2 rounded-xl bg-slate-900/90 hover:bg-slate-950 text-white text-[11px] font-bold backdrop-blur-xs opacity-0 group-hover:opacity-100 transition duration-200 flex items-center justify-center gap-1 shadow-md cursor-pointer z-10"
                                        title="Quick View"
                                    >
                                        <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                        <span>Quick View</span>
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

                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        type="button"
                                        wire:click="openQuickView({{ $item->id }})"
                                        class="py-2 text-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition cursor-pointer"
                                    >
                                        Quick View
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="quickAddToCart({{ $item->id }})"
                                        class="py-2 text-center rounded-xl bg-slate-900 hover:bg-indigo-600 text-white text-xs font-bold transition shadow-xs cursor-pointer"
                                    >
                                        {{ $item->has_variations && $item->variations->count() > 1 ? 'Options' : 'Add' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    <!-- ================= FULL CATALOG & FILTERING EXPLORER ================= -->
    <div class="space-y-6 pt-4" x-data="{ showMobileFilters: false }">
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
                        ⚡ Flash Sale
                        <button type="button" wire:click="$set('onlyFlashSale', false)" class="text-rose-600 hover:text-rose-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($inStockOnly)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-emerald-100 text-emerald-800 font-medium">
                        📦 In Stock Only
                        <button type="button" wire:click="$set('inStockOnly', false)" class="text-emerald-600 hover:text-emerald-900 text-sm font-bold">&times;</button>
                    </span>
                @endif
                @if ($verifiedSupplierOnly)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-teal-100 text-teal-800 font-medium">
                        🛡️ Verified Suppliers
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
                            <span>⚡ Flash Sale Deals</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                            <input
                                type="checkbox"
                                wire:model.live="inStockOnly"
                                class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                            />
                            <span>📦 In Stock Only</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-slate-800">
                            <input
                                type="checkbox"
                                wire:model.live="verifiedSupplierOnly"
                                class="rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                            />
                            <span>🛡️ Verified Suppliers Only</span>
                        </label>
                    </div>
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
                                            $catImg = $item->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
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

                                        <!-- Quick View Hover Action -->
                                        <button
                                            type="button"
                                            wire:click="openQuickView({{ $item->id }})"
                                            class="absolute inset-x-2.5 bottom-2.5 py-1.5 px-2 rounded-xl bg-slate-900/90 hover:bg-slate-950 text-white text-[11px] font-bold backdrop-blur-xs opacity-0 group-hover:opacity-100 transition duration-200 flex items-center justify-center gap-1 shadow-md cursor-pointer z-10"
                                            title="Quick View"
                                        >
                                            <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            <span>Quick View</span>
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
                                        <button
                                            type="button"
                                            wire:click="openQuickView({{ $item->id }})"
                                            class="py-2 text-center rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition cursor-pointer"
                                        >
                                            Quick View
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="quickAddToCart({{ $item->id }})"
                                            class="py-2 text-center rounded-xl bg-slate-900 hover:bg-indigo-600 text-white text-xs font-bold transition shadow-xs cursor-pointer"
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
                                    <span>🛡️</span>
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
