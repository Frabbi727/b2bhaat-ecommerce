{{-- The coloured "where things stand and what to do next" banner at the top of a seller record page.
     $summary: tone, icon, heading, body, status, statusColor, optional points (label => text). --}}
@php
    $tone = [
        'warning' => ['panel' => 'border-warning-200 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10', 'icon' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-300', 'heading' => 'text-warning-900 dark:text-warning-100', 'body' => 'text-warning-800 dark:text-warning-200/90'],
        'primary' => ['panel' => 'border-primary-200 bg-primary-50 dark:border-primary-500/30 dark:bg-primary-500/10', 'icon' => 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-300', 'heading' => 'text-primary-900 dark:text-primary-100', 'body' => 'text-primary-800 dark:text-primary-200/90'],
        'info' => ['panel' => 'border-info-200 bg-info-50 dark:border-info-500/30 dark:bg-info-500/10', 'icon' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-300', 'heading' => 'text-info-900 dark:text-info-100', 'body' => 'text-info-800 dark:text-info-200/90'],
        'success' => ['panel' => 'border-success-200 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10', 'icon' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-300', 'heading' => 'text-success-900 dark:text-success-100', 'body' => 'text-success-800 dark:text-success-200/90'],
        'danger' => ['panel' => 'border-danger-200 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10', 'icon' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-300', 'heading' => 'text-danger-900 dark:text-danger-100', 'body' => 'text-danger-800 dark:text-danger-200/90'],
    ][$summary['tone']];
@endphp

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
        @if (! empty($summary['points']))
            <dl class="mt-3 grid max-w-3xl gap-2 text-sm leading-6 sm:grid-cols-2 sm:gap-4">
                @foreach ($summary['points'] as $label => $text)
                    <div>
                        <dt @class(['font-semibold', $tone['heading']])>{{ $label }}</dt>
                        <dd @class(['text-pretty', $tone['body']])>{{ $text }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif
    </div>
</div>

