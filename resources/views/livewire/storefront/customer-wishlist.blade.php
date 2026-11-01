<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-6 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2">
                <span class="p-2 rounded-xl bg-rose-50 text-rose-600">
                    <svg class="w-6 h-6 fill-rose-500" viewBox="0 0 24 24">
                        <path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                </span>
                <h1 class="text-2xl font-black text-slate-900 tracking-tight">My Wishlist & Saved Products</h1>
            </div>
            <p class="text-xs text-slate-500 mt-1">Keep track of wholesale inventory, reorder favorites, and factory products you love.</p>
        </div>
        <a href="{{ route('catalog.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white font-bold text-xs shadow-sm transition">
            <span>Continue Shopping</span>
            <span>&rarr;</span>
        </a>
    </div>

    <!-- Feedback Alerts -->
    @if ($feedbackMessage)
        <div class="mt-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-emerald-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span>{{ $feedbackMessage }}</span>
            </div>
            <a href="{{ route('cart.index') }}" class="font-bold underline text-emerald-900 text-xs">View Cart &rarr;</a>
        </div>
    @endif

    @if ($errorMessage)
        <div class="mt-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-2">
            <svg class="w-5 h-5 text-rose-600 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>{{ $errorMessage }}</span>
        </div>
    @endif

    <!-- Wishlist Grid -->
    <div class="mt-8">
        @if ($wishlists->isEmpty())
            <div class="text-center py-20 bg-white rounded-3xl border border-dashed border-slate-300 p-8">
                <div class="w-16 h-16 mx-auto rounded-full bg-rose-50 text-rose-500 flex items-center justify-center mb-4">
                    <svg class="w-8 h-8 fill-rose-400" viewBox="0 0 24 24">
                        <path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                    </svg>
                </div>
                <h3 class="text-base font-bold text-slate-800">Your wishlist is empty</h3>
                <p class="text-xs text-slate-500 max-w-sm mx-auto mt-1 mb-6">Explore the marketplace catalog and click the heart icon on any product to save it here for later.</p>
                <a href="{{ route('catalog.index') }}" class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-sm shadow-md transition">
                    Explore Catalog
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                @foreach ($wishlists as $wishlist)
                    @php
                        $product = $wishlist->product;
                        $imgUrl = $product->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=800';
                        $hasVariations = $product->has_variations && $product->variations->isNotEmpty();
                    @endphp
                    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden shadow-xs hover:shadow-md transition flex flex-col justify-between group">
                        <div>
                            <!-- Image + Remove Button -->
                            <div class="relative aspect-4/3 bg-slate-100 overflow-hidden">
                                <a href="{{ route('catalog.show', $product->slug) }}">
                                    <img src="{{ $imgUrl }}" alt="{{ $product->title }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-300"/>
                                </a>
                                <button
                                    wire:click="removeFromWishlist({{ $wishlist->id }})"
                                    type="button"
                                    class="absolute top-2.5 right-2.5 p-2 rounded-full bg-white/90 backdrop-blur-xs text-rose-500 hover:bg-rose-500 hover:text-white transition shadow-xs"
                                    title="Remove from Wishlist"
                                >
                                    <svg class="w-4 h-4 fill-current" viewBox="0 0 24 24">
                                        <path d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/>
                                    </svg>
                                </button>
                                @if ($product->seller?->sellerProfile?->is_on_vacation)
                                    <span class="absolute bottom-2 left-2 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-500 text-white shadow-xs">
                                        Seller On Vacation
                                    </span>
                                @endif
                            </div>

                            <!-- Details -->
                            <div class="p-4 space-y-2">
                                <div class="flex items-center justify-between text-[11px] text-slate-500">
                                    <span>{{ $product->category?->name ?? 'General' }}</span>
                                    <span class="font-semibold text-indigo-600 truncate max-w-[120px]">
                                        {{ $product->seller?->sellerProfile?->store_name ?? 'Factory' }}
                                    </span>
                                </div>

                                <a href="{{ route('catalog.show', $product->slug) }}" class="block">
                                    <h4 class="font-bold text-sm text-slate-900 group-hover:text-indigo-600 transition line-clamp-2">
                                        {{ $product->title }}
                                    </h4>
                                </a>

                                <!-- Price -->
                                <div class="pt-1">
                                    @if ($product->regular_price && $product->regular_price > $product->effective_selling_price)
                                        <div class="flex items-center gap-1.5 flex-wrap">
                                            <span class="text-base font-extrabold text-slate-900">৳{{ number_format($product->effective_selling_price, 2) }}</span>
                                            <span class="text-xs text-slate-400 line-through">৳{{ number_format($product->regular_price, 2) }}</span>
                                            <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-100 text-rose-700">-{{ $product->discount_percentage }}%</span>
                                        </div>
                                    @elseif ($product->wholesalePrice && (auth()->user()?->isWholesaleBuyer() || auth()->user()?->is_wholesale_approved))
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-base font-extrabold text-indigo-700">৳{{ number_format($product->wholesalePrice->unit_price, 2) }}</span>
                                            <span class="text-[10px] font-bold uppercase text-slate-500">/ wholesale</span>
                                        </div>
                                    @elseif ($product->retailPrice)
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-base font-extrabold text-slate-900">৳{{ number_format($product->retailPrice->unit_price, 2) }}</span>
                                            <span class="text-[10px] text-slate-500">retail</span>
                                        </div>
                                    @elseif ($product->wholesalePrice)
                                        <div class="flex items-baseline gap-1">
                                            <span class="text-base font-extrabold text-indigo-700">৳{{ number_format($product->wholesalePrice->unit_price, 2) }}</span>
                                            <span class="text-[10px] font-bold uppercase text-slate-500">wholesale</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer Actions -->
                        <div class="p-4 pt-0">
                            @if ($hasVariations)
                                <a
                                    href="{{ route('catalog.show', $product->slug) }}"
                                    class="w-full py-2 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-xs flex items-center justify-center gap-1.5 transition"
                                >
                                    <span>Choose Options</span>
                                    <span>&rarr;</span>
                                </a>
                            @else
                                <div class="grid grid-cols-2 gap-2">
                                    <a
                                        href="{{ route('catalog.show', $product->slug) }}"
                                        class="py-2 px-3 rounded-xl border border-slate-200 hover:bg-slate-50 text-slate-700 font-semibold text-xs text-center transition"
                                    >
                                        Details
                                    </a>
                                    <button
                                        wire:click="quickAddToCart({{ $product->id }})"
                                        type="button"
                                        class="py-2 px-3 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs text-center shadow-xs transition"
                                    >
                                        Add to Cart
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
