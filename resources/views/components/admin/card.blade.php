@props([
    'title' => null,
    'description' => null,
    // Rendered at the inline-end of the header (buttons, links).
    'actions' => null,
    // Optical weight: a plain card, or one that carries a figure (see stat).
    'flush' => false,
    // Extra classes for the content box — a field grid needs its own columns.
    'contentClass' => null,
])

<div {{ $attributes->class(['kt-card']) }}>
    @if ($title || isset($actions))
        <div class="kt-card-header">
            @if ($title)
                <div class="kt-card-heading">
                    <h3 class="kt-card-title">{{ $title }}</h3>
                    @if ($description)
                        <span class="kt-card-description">{{ $description }}</span>
                    @endif
                </div>
            @endif

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @elseif ($description)
        <div class="kt-card-header">
            <span class="kt-card-description">{{ $description }}</span>
        </div>
    @endif

    <div @class(['kt-card-content', 'grid gap-4' => ! $flush, $contentClass])>
        {{ $slot }}
    </div>
</div>
