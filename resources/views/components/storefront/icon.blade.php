{{--
    One stroke-style icon set for the storefront, so pages stop leaning on emoji.
    Usage: <x-storefront.icon name="shield" class="w-4 h-4 text-emerald-600" />
--}}
@props(['name'])

@php
    $paths = [
        'bolt' => '<path d="M13 2L4.5 13.5H11L10 22l8.5-11.5H12L13 2z" fill="currentColor" stroke="none"/>',
        'shield' => '<path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
        'truck' => '<path d="M3 7h11v9H3zM14 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.75"/><circle cx="17" cy="17.5" r="1.75"/>',
        'check' => '<path d="M5 13l4 4L19 7"/>',
        'map-pin' => '<path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'calendar' => '<path d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/>',
        'tag' => '<path d="M7 7h.01M3 11V5a2 2 0 012-2h6l9.586 9.586a2 2 0 010 2.828l-6.172 6.172a2 2 0 01-2.828 0L3 11z"/>',
        'box' => '<path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'w-4 h-4 shrink-0']) }} fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true">{!! $paths[$name] ?? '' !!}</svg>
