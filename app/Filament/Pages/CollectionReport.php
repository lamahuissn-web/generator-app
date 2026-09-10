<?php

namespace App\Filament\Pages;

use App\Models\Route;
use App\Models\User;
use App\Services\CollectionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class CollectionReport extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::Banknotes;

    protected static \UnitEnum|string|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.collection-report';

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
        return __('col.title');
    }

    public function getTitle(): string
    {
        return __('col.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'route_id' => null,
            'collector_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('from')
                ->label(__('col.from'))
                ->required()
                ->live()
                ->maxDate(fn () => $this->data['to'] ?? null),
            DatePicker::make('to')
                ->label(__('col.to'))
                ->required()
                ->live()
                ->minDate(fn () => $this->data['from'] ?? null),
            Select::make('route_id')
                ->label(__('col.route'))
                ->options(Route::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('gen.all_routes'))
                ->nullable()
                ->live(),
            Select::make('collector_id')
                ->label(__('col.collector'))
                ->options(User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('col.all_collectors'))
                ->nullable()
                ->live(),
        ])->statePath('data')->columns(4);
    }

    // ---- filter accessors ----------------------------------------------------

    public function from(): Carbon
    {
        $from = Carbon::parse($this->data['from'] ?? now()->startOfMonth()->toDateString())->startOfDay();
        $to = $this->to();

        return $from->gt($to) ? $to->copy() : $from;
    }

    public function to(): Carbon
    {
        return Carbon::parse($this->data['to'] ?? now()->toDateString())->endOfDay();
    }

    public function routeId(): ?int
    {
        $id = $this->data['route_id'] ?? null;

        return $id === null || $id === '' ? null : (int) $id;
    }

    public function collectorId(): ?int
    {
        $id = $this->data['collector_id'] ?? null;

        return $id === null || $id === '' ? null : (int) $id;
    }

    // ---- report data --------------------------------------------------------

    /** @return array{collected: float, handed: float, net: float, in_hand: float, payments_count: int} */
    public function totals(): array
    {
        return CollectionService::totals($this->from(), $this->to(), $this->collectorId(), $this->routeId());
    }

    /** @return Collection<int,array<string,mixed>> */
    public function collectors(): Collection
    {
        return CollectionService::byCollector($this->from(), $this->to())
            ->when($this->collectorId(), fn (Collection $rows, $id) => $rows->where('collector_id', $id)->values());
    }

    /** @return Collection<int,array<string,mixed>> */
    public function routeRows(): Collection
    {
        return CollectionService::byRoute($this->from(), $this->to(), $this->collectorId());
    }

    /** @return Collection<int,array<string,mixed>> */
    public function dayRows(): Collection
    {
        return CollectionService::byDay($this->from(), $this->to(), $this->collectorId(), $this->routeId());
    }

    public function periodLabel(): string
    {
        return $this->from()->format('Y-m-d').' → '.$this->to()->format('Y-m-d');
    }

    /** Filters shared by the print view, the PDF and the CSV export. */
    public function exportParams(): array
    {
        return array_filter([
            'from' => $this->from()->toDateString(),
            'to' => $this->to()->toDateString(),
            'route_id' => $this->routeId(),
            'collector_id' => $this->collectorId(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    // ---- actions -------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label(__('col.print'))
                ->icon(Heroicon::Printer)
                ->url(fn (): string => route('print.collection-report', [...$this->exportParams(), 'size' => 'a4']))
                ->openUrlInNewTab(),
            Action::make('pdf')
                ->label(__('col.pdf'))
                ->icon(Heroicon::DocumentArrowDown)
                ->url(fn (): string => route('print.collection-report', [...$this->exportParams(), 'size' => 'a4', 'format' => 'pdf']))
                ->openUrlInNewTab(),
            Action::make('export')
                ->label(__('col.export'))
                ->icon(Heroicon::ArrowDownTray)
                ->action(fn () => $this->exportCsv()),
        ];
    }

    /** Daily detail as CSV (Excel-friendly: UTF-8 BOM, Latin digits). */
    protected function exportCsv()
    {
        $totals = $this->totals();
        $csv = fopen('php://temp', 'r+');
        fwrite($csv, "\xEF\xBB\xBF");

        fputcsv($csv, [__('col.title'), $this->periodLabel()]);
        if ($collector = $this->collectorId()) {
            fputcsv($csv, [__('col.collector'), User::whereKey($collector)->value('name')]);
        }
        if ($route = $this->routeId()) {
            fputcsv($csv, [__('col.route'), Route::whereKey($route)->value('name')]);
        }
        fputcsv($csv, [__('col.collected'), number_format($totals['collected'], 2)]);
        fputcsv($csv, [__('col.handed'), number_format($totals['handed'], 2)]);
        fputcsv($csv, [__('col.in_hand'), number_format($totals['in_hand'], 2)]);
        fputcsv($csv, []);

        fputcsv($csv, [__('col.by_day')]);
        fputcsv($csv, [__('t.date'), __('col.payments_count'), __('t.amount')]);
        foreach ($this->dayRows() as $row) {
            fputcsv($csv, [$row['day'], $row['payments_count'], number_format($row['collected'], 2)]);
        }
        fputcsv($csv, []);

        fputcsv($csv, [__('col.by_route')]);
        fputcsv($csv, [__('col.route'), __('col.payments_count'), __('t.amount')]);
        foreach ($this->routeRows() as $row) {
            fputcsv($csv, [$row['name'], $row['payments_count'], number_format($row['collected'], 2)]);
        }
        fputcsv($csv, []);

        fputcsv($csv, [__('col.by_collector')]);
        fputcsv($csv, [__('col.collector'), __('col.payments_count'), __('col.collected'), __('col.handed'), __('col.in_hand')]);
        foreach ($this->collectors() as $row) {
            fputcsv($csv, [
                $row['name'], $row['payments_count'],
                number_format($row['collected'], 2),
                number_format($row['handed'], 2),
                number_format($row['in_hand'], 2),
            ]);
        }

        rewind($csv);

        return response()->streamDownload(function () use ($csv) {
            fpassthru($csv);
        }, 'collections-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }
}
