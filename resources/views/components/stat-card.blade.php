@props(['label', 'value', 'icon' => 'trending-up', 'accent' => 'brand'])

@php
    $accents = [
        'brand' => 'bg-brand/15 text-brand-dim',
        'good' => 'bg-status-good/15 text-status-good',
        'warning' => 'bg-status-warning/15 text-status-warning',
        'critical' => 'bg-status-critical/15 text-status-critical',
        'ink' => 'bg-white/5 text-ink-dim',
    ];

    $paths = [
        'trending-up' => 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941',
        'banknotes' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z',
        'arrow-down-circle' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM8.25 12l3.75 3.75L15.75 12M12 8.25v7.5',
        'arrow-up-circle' => 'M12 21a9 9 0 100-18 9 9 0 000 18zM15.75 12l-3.75-3.75L8.25 12M12 15.75v-7.5',
        'cube-outline' => 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
        'users' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
        'building' => 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    ];
@endphp

<div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
    <div class="flex items-start justify-between">
        <p class="text-xs text-ink-mute uppercase tracking-wide">{{ $label }}</p>
        <span class="h-8 w-8 rounded-lg flex items-center justify-center shrink-0 {{ $accents[$accent] ?? $accents['brand'] }}">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $paths[$icon] ?? $paths['trending-up'] }}" />
            </svg>
        </span>
    </div>
    <p class="mt-2 text-2xl font-display font-semibold text-ink">{{ $value }}</p>
</div>
