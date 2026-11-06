@props([
    'endsAt' => null,
    'startsAt' => null,
    'label' => null,
])

@php
    $target = $endsAt ?? $startsAt;
    if ($target instanceof \Carbon\CarbonInterface) {
        $targetMs = $target->getTimestampMs();
    } elseif ($target) {
        $targetMs = \Illuminate\Support\Carbon::parse($target)->getTimestampMs();
    } else {
        $targetMs = null;
    }
    $serverNowMs = now()->getTimestampMs();
    $displayLabel = $label ?? ($endsAt ? 'Ends in:' : 'Starts in:');
@endphp

@if ($targetMs)
<div
    data-target-at="{{ $targetMs }}"
    @if ($endsAt) data-ends-at="{{ $targetMs }}" @endif
    @if ($startsAt) data-starts-at="{{ $targetMs }}" @endif
    data-server-now="{{ $serverNowMs }}"
    x-data="{
        target: {{ $targetMs }},
        serverNow: {{ $serverNowMs }},
        offset: 0,
        days: 0,
        hours: 0,
        minutes: 0,
        seconds: 0,
        refreshed: false,
        init() {
            this.offset = this.serverNow - Date.now();
            this.tick();
            setInterval(() => this.tick(), 1000);
        },
        tick() {
            const now = Date.now() + this.offset;
            const diffSeconds = Math.max(0, Math.floor((this.target - now) / 1000));
            this.days = Math.floor(diffSeconds / 86400);
            this.hours = Math.floor((diffSeconds % 86400) / 3600);
            this.minutes = Math.floor((diffSeconds % 3600) / 60);
            this.seconds = diffSeconds % 60;

            if (diffSeconds === 0 && !this.refreshed) {
                this.refreshed = true;
                if (typeof $wire !== 'undefined' && $wire.$refresh) {
                    $wire.$refresh();
                }
            }
        }
    }"
    class="flex items-center gap-2 self-start sm:self-auto bg-slate-950/60 px-4 py-2 rounded-2xl border border-rose-800/40 text-white"
>
    <span class="text-xs text-rose-200 font-semibold mr-1">{{ $displayLabel }}</span>
    <template x-if="days > 0">
        <div class="flex items-center gap-1 font-mono font-black text-xs text-white">
            <span class="px-2 py-1 rounded-lg bg-rose-600" x-text="days + 'd'"></span>
        </div>
    </template>
    <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(hours).padStart(2, '0')">00</div>
    <span class="font-bold text-rose-400">:</span>
    <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(minutes).padStart(2, '0')">00</div>
    <span class="font-bold text-rose-400">:</span>
    <div class="w-8 h-8 rounded-lg bg-rose-600 font-mono font-black text-xs flex items-center justify-center text-white" x-text="String(seconds).padStart(2, '0')">00</div>
</div>
@endif
