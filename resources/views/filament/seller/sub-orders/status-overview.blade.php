@php
    $summary = \App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderInfolist::statusSummary($getRecord());
    $tone = [
        'warning' => ['panel' => 'border-warning-200 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10', 'icon' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300', 'heading' => 'text-warning-900 dark:text-warning-100', 'body' => 'text-warning-800 dark:text-warning-200/90'],
        'primary' => ['panel' => 'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10', 'icon' => 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300', 'heading' => 'text-primary-900 dark:text-primary-100', 'body' => 'text-primary-800 dark:text-primary-200/90'],
        'info' => ['panel' => 'border-info-200 bg-info-50 dark:border-info-500/30 dark:bg-info-500/10', 'icon' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-300', 'heading' => 'text-info-900 dark:text-info-100', 'body' => 'text-info-800 dark:text-info-200/90'],
        'success' => ['panel' => 'border-success-200 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10', 'icon' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300', 'heading' => 'text-success-900 dark:text-success-100', 'body' => 'text-success-800 dark:text-success-200/90'],
        'danger' => ['panel' => 'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10', 'icon' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300', 'heading' => 'text-danger-900 dark:text-danger-100', 'body' => 'text-danger-800 dark:text-danger-200/90'],
    ][$summary['tone']];
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div @class(['flex gap-4 border-b p-5 sm:p-6', $tone['panel']])>
        <div @class(['flex size-11 shrink-0 items-center justify-center rounded-full', $tone['icon']])>
            <x-filament::icon :icon="$summary['icon']" class="size-6" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h2 @class(['text-lg font-semibold text-balance', $tone['heading']])>{{ $summary['heading'] }}</h2>
                @unless (str_starts_with($summary['heading'], $summary['status']))
                    <x-filament::badge :color="$summary['statusColor']" size="sm">{{ $summary['status'] }}</x-filament::badge>
                @endunless
            </div>
            <p @class(['mt-1 max-w-3xl text-sm leading-6 text-pretty', $tone['body']])>{{ $summary['body'] }}</p>
        </div>
    </div>

    @if ($summary['steps'] !== [])
        <ol class="grid grid-cols-5 px-2 py-5 sm:px-6" aria-label="Delivery progress">
            @foreach ($summary['steps'] as $step)
                <li class="relative flex flex-col items-center text-center" @if ($step['state'] === 'current') aria-current="step" @endif>
                    @unless ($loop->first)
                        <span aria-hidden="true" @class([
                            'absolute top-3.5 right-1/2 h-0.5 w-full -translate-y-1/2',
                            'bg-primary-500' => $step['state'] === 'done',
                            'bg-gray-200 dark:bg-white/10' => $step['state'] !== 'done',
                        ])></span>
                    @endunless

                    <span @class([
                        'relative z-10 flex size-7 items-center justify-center rounded-full text-xs font-semibold ring-4 ring-white dark:ring-gray-900',
                        'bg-primary-600 text-white' => $step['state'] === 'done',
                        'border-2 border-primary-600 bg-white text-primary-700 dark:bg-gray-900 dark:text-primary-400' => $step['state'] === 'current',
                        'border-2 border-gray-200 bg-white text-gray-400 dark:border-white/15 dark:bg-gray-900 dark:text-gray-500' => $step['state'] === 'upcoming',
                    ])>
                        @if ($step['state'] === 'done')
                            <x-filament::icon icon="heroicon-m-check" class="size-4" />
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>

                    <span @class([
                        'mt-2 text-xs leading-tight sm:text-sm',
                        'font-medium text-gray-950 dark:text-white' => $step['state'] !== 'upcoming',
                        'text-gray-500 dark:text-gray-400' => $step['state'] === 'upcoming',
                    ])>{{ $step['label'] }}</span>

                    @if ($step['at'])
                        <span class="mt-0.5 hidden text-xs text-gray-500 tabular-nums sm:block dark:text-gray-400">{{ $step['at'] }}</span>
                    @elseif ($step['state'] === 'current')
                        <span class="mt-0.5 hidden text-xs font-medium text-primary-700 sm:block dark:text-primary-400">Next</span>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
