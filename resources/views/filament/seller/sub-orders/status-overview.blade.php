@php
    $summary = \App\Filament\Seller\Resources\SubOrders\Schemas\SubOrderInfolist::statusSummary($getRecord());
@endphp

<div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
    @include('filament.seller.partials.status-banner', ['summary' => $summary])

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
