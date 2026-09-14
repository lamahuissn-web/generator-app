<?php

namespace App\Admin;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Description of one admin table.
 *
 * Every list screen in the panel — and every tab inside the subscriber profile — is
 * rendered by one Livewire component (App\Livewire\Admin\DataTable) driven by a
 * subclass of this. A screen therefore costs a config file, not a page of markup, and
 * sorting, searching, filtering, pagination, empty states and the row actions behave
 * identically everywhere because there is only one implementation.
 *
 * Column shape (array keys, all optional unless noted):
 *   key         string   required — property path or alias, used by sort/search
 *   label       string   required
 *   type        string   text|money|number|badge|date|html|two-line
 *   sortable    bool     sorts by `sort_by` when that is given, else by `key`
 *   sort_by     string   raw SQL for a sort that is not a plain column
 *   searchable  bool
 *   render      Closure  fn(Model $row): string|Htmlable — wins over `type`
 *   badge       string   colour key for type=badge: success|warning|danger|info|muted
 *   color       Closure  fn(Model $row): string — badge colour per row
 *   sub         Closure  fn(Model $row): ?string — second line, for type=two-line
 *   align       string   start|end
 *   hidden      bool     not rendered (still available to `render` closures)
 *   on_mobile   bool     default true; false hides the column below sm
 *
 * Filter shape:
 *   key      string   required — the query-string / component property name
 *   label    string   required
 *   type     string   select|ternary|date|month
 *   options  array    value => label (select), or resolved by `options_from`
 *   options_from Closure  fn(): array — evaluated per render (e.g. periods in the data)
 *   apply    Closure  fn(Builder $query, mixed $value): void
 *   true/false_label  string  for type=ternary
 *
 * Action shape (toolbar and row):
 *   key      string   required
 *   label    string   required
 *   icon     string   KeenIcon name, e.g. 'ki-pencil'
 *   url      Closure  fn(?Model $row): string — link action
 *   method   string   Livewire method to call on the table (e.g. 'deleteRecord')
 *   confirm  string   confirmation text (opens a modal before `method` runs)
 *   hidden   Closure  fn(Model $row): bool
 *   variant  string   default|primary|danger|success
 */
abstract class TableDefinition
{
    /** Named constructor, mirroring FormDefinition::make(). */
    public static function make(): static
    {
        return new static;
    }

    /** @return class-string<Model> */
    abstract public function model(): string;

    /** Base query, including the eager loads the columns read. */
    abstract public function query(): Builder;

    /** @return array<int, array<string, mixed>> */
    abstract public function columns(): array;

    /** @return array<int, array<string, mixed>> */
    public function filters(): array
    {
        return [];
    }

    /** Columns matched by the toolbar search box. */
    public function searchable(): array
    {
        return [];
    }

    /** @return array{0: string, 1: string} column, direction */
    public function defaultSort(): array
    {
        return ['id', 'desc'];
    }

    public function perPage(): int
    {
        return 25;
    }

    /** Row click target, or null for a table whose rows are not links. */
    public function rowUrl(Model $row): ?string
    {
        return null;
    }

    /** @return array<int, array<string, mixed>> default and bulk actions in the header */
    public function toolbar(): array
    {
        return [];
    }

    /** @return array<int, array<string, mixed>> per-row actions */
    public function rowActions(): array
    {
        return [];
    }

    /**
     * Narrow this table to one billing cycle.
     *
     * A period is a plain column on most tables — but not on every one: a payment carries no
     * cycle, only the bills its money was applied to. The rule therefore lives here (and is
     * overridden where it differs) instead of being hard-coded as a `where()` by every caller,
     * so the page and the table can never disagree about what "this cycle" means.
     */
    public function scopePeriod(EloquentBuilder $query, string $period): void
    {
        $query->where('period', $period);
    }

    public function emptyState(): string
    {
        return __('t.no_results');
    }

    /**
     * Constraints the screen forces on the query, e.g. a profile tab showing only one
     * subscriber's bills. Kept separate from `query()` so the same table class can serve
     * a list page and a nested tab.
     *
     * @return array<string, mixed> column => value
     */
    public function constraints(): array
    {
        return [];
    }

    /**
     * A named action on one row, e.g. reversing a payment or deleting a reading.
     *
     * Table actions go through here rather than straight to a model write so every
     * correction runs the same domain service (and the same guard) the form screens
     * used. Return the flash message; throw to report a refusal.
     *
     * @return array{ok: bool, message: string}
     */
    public function runAction(string $key, Model $row): array
    {
        throw new \RuntimeException(__('t.action_not_allowed'));
    }

    // ---- CSV export -----------------------------------------------------------

    /**
     * Column headings for this list's CSV export, or [] when the list has no export.
     *
     * Returning [] is what removes the export button, so a list that would export something
     * useless (an audit log with a JSON payload) simply does not offer one.
     *
     * @return array<int, string>
     */
    public function csvHeader(): array
    {
        return [];
    }

    /** One CSV line for a row. Must return as many values as csvHeader() has headings. */
    public function csvRow(Model $row): array
    {
        return [];
    }

    /**
     * The download's file name.
     *
     * Screens that export a CYCLE override this to carry the period: two exports of different
     * months must not land in the same file name, because the office keeps both.
     */
    public function csvFilename(): string
    {
        return str(class_basename(static::class))->kebab()->toString().'-'.now()->format('Y-m-d').'.csv';
    }

    /** Runs before a row is deleted through the table. Throwing cancels the delete. */
    public function deleteRecord(Model $row): void
    {
        throw new \RuntimeException(__('t.delete_not_allowed'));
    }

    /**
     * The warning shown in the delete confirmation. Definitions override this so a
     * destructive row action explains its blast radius (a subscriber with billing
     * history takes their readings, bills and payments with them).
     */
    public function deleteWarning(Model $row): string
    {
        return __('t.confirm_delete_record');
    }

    public function canDelete(Model $row): bool
    {
        return false;
    }

    /**
     * WHY this row cannot be deleted, in the operator's words.
     *
     * canDelete() answers yes or no, and a bare "no" tells the operator nothing they can act on —
     * a button that refuses without a reason is the silent failure this project refuses (principle
     * IV). Definitions that refuse deletion say what to do instead; the subscriber case names the
     * status to set. Null falls back to the generic line.
     */
    public function deleteRefusal(Model $row): ?string
    {
        return null;
    }
}
