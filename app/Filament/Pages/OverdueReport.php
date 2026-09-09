<?php

namespace App\Filament\Pages;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Route;
use App\Models\Subscriber;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class OverdueReport extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::ExclamationTriangle;

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.overdue-report';

    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('mdl.overdue_report');
    }

    public function mount(): void
    {
        $this->form->fill([
            'bucket' => 'all',
            'route_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('bucket')
                ->label(__('over.age'))
                ->options([
                    'all' => __('over.opt_all'),
                    'red' => __('over.opt_red'),
                    'yellow' => __('over.opt_yellow'),
                    'current' => __('over.opt_current'),
                    'settled' => __('over.opt_settled'),
                ])
                ->live()
                ->required(),
            Select::make('route_id')
                ->label(__('over.route'))
                ->options(Route::query()->orderBy('name')->pluck('name', 'id')->all())
                ->nullable()
                ->live(),
        ])->statePath('data');
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(): array
    {
        $bucket = $this->data['bucket'] ?? 'all';
        $routeId = $this->data['route_id'] ?? null;

        $agg = Bill::query()
            ->selectRaw('subscriber_id, MIN(period) as oldest, MAX(period) as newest, COALESCE(SUM(total - paid_amount), 0) as due')
            ->whereRaw('paid_amount < total')
            ->groupBy('subscriber_id')
            ->get()
            ->keyBy('subscriber_id');

        $subs = Subscriber::query()
            ->with('route:id,name')
            ->when($routeId, fn ($q) => $q->where('route_id', $routeId))
            ->get();

        $rows = [];
        foreach ($subs as $sub) {
            $a = $agg->get($sub->id);
            $due = (float) ($a->due ?? 0);

            if ($due <= 0) {
                $bucketKey = 'settled';
            } else {
                $oldestMonths = $this->monthsBetween(now()->format('Y-m'), (string) $a->oldest);
                $bucketKey = $oldestMonths >= 2 ? 'red' : ($oldestMonths === 1 ? 'yellow' : 'current');
            }

            $rows[] = [
                'id' => $sub->id,
                'name' => $sub->name,
                'route' => $sub->route?->name,
                'type' => $sub->type,
                'status' => $sub->status,
                'phone' => $sub->phone,
                'due' => $due,
                'bucket' => $bucketKey,
                'oldest' => $a?->oldest,
                'newest' => $a?->newest,
            ];
        }

        usort($rows, fn ($x, $y) => [$this->bucketRank($x['bucket']), -$x['due']] <=> [$this->bucketRank($y['bucket']), -$y['due']]);

        if ($bucket !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['bucket'] === $bucket));
        }

        return $rows;
    }

    protected function monthsBetween(string $current, string $period): int
    {
        [$cy, $cm] = array_map('intval', explode('-', $current));
        [$py, $pm] = array_map('intval', explode('-', $period));

        return ($cy * 12 + $cm) - ($py * 12 + $pm);
    }

    protected function bucketRank(string $bucket): int
    {
        return match ($bucket) {
            'red' => 0,
            'yellow' => 1,
            'current' => 2,
            default => 3,
        };
    }

    /** @return array{total: float, red: float, yellow: float} */
    public function summary(): array
    {
        $rows = $this->rows();

        return [
            'total' => (float) array_sum(array_column($rows, 'due')),
            'red' => (float) array_sum(array_column(array_filter($rows, fn ($r) => $r['bucket'] === 'red'), 'due')),
            'yellow' => (float) array_sum(array_column(array_filter($rows, fn ($r) => $r['bucket'] === 'yellow'), 'due')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('over.export_btn'))
                ->icon(Heroicon::ArrowDownTray)
                ->action(function () {
                    $rows = array_values(array_filter($this->rows(), fn ($r) => $r['bucket'] !== 'settled'));
                    $csv = fopen('php://temp', 'r+');
                    fwrite($csv, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly
                    fputcsv($csv, ['Subscriber', 'Route', 'Type', 'Phone', 'Status', 'Oldest unpaid period', 'Total due (USD)']);
                    foreach ($rows as $r) {
                        fputcsv($csv, [
                            $r['name'], $r['route'] ?? '', $r['type'] === 'metered' ? 'Metered' : 'Flat',
                            $r['phone'] ?? '', $r['status'], $r['oldest'] ?? '', number_format($r['due'], 2),
                        ]);
                    }
                    rewind($csv);

                    return response()->streamDownload(function () use ($csv) {
                        fpassthru($csv);
                    }, 'overdue-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
                }),
        ];
    }
}
