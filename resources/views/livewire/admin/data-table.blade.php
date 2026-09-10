@php
    use Illuminate\Contracts\Support\Htmlable;

    /** $row — a model; returns the display value for a column definition. */
    $cellValue = function (array $column, $row) {
        if (isset($column['render'])) {
            return ($column['render'])($row);
        }

        $value = data_get($row, $column['key']);

        return match ($column['type'] ?? 'text') {
            'money' => money($value),
            'number' => number_format((float) $value, (int) ($column['decimals'] ?? 0), '.', ','),
            'date' => $value ? \Illuminate\Support\Carbon::parse($value)->format($column['format'] ?? 'Y-m-d') : '—',
            default => $value,
        };
    };

    $actions = $definition->toolbar();
    $rowActions = $definition->rowActions();
    $hasToolbar = $definition->searchable() !== [] || $filterDefs !== [] || $actions !== [];
@endphp

{{-- The toolbar lives INSIDE the card header rather than floating above it: on the page
     background the controls read as loose text, and Metronic's own lists put the search,
     filters and "new" action on the card's own surface with a divider under them. --}}
<div class="kt-card">
    @if ($hasToolbar)
        <div class="kt-card-header flex-wrap gap-2.5 py-3">
            <div class="flex flex-wrap items-center gap-2.5">
                @if ($definition->searchable() !== [])
                    <div class="kt-input kt-input-sm w-full sm:w-56">
                        <i class="ki-filled ki-magnifier"></i>
                        <input type="search" wire:model.live.debounce.300ms="search"
                               placeholder="{{ __('t.search_ph') }}" autocomplete="off">
                    </div>
                @endif

                @foreach ($filterDefs as $filter)
                    @if (($filter['type'] ?? 'select') === 'select')
                        <select wire:model.live="filters.{{ $filter['key'] }}" class="kt-select kt-select-sm w-auto"
                                aria-label="{{ $filter['label'] }}">
                            <option value="">{{ $filter['label'] }} — {{ __('t.all') }}</option>
                            @foreach (($filter['options'] ?? []) as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @elseif ($filter['type'] === 'ternary')
                        <select wire:model.live="filters.{{ $filter['key'] }}" class="kt-select kt-select-sm w-auto"
                                aria-label="{{ $filter['label'] }}">
                            <option value="">{{ $filter['label'] }} — {{ __('t.all') }}</option>
                            <option value="1">{{ $filter['true_label'] ?? __('t.yes') }}</option>
                            <option value="0">{{ $filter['false_label'] ?? __('t.no') }}</option>
                        </select>
                    @endif
                @endforeach

                @if ($activeFilters > 0)
                    <button type="button" wire:click="clearFilters" class="kt-btn kt-btn-sm kt-btn-ghost">
                        <i class="ki-filled ki-cross"></i>
                        {{ __('t.clear_filters') }}
                    </button>
                @endif
            </div>

            @if ($actions !== [])
                <div class="kt-card-toolbar">
                    @foreach ($actions as $action)
                        @php $url = isset($action['url']) ? ($action['url'])(null) : null; @endphp
                        <a href="{{ $url }}" @if ($action['new_tab'] ?? false) target="_blank" rel="noopener" @endif
                           @class([
                               'kt-btn kt-btn-sm',
                               'kt-btn-outline' => ($action['variant'] ?? 'primary') !== 'primary',
                           ])>
                            @if (! empty($action['icon'])) <i class="ki-filled {{ $action['icon'] }}"></i> @endif
                            {{ $action['label'] }}
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($rows->isEmpty())
        <div class="kt-card-content flex flex-col items-center gap-2 py-12 text-center">
            <span class="tile tile-muted size-12 text-xl">
                <i class="ki-filled ki-information"></i>
            </span>
            <span class="text-sm font-medium text-mono">{{ $definition->emptyState() }}</span>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="kt-table w-full">
                <thead>
                    <tr class="border-b border-border">
                        @foreach ($columns as $column)
                            @continue ($column['hidden'] ?? false)
                            <th @class([
                                    'px-4 py-3 text-2xs font-semibold uppercase tracking-wide text-muted-foreground whitespace-nowrap',
                                    'text-end' => ($column['align'] ?? 'start') === 'end',
                                    'text-start' => ($column['align'] ?? 'start') !== 'end',
                                    'hidden sm:table-cell' => ($column['on_mobile'] ?? true) === false,
                                ])>
                                @if ($column['sortable'] ?? false)
                                    <button type="button" wire:click="sortBy('{{ $column['key'] }}')"
                                            class="inline-flex items-center gap-1 transition hover:text-mono">
                                        {{ $column['label'] }}
                                        @if ($sort === $column['key'])
                                            <i class="ki-filled {{ $direction === 'asc' ? 'ki-arrow-up' : 'ki-arrow-down' }} text-xs"></i>
                                        @else
                                            <i class="ki-filled ki-arrow-up-down text-xs opacity-40"></i>
                                        @endif
                                    </button>
                                @else
                                    {{ $column['label'] }}
                                @endif
                            </th>
                        @endforeach

                        @if ($rowActions !== [])
                            <th class="px-4 py-3 text-end text-2xs font-semibold uppercase tracking-wide text-muted-foreground">
                                {{ __('t.actions') }}
                            </th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $rowUrl = $definition->rowUrl($row); @endphp
                        <tr wire:key="row-{{ $row->getKey() }}" class="border-b border-border last:border-b-0 transition hover:bg-muted/50">
                            @foreach ($columns as $column)
                                @continue ($column['hidden'] ?? false)
                                @php
                                    $value = $cellValue($column, $row);
                                    $type = $column['type'] ?? 'text';
                                    $align = ($column['align'] ?? 'start') === 'end';
                                @endphp
                                <td @class([
                                        'px-4 py-3 text-sm align-middle',
                                        'text-end' => $align,
                                        'hidden sm:table-cell' => ($column['on_mobile'] ?? true) === false,
                                    ])>
                                    @if ($type === 'badge')
                                        @php $color = isset($column['color']) ? ($column['color'])($row) : ($column['badge'] ?? 'muted'); @endphp
                                        {{-- A badge column is usually an EXCEPTION flag (a broken meter, an
                                             estimate). Emitting the pill for an empty value paints a blank
                                             coloured chip on every ordinary row. --}}
                                        @if (trim((string) $value) !== '')
                                            <span @class([
                                                'kt-badge kt-badge-sm',
                                                'kt-badge-success' => $color === 'success',
                                                'kt-badge-warning kt-badge-outline' => $color === 'warning',
                                                'kt-badge-destructive' => $color === 'danger',
                                                'kt-badge-info kt-badge-outline' => $color === 'info',
                                                'kt-badge-outline' => $color === 'muted',
                                            ])>{{ $value }}</span>
                                        @else
                                            <span class="text-muted-foreground/40">—</span>
                                        @endif
                                    @elseif ($type === 'two-line')
                                        <div class="leading-tight">
                                            <div class="font-medium text-mono">{{ $value }}</div>
                                            @php $sub = isset($column['sub']) ? ($column['sub'])($row) : null; @endphp
                                            @if ($sub)
                                                <div class="mt-0.5 text-xs text-muted-foreground">{{ $sub }}</div>
                                            @endif
                                        </div>
                                    @elseif ($type === 'html')
                                        {!! $value instanceof Htmlable ? $value->toHtml() : $value !!}
                                    @elseif ($type === 'money')
                                        <span class="amount-cell font-medium {{ isset($column['color']) ? tone_text(($column['color'])($row)) : 'text-mono' }}">{{ $value }}</span>
                                    @elseif ($type === 'number')
                                        <span class="amount-cell text-mono">{{ $value }}</span>
                                    @else
                                        @if ($rowUrl && $loop->first)
                                            <a href="{{ $rowUrl }}" class="font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400">
                                                {{ $value }}
                                            </a>
                                        @else
                                            <span class="text-secondary-foreground">{{ $value }}</span>
                                        @endif
                                    @endif
                                </td>
                            @endforeach

                            @if ($rowActions !== [])
                                <td class="px-4 py-3 text-end">
                                    <div class="flex items-center justify-end gap-1">
                                        @foreach ($rowActions as $action)
                                            @if (isset($action['hidden']) && ($action['hidden'])($row))
                                                @continue
                                            @endif

                                            @if (isset($action['action']))
                                                <button type="button" wire:click="runAction('{{ $action['action'] }}', {{ $row->getKey() }})"
                                                        @if (! empty($action['confirm'])) wire:confirm="{{ $action['confirm'] }}" @endif
                                                        class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost kt-btn-destructive"
                                                        title="{{ $action['label'] }}">
                                                    <i class="ki-filled {{ $action['icon'] ?? 'ki-dots-horizontal' }}"></i>
                                                </button>
                                            @elseif (isset($action['method']))
                                                @php
                                                    // The warning is per-row (deleting a subscriber with
                                                    // billing history takes their readings and payments too),
                                                    // so it is resolved here rather than in the definition.
                                                    $confirm = $action['confirm'] ?? null;
                                                    if (($action['method'] ?? '') === 'deleteRecord') {
                                                        $confirm = $definition->deleteWarning($row);
                                                    }
                                                @endphp
                                                <button type="button" wire:click="{{ $action['method'] }}({{ $row->getKey() }})"
                                                        @if ($confirm) wire:confirm="{{ $confirm }}" @endif
                                                        @class([
                                                            'kt-btn kt-btn-icon kt-btn-sm',
                                                            'kt-btn-ghost' => ($action['variant'] ?? 'default') === 'default',
                                                            'kt-btn-ghost kt-btn-destructive' => ($action['variant'] ?? '') === 'danger',
                                                        ])
                                                        title="{{ $action['label'] }}">
                                                    <i class="ki-filled {{ $action['icon'] ?? 'ki-dots-horizontal' }}"></i>
                                                </button>
                                            @else
                                                <a href="{{ ($action['url'])($row) }}"
                                                   @if ($action['new_tab'] ?? false) target="_blank" rel="noopener" @endif
                                                   @class([
                                                       'kt-btn kt-btn-icon kt-btn-sm',
                                                       'kt-btn-ghost' => ($action['variant'] ?? 'default') === 'default',
                                                       'kt-btn-ghost kt-btn-destructive' => ($action['variant'] ?? '') === 'danger',
                                                   ])
                                                   title="{{ $action['label'] }}">
                                                    <i class="ki-filled {{ $action['icon'] ?? 'ki-dots-horizontal' }}"></i>
                                                </a>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($rows->total() > 0)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3">
                <span class="text-xs text-muted-foreground">
                    {{ trans_choice('t.showing_range', $rows->total(), [
                        'from' => $rows->firstItem() ?? 0,
                        'to' => $rows->lastItem() ?? 0,
                        'total' => $rows->total(),
                    ]) }}
                </span>

                @if ($rows->hasPages())
                    <div class="flex items-center gap-1">
                        <button type="button" wire:click="previousPage" @disabled($rows->onFirstPage())
                                class="kt-btn kt-btn-icon kt-btn-sm kt-btn-outline disabled:opacity-40">
                            <i class="ki-filled ki-arrow-left rtl:rotate-180"></i>
                        </button>

                        @foreach ($rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }})"
                                    @class([
                                        'kt-btn kt-btn-sm kt-btn-icon',
                                        'kt-btn-outline' => $page !== $rows->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <button type="button" wire:click="nextPage" @disabled(! $rows->hasMorePages())
                                class="kt-btn kt-btn-icon kt-btn-sm kt-btn-outline disabled:opacity-40">
                            <i class="ki-filled ki-arrow-right rtl:rotate-180"></i>
                        </button>
                    </div>
                @endif
            </div>
        @endif
    @endif
</div>
