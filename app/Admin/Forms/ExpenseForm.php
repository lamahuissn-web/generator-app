<?php

namespace App\Admin\Forms;

use App\Admin\FormDefinition;
use App\Models\Expense;
use App\Services\ExpenseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * An operating expense, with the paper receipt photographed on site.
 *
 * The receipt is stored on the public disk (a receipt is a photo the operator needs to open
 * later, not a private document) and the OLD file is removed when it is replaced — otherwise
 * every corrected expense leaves an orphan in storage forever.
 */
class ExpenseForm extends FormDefinition
{
    public function model(): string
    {
        return Expense::class;
    }

    public function sections(): array
    {
        return [
            [
                'title' => __('s.expense'),
                'fields' => [
                    [
                        'key' => 'category',
                        'label' => __('t.category'),
                        'type' => 'select',
                        'default' => Expense::CAT_FUEL,
                        'options' => collect(Expense::$categories)
                            ->mapWithKeys(fn (string $label, string $key) => [$key => Expense::label($key)])
                            ->all(),
                        'rules' => ['required', 'in:'.implode(',', array_keys(Expense::$categories))],
                    ],
                    [
                        'key' => 'expense_date',
                        'label' => __('exp.date'),
                        'type' => 'date',
                        'default' => now()->toDateString(),
                        'rules' => ['required', 'date'],
                    ],
                    [
                        'key' => 'amount',
                        'label' => __('exp.amount'),
                        'type' => 'money',
                        'min' => 0.01,
                        'rules' => ['required', 'numeric', 'min:0.01'],
                    ],
                    [
                        'key' => 'quantity',
                        'label' => __('exp.quantity'),
                        'type' => 'number',
                        'min' => 0,
                        'helper' => __('s.fuel_hint'),
                        'rules' => ['nullable', 'numeric', 'min:0'],
                        'visible_when' => fn (array $state): bool => ($state['category'] ?? null) === Expense::CAT_FUEL,
                    ],
                    [
                        'key' => 'description',
                        'label' => __('exp.description'),
                        'span' => 'full',
                        'rules' => ['nullable', 'string', 'max:200'],
                    ],
                    [
                        'key' => 'receipt_path',
                        'label' => __('exp.receipt_photo'),
                        'type' => 'image',
                        'span' => 'full',
                        'helper' => __('exp.receipt_hint'),
                        'rules' => ['nullable', 'image', 'max:4096'],
                    ],
                ],
            ],
        ];
    }

    public function stateFrom(Model $record): array
    {
        return [
            'category' => $record->category,
            'expense_date' => $record->expense_date?->toDateString(),
            'amount' => (float) $record->amount,
            'quantity' => $record->quantity,
            'description' => $record->description,
            'receipt_path' => $record->receipt_path,
        ];
    }

    public function save(array $state, ?Model $record): Model
    {
        $data = [
            'category' => $state['category'],
            'expense_date' => $state['expense_date'],
            'amount' => (float) $state['amount'],
            // Litres only mean anything for fuel; a stale value on a rent expense is noise.
            'quantity' => $state['category'] === Expense::CAT_FUEL && filled($state['quantity'] ?? null)
                ? (float) $state['quantity']
                : null,
            'description' => $state['description'] ?: null,
        ];

        $upload = $state['receipt_path'] ?? null;

        if ($upload instanceof UploadedFile) {
            $previous = $record?->receipt_path;
            $data['receipt_path'] = $upload->store('expense-receipts', 'public');

            if (filled($previous) && Storage::disk('public')->exists($previous)) {
                Storage::disk('public')->delete($previous);
            }
        }

        return $record === null
            ? ExpenseService::create($data, auth()->id())
            : ExpenseService::update($record, $data);
    }

    public function redirectAfterSave(Model $record): string
    {
        return route('admin.expenses.index');
    }

    public function recordTitle(Model $record): string
    {
        return money($record->amount).' · '.Expense::label($record->category);
    }
}
