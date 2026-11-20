<div class="flex flex-col gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm sm:flex-row sm:items-end sm:justify-between dark:border-white/10 dark:bg-gray-900 print:hidden">
    <p class="text-sm text-gray-600 dark:text-gray-400">Balances at the end of the chosen day.</p>
    <label class="block sm:w-48">
        <span class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">As of</span>
        <x-filament::input.wrapper>
            <x-filament::input type="date" wire:model.live="asOf" />
        </x-filament::input.wrapper>
    </label>
</div>
