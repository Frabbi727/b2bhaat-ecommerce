<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    @include('filament.seller.partials.status-banner', [
        'summary' => \App\Filament\Seller\Resources\ReturnRequests\Schemas\ReturnRequestInfolist::decisionSummary($getRecord()),
    ])
</div>
