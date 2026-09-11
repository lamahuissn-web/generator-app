@props([
    // [['label' => '…', 'align' => 'end', 'on_mobile' => false], …]
    'columns',
    // Rendered when there are no rows; the caller decides the wording.
    'empty' => null,
    'rowCount' => null,
    // A section heading, when this table is one of several groupings on the screen.
    'title' => null,
    'description' => null,
    // Inline-end slot for a button tied to this table.
    'actions' => null,
])

@php
    $columns = collect($columns);
    $isEmpty = $rowCount !== null && $rowCount === 0;
@endphp

<div class="kt-card">
    @if ($title)
        {{-- Titled tables are one grouping among several; an untitled one IS the screen's
             content, and a heading there would just repeat the page title. --}}
        <div class="kt-card-header">
            <div class="kt-card-heading">
                <h3 class="kt-card-title">{{ $title }}</h3>
                @if ($description)
                    <span class="kt-card-description">{{ $description }}</span>
                @endif
            </div>

            @isset($actions)
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    @if ($isEmpty)
        <div class="kt-card-content flex flex-col items-center gap-2 py-12 text-center">
            <span class="tile tile-muted size-12 text-xl">
                <i class="ki-filled ki-information"></i>
            </span>
            <span class="text-sm font-medium text-mono">{{ $empty ?? __('t.no_results') }}</span>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="kt-table w-full">
                <thead>
                    <tr class="border-b border-border">
                        @foreach ($columns as $column)
                            <th @class([
                                    'px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap',
                                    'text-end' => ($column['align'] ?? 'start') === 'end',
                                    'text-start' => ($column['align'] ?? 'start') !== 'end',
                                    'hidden sm:table-cell' => ($column['on_mobile'] ?? true) === false,
                                ])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    {{ $slot }}
                </tbody>
            </table>
        </div>
    @endif
</div>
