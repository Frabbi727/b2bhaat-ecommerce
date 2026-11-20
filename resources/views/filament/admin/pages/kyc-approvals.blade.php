<x-filament-panels::page>
    <x-filament::tabs label="Application type">
        @foreach ($this->getTabs() as $key => $tab)
            <x-filament::tabs.item
                :active="$activeTab === $key"
                :badge="$tab['pending'] ?: null"
                badge-color="warning"
                wire:click="$set('activeTab', '{{ $key }}')"
            >
                {{ $tab['label'] }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
