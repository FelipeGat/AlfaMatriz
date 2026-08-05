@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-status-good']) }}>
        {{ $status }}
    </div>
@endif
