<?php

namespace App\Livewire\Admin;

use App\Admin\TableDefinition;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * One table for the whole panel.
 *
 * Renders sortable/searchable/filterable/paginated lists from a TableDefinition. Screens
 * differ only by which definition they pass in, which is what keeps ten list pages and the
 * subscriber-profile tabs visually and behaviourally identical.
 */
class DataTable extends Component
{
    use WithPagination;

    /** @var class-string<TableDefinition> */
    public string $table;

    /** @var array<string, mixed> forced query constraints (profile tabs) */
    public array $constraints = [];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $sort = '';

    #[Url(except: 'asc')]
    public string $direction = 'asc';

    /** @var array<string, mixed> filterKey => value */
    #[Url(except: [])]
    public array $filters = [];

    /**
     * A message about what just happened (a reading deleted, an export that has no columns).
     *
     * Component state rather than a session flash: this table renders INSIDE a page, so a flashed
     * message was written to the session and only ever painted on the next full page load — the
     * operator saw the row disappear with no word about why, and a refused action was silent.
     */
    public ?string $flash = null;

    public string $flashType = 'success';

    public int $perPage = 25;

    public function mount(string $table, array $constraints = []): void
    {
        if (! is_subclass_of($table, TableDefinition::class)) {
            throw new \InvalidArgumentException("{$table} is not a table definition.");
        }

        $this->table = $table;
        $this->constraints = $constraints;

        // A term arriving from the panel's global search lands in the table's own box, so
        // the header search and the on-page filter are one action rather than two.
        if ($this->search === '' && ($term = trim((string) request('q'))) !== '') {
            $this->search = $term;
        }

        $definition = $this->definition();

        if ($this->sort === '') {
            [$this->sort, $this->direction] = $definition->defaultSort();
        }

        $this->perPage = $definition->perPage();
    }

    public function definition(): TableDefinition
    {
        return app($this->table);
    }

    // ---- interactions --------------------------------------------------------

    public function sortBy(string $column): void
    {
        $sortable = collect($this->definition()->columns())
            ->filter(fn (array $c) => ($c['sortable'] ?? false) && ! ($c['hidden'] ?? false))
            ->map(fn (array $c) => $c['key'])
            ->all();

        if (! in_array($column, $sortable, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            $this->direction = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->search = '';
        $this->resetPage();
    }

    /**
     * Delete one row. The confirmation text is rendered by the view from
     * TableDefinition::deleteWarning(), so the operator reads the same warning whether
     * they delete from a list or from a profile tab.
     */
    public function deleteRecord(int $id): void
    {
        $row = $this->baseQuery()->whereKey($id)->first();

        if (! $row || ! $this->definition()->canDelete($row)) {
            $this->flashMessage(__('t.delete_not_allowed'), 'error');

            return;
        }

        try {
            $this->definition()->deleteRecord($row);
            $this->flashMessage(__('t.deleted_ok'));
        } catch (\RuntimeException $e) {
            $this->flashMessage($e->getMessage(), 'error');
        }

        $this->resetPage();
    }

    /** Run a named row action defined by the table (reverse a payment, delete a reading). */
    public function runAction(string $key, int $id): void
    {
        $row = $this->baseQuery()->whereKey($id)->first();

        if (! $row) {
            return;
        }

        try {
            $result = $this->definition()->runAction($key, $row);
            $this->flashMessage($result['message'], $result['ok'] ? 'success' : 'error');
        } catch (\RuntimeException $e) {
            $this->flashMessage($e->getMessage(), 'error');
        }

        $this->resetPage();
    }

    public function dismissFlash(): void
    {
        $this->flash = null;
    }

    /** Every outcome of an action goes through here, so one place decides how it is announced. */
    protected function flashMessage(string $message, string $type = 'success'): void
    {
        $this->flash = $message;
        $this->flashType = $type;
    }

    /**
     * Stream the whole filtered list as CSV.
     *
     * The export runs the SAME query the table renders — search box, every filter and the
     * current sort included — so what the operator downloaded is what they were looking at.
     * Chunked rather than loaded: this is the one place in the panel that reads a list without
     * a page limit, and a year of readings is not a small array.
     */
    public function exportCsv()
    {
        $definition = $this->definition();

        if ($definition->csvHeader() === []) {
            $this->flashMessage(__('t.export_unavailable'), 'error');

            return null;
        }

        // The download happened; say so, because the browser gives no other sign on a phone.
        $this->flashMessage(__('t.exported'));

        return response()->streamDownload(function () use ($definition): void {
            $handle = fopen('php://output', 'w');

            // Excel opens a UTF-8 CSV as garbage without it; every export in this app carries it.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $definition->csvHeader());

            $this->applySort($this->filteredQuery())
                ->chunk(500, function ($rows) use ($handle, $definition): void {
                    foreach ($rows as $row) {
                        fputcsv($handle, $definition->csvRow($row));
                    }
                });

            fclose($handle);
        }, $definition->csvFilename(), ['Content-Type' => 'text/csv']);
    }

    // ---- query ---------------------------------------------------------------

    public function baseQuery(): Builder
    {
        $query = $this->definition()->query();

        foreach ($this->constraints as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }

    public function filteredQuery(): Builder
    {
        $query = $this->baseQuery();
        $definition = $this->definition();

        // Search across the definition's searchable columns; a relation path
        // ("actor.name") becomes a whereHas so the base table is not duplicated.
        $term = trim($this->search);
        if ($term !== '' && $definition->searchable() !== []) {
            $query->where(function (Builder $q) use ($definition, $term): void {
                foreach ($definition->searchable() as $column) {
                    $like = '%'.$term.'%';

                    if (str_contains($column, '.')) {
                        [$relation, $field] = explode('.', $column, 2);
                        $q->orWhereHas($relation, fn (Builder $rel) => $rel->where($field, 'like', $like));
                    } else {
                        $q->orWhere($column, 'like', $like);
                    }
                }
            });
        }

        foreach ($definition->filters() as $filter) {
            $value = $this->filters[$filter['key']] ?? null;

            if ($value === null || $value === '' || $value === [] || ! isset($filter['apply'])) {
                continue;
            }

            ($filter['apply'])($query, $value);
        }

        return $query;
    }

    private function applySort(Builder $query): Builder
    {
        $column = collect($this->definition()->columns())->firstWhere('key', $this->sort);
        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        if ($column === null) {
            return $query->orderBy($this->definition()->model()::make()->getKeyName(), 'desc');
        }

        // A derived figure (a subscriber's outstanding balance) has no column to sort
        // on, so the definition supplies the SQL.
        if (! empty($column['sort_by'])) {
            return $query->orderByRaw($column['sort_by'].' '.$direction);
        }

        $target = str_contains($column['key'], '.') ? $column['key'] : $column['key'];

        return $query->orderBy($target, $direction);
    }

    public function rows(): LengthAwarePaginator
    {
        return $this->applySort($this->filteredQuery())
            ->paginate($this->perPage, ['*'], 'page', $this->getPage());
    }

    /** Filter options resolved per render, so a list can offer the periods actually present. */
    public function resolvedFilters(): array
    {
        return collect($this->definition()->filters())->map(function (array $filter): array {
            if (isset($filter['options_from'])) {
                $filter['options'] = ($filter['options_from'])();
            }

            return $filter;
        })->all();
    }

    public function activeFilterCount(): int
    {
        return collect($this->filters)
            ->reject(fn ($value) => $value === null || $value === '' || $value === [])
            ->count() + (trim($this->search) !== '' ? 1 : 0);
    }

    public function render()
    {
        return view('livewire.admin.data-table', [
            'definition' => $this->definition(),
            'rows' => $this->rows(),
            'columns' => $this->definition()->columns(),
            // NOT `filters`: Livewire also exposes every public property to the view, so a
            // variable sharing the property's name is shadowed by the property's VALUE — the
            // filter array arrives as the raw ['status' => '0'] state and every `$filter['key']`
            // indexes a string. The filter dropdowns then silently never render.
            'filterDefs' => $this->resolvedFilters(),
            'activeFilters' => $this->activeFilterCount(),
        ]);
    }

    /** Escapes anything a column closure returns that is not already HTML. */
    public static function toHtml(mixed $value): \Illuminate\Support\HtmlString
    {
        return new \Illuminate\Support\HtmlString(
            $value instanceof \Illuminate\Contracts\Support\Htmlable ? $value->toHtml() : e((string) $value)
        );
    }

    protected function classBasename(): string
    {
        return Str::kebab(class_basename($this->table));
    }
}
