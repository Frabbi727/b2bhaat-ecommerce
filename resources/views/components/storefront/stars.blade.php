{{-- A five-star rating drawn as SVG. Usage: <x-storefront.stars :rating="4.2" class="w-4 h-4" /> --}}
@props(['rating' => 0])

@php
    $filledStars = (int) round((float) $rating);
@endphp

<span class="inline-flex items-center gap-0.5" role="img" aria-label="{{ number_format((float) $rating, 1) }} out of 5 stars">
    @for ($star = 1; $star <= 5; $star++)
        <svg {{ $attributes->merge(['class' => 'w-3.5 h-3.5'])->class([$star <= $filledStars ? 'text-amber-400' : 'text-slate-200']) }} viewBox="0 0 20 20" aria-hidden="true">
            <path fill="currentColor" d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
        </svg>
    @endfor
</span>
