<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <nav class="flex items-center gap-2 text-xs text-slate-500">
        <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600">Home</a>
        <span>/</span>
        <a href="{{ route('flash-sales.index') }}" class="hover:text-indigo-600">Flash Sales</a>
        <span>/</span>
        <span class="text-slate-800 font-semibold">{{ $sale->name }}</span>
    </nav>

    @if ($effectiveStatus === \App\Enums\FlashSaleStatus::Ended || $effectiveStatus === \App\Enums\FlashSaleStatus::Paused)
        <div class="rounded-3xl bg-amber-50 border border-amber-200 p-8 text-center space-y-3">
            <span class="text-3xl">⏳</span>
            <h1 class="text-xl font-bold text-slate-900">This flash sale has ended</h1>
            <p class="text-sm text-slate-600">The promotional pricing for {{ $sale->name }} is no longer active.</p>
            <div class="pt-2">
                <a href="{{ route('flash-sales.index') }}" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-bold text-xs hover:bg-indigo-700 transition">
                    View Other Flash Deals
                </a>
            </div>
        </div>
    @elseif ($effectiveStatus === \App\Enums\FlashSaleStatus::Scheduled)
        <div class="rounded-3xl bg-slate-900 p-6 sm:p-8 text-white space-y-4 border border-slate-800">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="px-2.5 py-1 rounded-md bg-indigo-600/30 text-indigo-300 border border-indigo-500/40 text-[10px] font-bold uppercase tracking-wider">
                        Upcoming Event
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-black mt-2 text-white">{{ $sale->name }}</h1>
                    @if ($sale->description)
                        <p class="text-xs text-slate-400 mt-1">{{ $sale->description }}</p>
                    @endif
                </div>
                <x-storefront.flash-countdown :starts-at="$sale->starts_at" label="Starts in:" />
            </div>
        </div>

        <div class="space-y-4">
            <h2 class="text-lg font-bold text-slate-900">Featured in this Upcoming Sale</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach ($products as $product)
                    <div class="bg-white rounded-2xl p-3 border border-slate-200 flex flex-col justify-between">
                        <div>
                            <div class="relative rounded-xl overflow-hidden bg-slate-100 aspect-square mb-2">
                                <a href="{{ route('catalog.show', $product->slug) }}">
                                    <img
                                        src="{{ $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600' }}"
                                        alt="{{ $product->title }}"
                                        class="w-full h-full object-cover"
                                        loading="lazy"
                                    />
                                </a>
                            </div>
                            <h3 class="text-xs font-bold text-slate-900 line-clamp-2 mb-1">
                                <a href="{{ route('catalog.show', $product->slug) }}">
                                    {{ $product->title }}
                                </a>
                            </h3>
                        </div>
                        <div class="pt-2 border-t border-slate-100 text-sm font-black text-slate-900">
                            ৳{{ number_format($product->effective_selling_price, 0) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <!-- Live Active Campaign -->
        <div class="rounded-3xl bg-gradient-to-r from-rose-950 via-slate-900 to-indigo-950 p-6 sm:p-8 text-white shadow-xl border border-rose-900/40 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-xl bg-rose-600 text-white flex items-center justify-center text-base font-black shadow-md shadow-rose-600/40">⚡</span>
                        <h1 class="text-2xl sm:text-3xl font-black tracking-tight text-white">{{ $sale->name }}</h1>
                        <span class="px-2 py-0.5 rounded-full bg-rose-500/30 border border-rose-400/40 text-rose-300 text-[10px] font-bold uppercase tracking-wider animate-pulse">
                            Live Now
                        </span>
                    </div>
                    @if ($sale->description)
                        <p class="text-xs text-rose-200/80 mt-1">{{ $sale->description }}</p>
                    @endif
                </div>
                <x-storefront.flash-countdown :ends-at="$sale->ends_at" label="Ends in:" />
            </div>
        </div>

        <div class="space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @foreach ($products as $product)
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
                        <div class="pt-2 border-t border-slate-100 space-y-1">
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
    @endif
</div>
