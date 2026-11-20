<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
    <nav class="flex items-center gap-2 text-xs text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('catalog.index') }}" class="hover:text-indigo-600">Home</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('flash-sales.index') }}" class="hover:text-indigo-600">Flash Sales</a>
        <span aria-hidden="true">/</span>
        <span class="text-slate-800 font-semibold">{{ $sale->name }}</span>
    </nav>

    @if ($effectiveStatus === \App\Enums\FlashSaleStatus::Ended || $effectiveStatus === \App\Enums\FlashSaleStatus::Paused)
        <div class="rounded-3xl bg-amber-50 border border-amber-200 p-8 text-center">
            <span class="grid place-items-center w-12 h-12 mx-auto rounded-2xl bg-amber-100 text-amber-700">
                <x-storefront.icon name="calendar" class="w-6 h-6" />
            </span>
            <h1 class="mt-4 text-xl font-bold text-slate-900">This flash sale has ended</h1>
            <p class="mt-1 text-sm text-slate-600">The promotional pricing for {{ $sale->name }} is no longer active.</p>
            <a href="{{ route('flash-sales.index') }}" class="inline-flex items-center mt-5 h-10 px-5 rounded-xl bg-slate-900 text-white font-bold text-sm hover:bg-indigo-600 transition">
                View Other Flash Deals
            </a>
        </div>
    @elseif ($effectiveStatus === \App\Enums\FlashSaleStatus::Scheduled)
        <div class="rounded-3xl bg-slate-900 p-5 sm:p-7 text-white border border-slate-800">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <span class="px-2.5 py-1 rounded-md bg-indigo-600/30 text-indigo-100 border border-indigo-500/40 text-[11px] font-bold uppercase tracking-wider">
                        Upcoming Event
                    </span>
                    <h1 class="text-2xl sm:text-3xl font-black mt-2 text-white">{{ $sale->name }}</h1>
                    @if ($sale->description)
                        <p class="text-sm text-slate-300 mt-1">{{ $sale->description }}</p>
                    @endif
                </div>
                <x-storefront.flash-countdown :starts-at="$sale->starts_at" label="Starts in:" />
            </div>
        </div>

        @if ($products->isNotEmpty())
            <section class="space-y-4" aria-labelledby="upcoming-products-heading">
                <h2 id="upcoming-products-heading" class="text-lg sm:text-xl font-extrabold tracking-tight text-slate-900">Featured in this Upcoming Sale</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                    @foreach ($products as $product)
                        <x-storefront.product-card :product="$product" :actions="false" wire:key="upcoming-{{ $product->id }}" />
                    @endforeach
                </div>
            </section>
        @endif
    @else
        <x-storefront.flash-campaign-header :sale="$sale" as="h1" />

        @if ($products->isNotEmpty())
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
                @foreach ($products as $product)
                    <x-storefront.product-card
                        :product="$product"
                        :actions="false"
                        :flash="true"
                        :remaining-units="$dealStock[$product->id]['remaining'] ?? null"
                        :stock-limit="$dealStock[$product->id]['limit'] ?? null"
                        wire:key="deal-{{ $product->id }}"
                    />
                @endforeach
            </div>
        @else
            <p class="text-sm text-slate-500">Deals for this campaign are being approved. Check back shortly.</p>
        @endif
    @endif
</div>
