@props([
    'product',
    'wishlisted' => false,
    'flash' => false,
    'remainingUnits' => null,
])

@php
    $imageUrl = $product->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=500';
    $price = $product->effective_selling_price;
    $regularPrice = (float) $product->regular_price;
    $isDiscounted = $product->regular_price && $regularPrice > $price;
    $hasOptions = $product->has_variations && $product->variations->count() > 1;
    $sourceName = $product->brand?->name ?? $product->seller?->sellerProfile?->store_name;
    $sellsInBothChannels = $product->relationLoaded('salesChannel') && $product->salesChannel?->slug === 'both';
    $productUrl = route('catalog.show', $product->slug);
@endphp

<article {{ $attributes->class('group relative flex flex-col bg-white rounded-2xl border border-slate-200 p-3 sm:p-4 text-slate-800 transition hover:border-indigo-300 hover:shadow-lg hover:shadow-slate-900/5') }}>
    <div class="relative {{ $flash ? 'aspect-4/3' : 'aspect-square' }} rounded-xl overflow-hidden bg-slate-100">
        <a href="{{ $productUrl }}" tabindex="-1" aria-hidden="true">
            <img src="{{ $imageUrl }}" alt="" class="w-full h-full object-cover transition duration-300 group-hover:scale-[1.03]" loading="lazy"/>
        </a>

        @if ($product->discount_percentage)
            <span class="absolute top-2 left-2 px-2 py-0.5 rounded-md bg-rose-600 text-white text-[11px] font-extrabold tabular-nums">
                -{{ $product->discount_percentage }}%
            </span>
        @endif

        <button
            type="button"
            wire:click="toggleWishlist({{ $product->id }})"
            class="absolute top-2 right-2 grid place-items-center w-8 h-8 rounded-lg bg-white/95 text-slate-500 hover:text-rose-600 shadow-sm transition focus-visible:outline-2 focus-visible:outline-indigo-500"
            aria-label="{{ $wishlisted ? 'Remove from wishlist' : 'Save to wishlist' }}"
            aria-pressed="{{ $wishlisted ? 'true' : 'false' }}"
        >
            <svg class="w-4 h-4 {{ $wishlisted ? 'fill-rose-500 text-rose-500' : 'fill-none' }}" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>
        </button>
    </div>

    <div class="mt-3 flex items-center gap-1.5 min-w-0 h-4 text-[11px] font-bold">
        @if ($sourceName)
            <span class="uppercase tracking-wide text-indigo-600 truncate">{{ $sourceName }}</span>
        @endif
        @if ($sellsInBothChannels)
            <span class="shrink-0 px-1.5 rounded bg-emerald-50 text-emerald-700 text-[10px]">B2B + Retail</span>
        @endif
    </div>

    <h3 class="mt-1 text-sm font-semibold leading-snug text-slate-900 line-clamp-2 min-h-[2lh]">
        <a href="{{ $productUrl }}" class="hover:text-indigo-600 transition focus-visible:outline-2 focus-visible:outline-indigo-500 rounded-sm">{{ $product->title }}</a>
    </h3>

    <div class="mt-auto pt-3">
        <div class="flex flex-wrap items-baseline gap-x-2">
            <span class="text-base sm:text-lg font-extrabold text-slate-900 tabular-nums">৳{{ number_format($price, 0) }}</span>
            @if ($isDiscounted)
                <span class="text-xs text-slate-400 line-through tabular-nums">৳{{ number_format($regularPrice, 0) }}</span>
            @endif
        </div>

        <div class="mt-0.5 h-4 flex items-center gap-1 text-[11px] font-medium min-w-0">
            @if ($flash && $remainingUnits !== null)
                <span class="text-rose-600 font-bold">Only {{ $remainingUnits }} left at this price</span>
            @elseif ($isDiscounted)
                <span class="text-emerald-700 font-semibold tabular-nums">Save ৳{{ number_format($product->savings_amount, 0) }}</span>
            @elseif ($product->warranty_period)
                <svg class="w-3 h-3 shrink-0 text-slate-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                <span class="text-slate-500 truncate" title="{{ $product->warranty_period }}">{{ $product->warranty_period }}</span>
            @endif
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
            <button
                type="button"
                wire:click="openQuickView({{ $product->id }})"
                class="h-9 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition cursor-pointer focus-visible:outline-2 focus-visible:outline-indigo-500"
            >
                <span class="sm:hidden">View</span>
                <span class="hidden sm:inline">Quick view</span>
            </button>
            <button
                type="button"
                wire:click="quickAddToCart({{ $product->id }})"
                wire:loading.attr="disabled"
                wire:target="quickAddToCart({{ $product->id }})"
                class="h-9 rounded-xl text-white text-xs font-bold transition cursor-pointer disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500 {{ $flash ? 'bg-rose-600 hover:bg-rose-700' : 'bg-slate-900 hover:bg-indigo-600' }}"
            >
                <span class="sm:hidden">{{ $hasOptions ? 'Options' : 'Add' }}</span>
                <span class="hidden sm:inline">{{ $hasOptions ? 'Choose options' : 'Add to cart' }}</span>
            </button>
        </div>
    </div>
</article>
