@props(['full' => false])
{{-- Shop logo set in admin Store settings; falls back to the bundled mark (or wordmark when full). --}}
<img src="{{ app(\App\Services\Settings\StoreConfigurationService::class)->getLogoUrl($full ? 'images/brand/logo.png' : 'images/brand/logo-mark.png') }}" {{ $attributes->merge(['alt' => app(\App\Services\Settings\StoreConfigurationService::class)->getStoreIdentity()['name']]) }}>
