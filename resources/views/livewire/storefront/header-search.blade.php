<div class="relative w-full max-w-2xl" x-data="{ focused: false }" @click.outside="$wire.closeDropdown(); focused = false">
    <form wire:submit.prevent="submitSearch" role="search" class="relative flex items-center">
        <label for="header-search-input" class="sr-only">Search products</label>
        <div class="relative flex-1">
            <svg class="absolute left-3.5 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
            <input
                id="header-search-input"
                type="search"
                wire:model.live.debounce.250ms="query"
                @focus="focused = true"
                @keydown.escape="$wire.closeDropdown(); focused = false; $el.blur()"
                placeholder="Search products, brands or model numbers"
                enterkeyhint="search"
                class="w-full h-11 pl-11 pr-10 text-sm text-slate-900 placeholder:text-slate-400 bg-slate-100 border border-transparent rounded-l-xl hover:bg-slate-50 hover:border-slate-200 focus:bg-white focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 transition [&::-webkit-search-cancel-button]:hidden"
                autocomplete="off"
            />
            @if (!empty($query))
                <button
                    type="button"
                    wire:click="$set('query', '')"
                    class="absolute right-2 top-1/2 -translate-y-1/2 grid place-items-center w-7 h-7 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-200/60 cursor-pointer"
                    aria-label="Clear search"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            @endif
        </div>

        <button
            type="submit"
            class="h-11 px-4 md:px-5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-r-xl font-semibold text-sm flex items-center gap-2 transition shrink-0 cursor-pointer focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-500"
            aria-label="Search"
        >
            <svg class="w-4 h-4 md:hidden" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 11-13 0 6.5 6.5 0 0113 0z"/></svg>
            <span class="hidden md:inline">Search</span>
        </button>
    </form>

    <!-- Autocomplete Dropdown Panel -->
    @if ($isOpen)
        <div
            class="absolute left-0 right-0 top-full mt-2 bg-white rounded-2xl shadow-2xl border border-slate-200 overflow-hidden z-50 divide-y divide-slate-100"
            x-transition
        >
            <!-- Category matches if any -->
            @if ($categoryResults->isNotEmpty())
                <div class="p-3 bg-slate-50/80">
                    <div class="text-xs font-semibold text-slate-500 mb-2">Categories</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($categoryResults as $c)
                            <a
                                href="{{ route('catalog.index', ['category' => $c->id]) }}"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-white border border-slate-200 text-xs font-medium text-slate-700 hover:border-indigo-500 hover:text-indigo-600 transition"
                            >
                                <span>{{ $c->name }}</span>
                                <span class="text-slate-400">&rarr;</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Products matching query -->
            <div class="max-h-96 overflow-y-auto divide-y divide-slate-100">
                @forelse ($results as $item)
                    <a
                        href="{{ route('catalog.show', $item->slug) }}"
                        class="p-3 flex items-center justify-between gap-4 hover:bg-slate-50 transition group"
                    >
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-12 h-12 rounded-xl bg-slate-100 border border-slate-200 overflow-hidden shrink-0 aspect-square">
                                @php
                                    $img = $item->primaryImage?->public_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=100';
                                @endphp
                                <img src="{{ $img }}" alt="{{ $item->title }}" class="w-full h-full object-cover group-hover:scale-105 transition" loading="lazy"/>
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs font-semibold text-slate-900 truncate group-hover:text-indigo-600 transition">
                                    {{ $item->title }}
                                </div>
                                <div class="flex items-center gap-2 mt-0.5 text-[11px] text-slate-500">
                                    @if ($item->brand)
                                        <span class="font-medium text-indigo-600">{{ $item->brand->name }}</span>
                                        <span>•</span>
                                    @endif
                                    @if ($item->model_number)
                                        <span class="font-mono">Mod: {{ $item->model_number }}</span>
                                    @elseif ($item->sku)
                                        <span class="font-mono">SKU: {{ $item->sku }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Price & Discount -->
                        <div class="text-right shrink-0">
                            <div class="text-sm font-bold text-slate-900">
                                ৳{{ number_format($item->effective_selling_price, 0) }}
                            </div>
                            @if ($item->discount_percentage)
                                <div class="flex items-center justify-end gap-1.5 mt-0.5">
                                    <span class="text-[11px] text-slate-400 line-through">৳{{ number_format((float) $item->regular_price, 0) }}</span>
                                    <span class="px-1.5 py-0.2 rounded text-[10px] font-bold bg-rose-100 text-rose-700">
                                        -{{ $item->discount_percentage }}%
                                    </span>
                                </div>
                            @endif
                        </div>
                    </a>
                @empty
                    <div class="p-6 text-center text-xs text-slate-500">
                        No products found matching "<strong class="text-slate-800">{{ $query }}</strong>".
                        <div class="mt-2">
                            <a href="{{ route('catalog.index', ['search' => $query]) }}" class="text-indigo-600 font-semibold hover:underline">
                                View all catalog results &rarr;
                            </a>
                        </div>
                    </div>
                @endforelse
            </div>

            @if ($results->isNotEmpty())
                <div class="p-2.5 bg-slate-50 text-center">
                    <button
                        type="button"
                        wire:click="submitSearch"
                        class="text-xs font-bold text-indigo-600 hover:text-indigo-800 hover:underline"
                    >
                        View all results for "{{ $query }}" &rarr;
                    </button>
                </div>
            @endif
        </div>
    @elseif (empty($query) && $allCategories->isNotEmpty())
        <!-- On focus with an empty box: jump straight into a category -->
        <div
            x-show="focused"
            x-cloak
            class="absolute left-0 right-0 top-full mt-2 bg-white rounded-2xl shadow-xl shadow-slate-900/10 border border-slate-200 p-4 z-50"
            x-transition
        >
            <div class="text-xs font-semibold text-slate-500 mb-2.5">Shop by category</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($allCategories as $shortcutCategory)
                    <a
                        href="{{ route('catalog.index', ['category' => $shortcutCategory->id]) }}"
                        class="inline-flex items-center h-8 px-3 rounded-full bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 text-xs font-medium text-slate-700 transition"
                    >
                        {{ $shortcutCategory->name }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
