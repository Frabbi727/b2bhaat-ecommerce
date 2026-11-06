<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-12">
    <div>
        <nav class="flex items-center gap-2 text-xs text-slate-500 mb-3">
            <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600">Home</a>
            <span>/</span>
            <span class="text-slate-800 font-semibold">Flash Sales</span>
        </nav>
        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Flash Sales & Limited Deals</h1>
        <p class="text-sm text-slate-600 mt-1">Exclusive limited-time promotions with direct factory discounts.</p>
    </div>

    <!-- Live Campaigns -->
    @if ($liveSales->isNotEmpty())
        <div class="space-y-10">
            @foreach ($liveSales as $sale)
                <div class="rounded-3xl bg-gradient-to-r from-rose-950 via-slate-900 to-indigo-950 p-6 sm:p-8 text-white shadow-xl border border-rose-900/40 space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-rose-900/30 pb-5">
                        <div class="flex items-center gap-3">
                            <span class="w-10 h-10 rounded-2xl bg-rose-600 text-white flex items-center justify-center text-xl font-black shadow-md shadow-rose-600/40">
                                ⚡
                            </span>
                            <div>
                                <div class="flex items-center gap-2">
                                    <h2 class="text-xl sm:text-2xl font-black tracking-tight text-white">{{ $sale->name }}</h2>
                                    <span class="px-2 py-0.5 rounded-full bg-rose-500/30 border border-rose-400/40 text-rose-300 text-[10px] font-bold uppercase tracking-wider animate-pulse">
                                        Live Now
                                    </span>
                                </div>
                                @if ($sale->description)
                                    <p class="text-xs text-rose-200/80">{{ $sale->description }}</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-4 flex-wrap">
                            <x-storefront.flash-countdown :ends-at="$sale->ends_at" label="Ends in:" />
                            <a href="{{ route('flash-sales.show', $sale->slug) }}" class="text-xs font-bold text-rose-300 hover:text-white underline">
                                View All ({{ $sale->products->count() }}) &rarr;
                            </a>
                        </div>
                    </div>

                    <!-- Products Grid -->
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                        @foreach ($sale->products as $product)
                            <div class="bg-white rounded-2xl p-3 text-slate-800 border border-slate-200 shadow-xs hover:shadow-lg transition flex flex-col justify-between group">
                                <div>
                                    <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-2">
                                        <a href="{{ route('catalog.show', $product->slug) }}">
                                            <img
                                                src="{{ $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600' }}"
                                                alt="{{ $product->title }}"
                                                class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                                loading="lazy"
                                            />
                                        </a>
                                        @if ($product->discount_percentage)
                                            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-rose-600 text-white font-black text-[11px] shadow-sm">
                                                -{{ $product->discount_percentage }}%
                                            </span>
                                        @endif
                                    </div>
                                    <h3 class="text-xs font-bold text-slate-900 group-hover:text-indigo-600 line-clamp-2 transition mb-1">
                                        <a href="{{ route('catalog.show', $product->slug) }}">
                                            {{ $product->title }}
                                        </a>
                                    </h3>
                                </div>
                                <div class="pt-2 border-t border-slate-100">
                                    <div class="text-sm font-black text-slate-900">
                                        ৳{{ number_format($product->effective_selling_price, 0) }}
                                    </div>
                                    @if ($product->regular_price && (float) $product->regular_price > $product->effective_selling_price)
                                        <div class="text-[11px] text-slate-400 line-through">
                                            ৳{{ number_format((float) $product->regular_price, 0) }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- Upcoming Campaigns -->
    @if ($upcomingSales->isNotEmpty())
        <div class="space-y-6 pt-6 border-t border-slate-200">
            <h2 class="text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <span>🗓️</span> Upcoming Flash Deals
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                @foreach ($upcomingSales as $upcoming)
                    <div class="rounded-3xl bg-slate-900 p-6 text-white border border-slate-800 space-y-4">
                        <div class="flex items-center justify-between gap-4 flex-wrap">
                            <div>
                                <h3 class="text-lg font-bold text-white">{{ $upcoming->name }}</h3>
                                @if ($upcoming->description)
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $upcoming->description }}</p>
                                @endif
                            </div>
                            <x-storefront.flash-countdown :starts-at="$upcoming->starts_at" label="Starts in:" />
                        </div>

                        <div class="grid grid-cols-3 sm:grid-cols-6 gap-2">
                            @foreach ($upcoming->products as $product)
                                <div class="rounded-xl overflow-hidden bg-slate-800 aspect-square border border-slate-700">
                                    <img
                                        src="{{ $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600' }}"
                                        alt="{{ $product->title }}"
                                        class="w-full h-full object-cover opacity-80 hover:opacity-100 transition"
                                        title="{{ $product->title }}"
                                    />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if ($liveSales->isEmpty() && $upcomingSales->isEmpty())
        <div class="text-center py-16 bg-white rounded-3xl border border-slate-200 p-8 space-y-3">
            <span class="text-4xl">🏷️</span>
            <h2 class="text-lg font-bold text-slate-900">No active flash sales right now</h2>
            <p class="text-sm text-slate-500">Check back soon for new scheduled promotions and limited offers.</p>
            <div class="pt-2">
                <a href="{{ route('catalog.index') }}" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition">
                    Browse All Products
                </a>
            </div>
        </div>
    @endif
</div>
