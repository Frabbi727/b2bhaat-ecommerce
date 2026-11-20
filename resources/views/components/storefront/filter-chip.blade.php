{{-- A removable "active filter" pill. Pass the Livewire action that clears it as wire:click. --}}
<button
    type="button"
    {{ $attributes->class('inline-flex items-center gap-1.5 h-8 pl-3 pr-2 rounded-full bg-indigo-50 border border-indigo-100 text-indigo-800 font-semibold hover:bg-indigo-100 transition cursor-pointer focus-visible:outline-2 focus-visible:outline-indigo-500') }}
>
    <span class="max-w-48 truncate">{{ $slot }}</span>
    <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    <span class="sr-only">Remove filter</span>
</button>
