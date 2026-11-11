{{-- Admin & Seller panel logo: the B2B Haat mark on a white tile (readable in light and dark mode) beside the panel name. --}}
<span style="display: inline-flex; align-items: center; gap: 0.625rem; height: 100%; white-space: nowrap;">
    <span style="display: inline-flex; align-items: center; justify-content: center; height: 100%; aspect-ratio: 1; padding: 2px; border-radius: 0.5rem; background: #ffffff; box-shadow: 0 0 0 1px rgb(0 0 0 / 0.06);">
        <x-brand.logo alt="" style="height: 100%; width: 100%; object-fit: contain;" />
    </span>
    <span>{{ filament()->getBrandName() }}</span>
</span>
