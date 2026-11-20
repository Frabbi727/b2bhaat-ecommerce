@php
    $record = $getRecord();
    $logs = $record->trackingLogs;
    $local = fn ($moment) => \App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderInfolist::localTime($moment);
@endphp

<ol class="space-y-0">
    @foreach ($logs as $log)
        <li class="relative flex gap-3 pb-5">
            <span aria-hidden="true" class="absolute top-4 left-[5px] h-full w-px bg-gray-200 dark:bg-white/10"></span>
            <span aria-hidden="true" @class([
                'relative mt-1.5 size-[11px] shrink-0 rounded-full ring-2 ring-white dark:ring-gray-900',
                'bg-primary-500' => $loop->first,
                'bg-gray-300 dark:bg-gray-600' => ! $loop->first,
            ])></span>
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $log->title ?: $log->fulfillmentStatus?->name }}</p>
                    <time class="text-xs text-gray-500 tabular-nums dark:text-gray-400" datetime="{{ $log->logged_at?->toIso8601String() }}">{{ $log->logged_at ? $local($log->logged_at)->format('d M Y, g:i A') : '' }}</time>
                </div>
                @if ($log->description || $log->hub_location)
                    <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">
                        {{ $log->description }}@if ($log->description && $log->hub_location) · @endif{{ $log->hub_location }}
                    </p>
                @endif
            </div>
        </li>
    @endforeach

    <li class="relative flex gap-3">
        <span aria-hidden="true" class="relative mt-1.5 size-[11px] shrink-0 rounded-full bg-gray-300 ring-2 ring-white dark:bg-gray-600 dark:ring-gray-900"></span>
        <div class="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-3">
            <p class="text-sm font-medium text-gray-950 dark:text-white">Order received</p>
            <time class="text-xs text-gray-500 tabular-nums dark:text-gray-400" datetime="{{ $record->created_at?->toIso8601String() }}">{{ $record->created_at ? $local($record->created_at)->format('d M Y, g:i A') : '' }}</time>
        </div>
    </li>
</ol>
