@props([
    'label',
    'value',
    // Semantic tone from tone_text(): default | success | warning | danger | info.
    'tone' => 'default',
    'hint' => null,
    'icon' => null,
])

{{-- A single figure with its own caption. The value uses `amount-cell` so money keeps its
     Latin digits and LTR direction inside the Arabic layout. --}}
<div class="kt-card">
    <div class="kt-card-content flex flex-col gap-1">
        <div class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-widest text-muted-foreground">
            @if ($icon)
                <i class="ki-filled {{ $icon }}"></i>
            @endif
            {{ $label }}
        </div>

        <span @class(['amount-cell text-2xl font-semibold', tone_text($tone)])>{{ $value }}</span>

        @if ($hint)
            <span class="text-xs text-muted-foreground">{{ $hint }}</span>
        @endif
    </div>
</div>
