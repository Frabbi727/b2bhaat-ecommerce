<a href="{{ route('cart.index') }}" class="relative inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border border-slate-200 hover:border-slate-300 bg-white hover:bg-slate-50 transition shadow-xs text-sm font-medium text-slate-700">
    <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
    </svg>
    <span>Cart</span>
    @if ($count > 0)
        <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold leading-none text-white bg-indigo-600 rounded-full">
            {{ $count }}
        </span>
        <span class="hidden sm:inline text-xs font-semibold text-slate-500">
            ৳{{ number_format($subtotal, 2) }}
        </span>
    @else
        <span class="inline-flex items-center justify-center px-1.5 py-0.5 text-xs font-semibold text-slate-400 bg-slate-100 rounded-full">
            0
        </span>
    @endif
</a>
