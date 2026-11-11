{{-- The dark campaign band used by the flash sale pages. Pass a heading level with :as (h1 on a sale page, h2 in the list). --}}
@props(['sale', 'as' => 'h2', 'link' => null, 'linkLabel' => null])

<div {{ $attributes->class('rounded-3xl bg-gradient-to-br from-rose-950 via-slate-900 to-indigo-950 p-5 sm:p-7 text-white shadow-xl shadow-slate-900/10') }}>
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-start gap-3 min-w-0">
            <span class="grid place-items-center w-10 h-10 shrink-0 rounded-xl bg-rose-600 text-white shadow-md shadow-rose-950/40">
                <x-storefront.icon name="bolt" class="w-5 h-5" />
            </span>
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <{{ $as }} class="text-xl sm:text-2xl font-black tracking-tight text-white text-balance">{{ $sale->name }}</{{ $as }}>
                    <span class="px-2 py-0.5 rounded-full bg-rose-500/25 border border-rose-400/40 text-rose-200 text-[10px] font-bold uppercase tracking-wider">Live now</span>
                </div>
                @if ($sale->description)
                    <p class="mt-1 text-xs sm:text-sm text-rose-100/80">{{ $sale->description }}</p>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 shrink-0">
            <x-storefront.flash-countdown :ends-at="$sale->ends_at" label="Ends in:" />
            @if ($link)
                <a href="{{ $link }}" class="text-sm font-bold text-rose-200 hover:text-white underline underline-offset-4">{{ $linkLabel }} &rarr;</a>
            @endif
        </div>
    </div>
</div>
