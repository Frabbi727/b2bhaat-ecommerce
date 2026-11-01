<div class="relative w-full max-w-2xl" x-data="{ focused: false }" @click.outside="$wire.closeDropdown(); focused = false">
    <form wire:submit.prevent="submitSearch" class="relative flex items-center">
        <!-- Category Scope Dropdown -->
        <div class="hidden sm:flex items-center shrink-0 border-r border-slate-200 bg-slate-50 rounded-l-xl">
            <select
                wire:model.live="selectedCategory"
                class="h-11 pl-3 pr-8 text-xs font-semibold text-slate-700 bg-transparent border-0 focus:ring-0 focus:outline-none cursor-pointer"
            >
                <option value="">All Categories</option>
                @foreach ($allCategories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        <!-- Search Input -->
        <div class="relative flex-1">
            <input
                type="text"
                wire:model.live.debounce.250ms="query"
                @focus="focused = true"
                placeholder="Search by product, brand, model, or SKU (e.g. Mini LED, S24, Sony)..."
                class="w-full h-11 pl-4 pr-10 text-sm text-slate-900 placeholder:text-slate-400 bg-white border border-slate-200 sm:border-l-0 rounded-l-xl sm:rounded-l-none focus:outline-none focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 transition"
                autocomplete="off"
            />
            @if (!empty($query))
                <button
                    type="button"
                    wire:click="$set('query', '')"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 p-1"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            @endif
        </div>

        <!-- Submit Button -->
        <button
            type="submit"
            class="h-11 px-5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-r-xl font-semibold text-xs tracking-wide flex items-center gap-1.5 transition shrink-0 shadow-xs"
            aria-label="Search"
        >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
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
                    <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Category Suggestions</div>
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
                                    $img = $item->primaryImage?->image_url ?? 'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=100';
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
    @elseif (empty($query))
        <!-- Popular Searches Quick Bar (visible on focus if query is empty) -->
        <div
            x-show="focused"
            x-cloak
            class="absolute left-0 right-0 top-full mt-2 bg-white rounded-2xl shadow-xl border border-slate-200 p-4 z-50"
            x-transition
        >
            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2.5 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                Popular Searches
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($popularSearches as $popular)
                    <button
                        type="button"
                        wire:click="selectPopular('{{ $popular }}')"
                        class="px-3 py-1 rounded-lg bg-slate-100 hover:bg-indigo-50 hover:text-indigo-600 text-xs text-slate-700 transition"
                    >
                        {{ $popular }}
                    </button>
                @endforeach
            </div>
        </div>
    @endif
</div>
