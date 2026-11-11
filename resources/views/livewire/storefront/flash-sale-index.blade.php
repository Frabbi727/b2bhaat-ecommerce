<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-12">
    <div>
        <nav class="flex items-center gap-2 text-xs text-slate-500 mb-3" aria-label="Breadcrumb">
            <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600">Home</a>
            <span aria-hidden="true">/</span>
            <span class="text-slate-800 font-semibold">Flash Sales</span>
        </nav>
        <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight">Flash Sales & Limited Deals</h1>
        <p class="text-sm text-slate-600 mt-1">Exclusive limited-time promotions with direct factory discounts.</p>
    </div>

    <!-- Live Campaigns -->
    @foreach ($liveSales as $sale)
        <section class="space-y-4" aria-label="{{ $sale->name }}">
            <x-storefront.flash-campaign-header
                :sale="$sale"
                :link="$sale->approved_products_count > 0 ? route('flash-sales.show', $sale->slug) : null"
                :link-label="'See all '.$sale->approved_products_count.' '.str('deal')->plural($sale->approved_products_count)"
            />

            @if ($sale->products->isNotEmpty())
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                    @foreach ($sale->products as $product)
                        <x-storefront.product-card :product="$product" :actions="false" wire:key="flash-{{ $sale->id }}-{{ $product->id }}" />
                    @endforeach
                </div>
            @else
                <p class="px-1 text-sm text-slate-500">Deals for this campaign are being approved. Check back shortly.</p>
            @endif
        </section>
    @endforeach

    <!-- Upcoming Campaigns -->
    @if ($upcomingSales->isNotEmpty())
        <section class="space-y-5 pt-8 border-t border-slate-200" aria-labelledby="upcoming-heading">
            <h2 id="upcoming-heading" class="text-xl sm:text-2xl font-black text-slate-900 tracking-tight flex items-center gap-2">
                <x-storefront.icon name="calendar" class="w-6 h-6 text-indigo-600" />
                Upcoming Flash Deals
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                @foreach ($upcomingSales as $upcoming)
                    <a href="{{ route('flash-sales.show', $upcoming->slug) }}" class="group block rounded-3xl bg-slate-900 p-5 sm:p-6 text-white border border-slate-800 hover:border-slate-600 transition space-y-4">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="min-w-0">
                                <h3 class="text-lg font-bold text-white group-hover:text-indigo-200">{{ $upcoming->name }}</h3>
                                @if ($upcoming->description)
                                    <p class="text-xs text-slate-400 mt-0.5">{{ $upcoming->description }}</p>
                                @endif
                            </div>
                            <x-storefront.flash-countdown :starts-at="$upcoming->starts_at" label="Starts in:" />
                        </div>

                        @if ($upcoming->products->isNotEmpty())
                            <div class="grid grid-cols-4 sm:grid-cols-6 gap-2">
                                @foreach ($upcoming->products as $product)
                                    <div class="rounded-xl overflow-hidden bg-slate-800 aspect-square border border-slate-700">
                                        <img src="{{ $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1593784991095-a205069470b6?w=600' }}" alt="{{ $product->title }}" title="{{ $product->title }}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition" loading="lazy"/>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($liveSales->isEmpty() && $upcomingSales->isEmpty())
        <div class="text-center py-16 bg-white rounded-3xl border border-slate-200 p-8">
            <span class="grid place-items-center w-12 h-12 mx-auto rounded-2xl bg-rose-50 text-rose-600">
                <x-storefront.icon name="tag" class="w-6 h-6" />
            </span>
            <h2 class="mt-4 text-lg font-bold text-slate-900">No active flash sales right now</h2>
            <p class="mt-1 text-sm text-slate-500">Check back soon for new scheduled promotions and limited offers.</p>
            <a href="{{ route('catalog.index') }}" class="inline-flex items-center mt-5 h-10 px-5 rounded-xl bg-slate-900 text-white font-bold text-sm hover:bg-indigo-600 transition">
                Browse All Products
            </a>
        </div>
    @endif
</div>
