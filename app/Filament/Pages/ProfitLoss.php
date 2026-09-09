<?php

namespace App\Filament\Pages;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Payment;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ProfitLoss extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::ChartBar;

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.profit-loss';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'month' => now()->format('Y-m'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')
                ->label('Month')
                ->options($this->monthOptions())
                ->live()
                ->required(),
        ])->statePath('data');
    }

    /** @return array<string,string> last 18 months, oldest first */
    public function monthOptions(): array
    {
        $options = [];
        for ($i = 17; $i >= 0; $i--) {
            $d = now()->startOfMonth()->subMonths($i);
            $options[$d->format('Y-m')] = $d->format('F Y');
        }

        return $options;
    }

    /** @return array<string,mixed> */
    public function report(): array
    {
        $month = $this->data['month'] ?? now()->format('Y-m');
        $start = \Carbon\Carbon::parse($month.'-01')->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $invoiced = (float) Bill::where('period', $month)->sum('total');
        $collected = (float) Payment::whereBetween('created_at', [$start, $end])->sum('amount');
        $rate = $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0;

        $expensesTotal = (float) Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])->sum('amount');
        $byCategory = Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('category, COUNT(*) as count, COALESCE(SUM(amount), 0) as total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'label' => Expense::$categories[$row->category] ?? $row->category,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

        $recentExpenses = Expense::whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->latest('expense_date')
            ->limit(8)
            ->get(['expense_date', 'category', 'description', 'amount']);

        return [
            'month' => $month,
            'label' => $start->format('F Y'),
            'invoiced' => $invoiced,
            'collected' => $collected,
            'rate' => $rate,
            'expenses' => $expensesTotal,
            'net' => round($collected - $expensesTotal, 2),
            'byCategory' => $byCategory,
            'recentExpenses' => $recentExpenses,
        ];
    }
}
