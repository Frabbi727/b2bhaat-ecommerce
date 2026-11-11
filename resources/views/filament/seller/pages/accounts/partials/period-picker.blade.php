@php
    $presets = ['month' => 'This month', 'last_month' => 'Last month', 'quarter' => 'This quarter', 'year' => 'This year', 'all' => 'All time'];
@endphp

<div class="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3 shadow-sm sm:flex-row sm:items-end sm:justify-between dark:border-white/10 dark:bg-gray-900 print:hidden">
    <div class="flex flex-wrap gap-1" role="group" aria-label="Period">
        @foreach ($presets as $key => $label)
            <button
                type="button"
                wire:click="setPreset('{{ $key }}')"
                @class([
                    'rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors',
                    'bg-primary-50 font-semibold text-primary-700 dark:bg-primary-950/60 dark:text-primary-300' => $this->preset === $key,
                    'text-gray-600 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5' => $this->preset !== $key,
                ])
            >{{ $label }}</button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-2 sm:w-80">
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">From</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="startDate" />
            </x-filament::input.wrapper>
        </label>
        <label class="block">
            <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">To</span>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="endDate" />
            </x-filament::input.wrapper>
        </label>
    </div>
</div>
