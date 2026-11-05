@php
    // Error pages can render while the database is down, so the store details are optional here.
    try {
        $errorPageIdentity = app(\App\Services\Settings\StoreConfigurationService::class)->getStoreIdentity();
    } catch (\Throwable) {
        $errorPageIdentity = ['helpline' => '', 'email' => ''];
    }
@endphp
{{ $intro }}
@if ($errorPageIdentity['helpline'])
    Helpline: <strong class="text-slate-800">{{ $errorPageIdentity['helpline'] }}</strong>
@endif
@if ($errorPageIdentity['email'])
    @if ($errorPageIdentity['helpline']) • @endif Email: <strong class="text-slate-800">{{ $errorPageIdentity['email'] }}</strong>
@endif
@if (! $errorPageIdentity['helpline'] && ! $errorPageIdentity['email'])
    Please try again in a few minutes.
@endif
