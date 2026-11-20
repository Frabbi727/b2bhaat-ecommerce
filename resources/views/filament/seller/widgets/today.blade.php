@php
    $toneClasses = [
        'danger' => ['row' => 'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10', 'icon' => 'text-danger-600 dark:text-danger-400', 'title' => 'text-danger-900 dark:text-danger-100', 'body' => 'text-danger-800 dark:text-danger-200/90', 'tile' => 'bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400'],
        'warning' => ['row' => 'border-warning-200 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10', 'icon' => 'text-warning-600 dark:text-warning-400', 'title' => 'text-warning-900 dark:text-warning-100', 'body' => 'text-warning-800 dark:text-warning-200/90', 'tile' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/10 dark:text-warning-400'],
        'info' => ['row' => 'border-info-200 bg-info-50 dark:border-info-500/30 dark:bg-info-500/10', 'icon' => 'text-info-600 dark:text-info-400', 'title' => 'text-info-900 dark:text-info-100', 'body' => 'text-info-800 dark:text-info-200/90', 'tile' => 'bg-info-50 text-info-600 dark:bg-info-500/10 dark:text-info-400'],
        'primary' => ['row' => '', 'icon' => '', 'title' => '', 'body' => '', 'tile' => 'bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-400'],
    ];
@endphp

<x-filament-widgets::widget>
    <div class="space-y-4" @if ($pollingInterval = $this->getPollingInterval()) wire:poll.{{ $pollingInterval }} @endif>
        @foreach ($alerts as $alert)
            @php $tone = $toneClasses[$alert['tone']]; @endphp
            <div @class(['flex items-start gap-3 rounded-xl border p-4', $tone['row']]) role="status">
                <x-filament::icon :icon="$alert['icon']" @class(['mt-0.5 size-5 shrink-0', $tone['icon']]) />
                <div class="min-w-0 flex-1">
                    <p @class(['text-sm font-semibold', $tone['title']])>{{ $alert['title'] }}</p>
                    <p @class(['mt-0.5 text-sm', $tone['body']])>{{ $alert['body'] }}</p>
                </div>
                @if ($alert['url'])
                    <a href="{{ $alert['url'] }}" @class(['shrink-0 self-center text-sm font-semibold underline underline-offset-4', $tone['title']])>{{ $alert['link'] }}</a>
                @endif
            </div>
        @endforeach

        <div class="grid gap-4 lg:grid-cols-3 lg:items-start">
            {{-- What needs the seller --}}
            <section class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm lg:col-span-2 dark:border-white/10 dark:bg-gray-900" aria-labelledby="today-heading">
                <header class="flex items-baseline justify-between gap-3 border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <h2 id="today-heading" class="text-base font-semibold text-gray-950 dark:text-white">To do today</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ now(\Filament\Support\Facades\FilamentTimezone::get())->format('l, d M') }}</p>
                </header>

                @if ($todos === [])
                    <div class="flex items-center gap-3 px-5 py-8">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400">
                            <x-filament::icon icon="heroicon-o-check" class="size-5" />
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-gray-950 dark:text-white">All caught up</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing needs you right now. New orders and returns show up here.</p>
                        </div>
                    </div>
                @else
                    <ul class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach ($todos as $todo)
                            <li>
                                <a href="{{ $todo['url'] }}" class="group flex min-h-16 items-center gap-4 px-5 py-3 transition hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/5 dark:focus-visible:bg-white/5">
                                    <span @class(['flex size-10 shrink-0 items-center justify-center rounded-lg', $toneClasses[$todo['tone']]['tile']])>
                                        <x-filament::icon :icon="$todo['icon']" class="size-5" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-950 dark:text-white">
                                            <span class="text-lg font-semibold tabular-nums">{{ number_format($todo['count']) }}</span>
                                            {{ $todo['label'] }}
                                        </span>
                                        @if ($todo['hint'] !== '')
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $todo['hint'] }}</span>
                                        @endif
                                    </span>
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="size-5 shrink-0 text-gray-400 transition group-hover:translate-x-0.5 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Money at a glance --}}
            <section class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900" aria-labelledby="money-heading">
                <header class="border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <h2 id="money-heading" class="text-base font-semibold text-gray-950 dark:text-white">Your money</h2>
                </header>
                <div class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach ($money as $item)
                        <div class="px-5 py-3.5">
                            <a href="{{ $item['url'] }}" class="group flex items-baseline justify-between gap-3 focus-visible:outline-none">
                                <span class="text-sm text-gray-600 group-hover:text-gray-950 group-focus-visible:underline dark:text-gray-400 dark:group-hover:text-white">{{ $item['label'] }}</span>
                                <span @class([
                                    'text-base font-semibold tabular-nums',
                                    'text-success-700 dark:text-success-400' => $loop->first,
                                    'text-gray-950 dark:text-white' => ! $loop->first,
                                ])>{{ $item['value'] }}</span>
                            </a>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $item['hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </section>
        </div>
    </div>
</x-filament-widgets::widget>
