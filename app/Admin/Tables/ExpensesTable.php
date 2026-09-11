<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\Expense;
use App\Models\User;
use App\Services\ExpenseService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Operating expenses: fuel, oil, repairs, salaries, rent. */
class ExpensesTable extends TableDefinition
{
    public function model(): string
    {
        return Expense::class;
    }

    public function query(): Builder
    {
        return Expense::query()->with('enteredBy:id,name');
    }

    public function searchable(): array
    {
        return ['description', 'category'];
    }

    public function defaultSort(): array
    {
        return ['expense_date', 'desc'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'expense_date', 'label' => __('t.date'), 'type' => 'date', 'sortable' => true],
            [
                'key' => 'category',
                'label' => __('t.category'),
                'type' => 'badge',
                'sortable' => true,
                // One colour per category so the column doubles as a legend; a grey
                // fall-through for two of seven categories read as "unclassified".
                'color' => fn (Expense $r): string => match ($r->category) {
                    Expense::CAT_FUEL => 'warning',
                    Expense::CAT_OIL => 'primary',
                    Expense::CAT_REPAIR => 'destructive',
                    Expense::CAT_SALARY => 'info',
                    Expense::CAT_RENT => 'success',
                    Expense::CAT_UTILITIES => 'muted',
                    default => 'muted',
                },
                'render' => fn (Expense $r): string => Expense::label($r->category),
            ],
            [
                'key' => 'description',
                'label' => __('t.description'),
                'searchable' => true,
                'render' => fn (Expense $r): string => $r->description
                    ? \Illuminate\Support\Str::limit($r->description, 40)
                    : '—',
            ],
            [
                'key' => 'quantity',
                'label' => __('t.liters'),
                'type' => 'number',
                'align' => 'end',
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Expense $r): string => $r->quantity ? number_format((float) $r->quantity, 0) : '—',
            ],
            [
                'key' => 'amount',
                'label' => __('t.amount'),
                'type' => 'money',
                'sortable' => true,
                'align' => 'end',
                'render' => fn (Expense $r): string => money($r->amount),
            ],
            [
                'key' => 'receipt_path',
                'label' => __('t.receipt'),
                // 'html': the render returns an <img> tag, and the default text branch
                // escapes it (the operator would see the markup).
                'type' => 'html',
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Expense $r): string => $r->receipt_path
                    ? '<img src="'.e(\Illuminate\Support\Facades\Storage::disk('public')->url($r->receipt_path)).'" alt="" class="size-8 rounded-full object-cover">'
                    : '—',
            ],
            [
                'key' => 'entered_by',
                'label' => __('t.by'),
                'hidden' => true,
                'on_mobile' => false,
                'render' => fn (Expense $r): string => $r->enteredBy?->name ?? '—',
            ],
        ];
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'category',
                'label' => __('t.category'),
                'type' => 'select',
                'options' => collect(Expense::$categories)
                    ->mapWithKeys(fn (string $label, string $key) => [$key => Expense::label($key)])
                    ->all(),
                'apply' => fn (Builder $query, $value) => $query->where('category', $value),
            ],
            [
                'key' => 'month',
                'label' => __('exp.period'),
                'type' => 'select',
                'options_from' => fn (): array => Expense::query()
                    ->selectRaw('substr(expense_date, 1, 7) as m')
                    ->distinct()->orderByDesc('m')->pluck('m', 'm')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('expense_date', 'like', $value.'%'),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            ['key' => 'edit', 'label' => __('t.edit'), 'icon' => 'ki-pencil', 'url' => fn (Expense $r) => route('admin.expenses.edit', $r)],
            ['key' => 'delete', 'label' => __('t.delete'), 'icon' => 'ki-trash', 'variant' => 'danger', 'method' => 'deleteRecord'],
        ];
    }

    public function canDelete(Model $row): bool
    {
        return true;
    }

    public function deleteRecord(Model $row): void
    {
        ExpenseService::delete($row);
    }
}
