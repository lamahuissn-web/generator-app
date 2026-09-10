<?php

namespace App\Filament\Pages;

use App\Models\Route;
use App\Services\BillingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class GenerateBills extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::DocumentDuplicate;

    protected static \UnitEnum|string|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.generate-bills';

    public array $data = [];

    /** Result of the last committed run, used by the print/PDF buttons. */
    public ?array $lastRun = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('gen.title');
    }

    public function getTitle(): string
    {
        return __('gen.title');
    }

    public function mount(): void
    {
        $this->form->fill([
            'period' => now()->format('Y-m'),
            'route_id' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label(__('gen.period'))
                ->options($this->monthOptions())
                ->live()
                ->required(),
            Select::make('route_id')
                ->label(__('gen.route'))
                ->options(Route::query()->orderBy('name')->pluck('name', 'id')->all())
                ->placeholder(__('gen.all_routes'))
                ->nullable()
                ->live(),
        ])->statePath('data');
    }

    /** @return array<string,string> previous, current and next month first */
    public function monthOptions(): array
    {
        $options = [now()->format('Y-m') => $this->monthLabel(now()->format('Y-m'))];

        for ($i = 1; $i <= 17; $i++) {
            $key = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $options[$key] = $this->monthLabel($key);
        }
        for ($i = 1; $i <= 3; $i++) {
            $key = now()->startOfMonth()->addMonths($i)->format('Y-m');
            $options[$key] = $this->monthLabel($key);
        }

        return $options;
    }

    private function monthLabel(string $period): string
    {
        return app()->isLocale('ar') ? period_label_ar($period) : period_label($period);
    }

    public function period(): string
    {
        return (string) ($this->data['period'] ?? now()->format('Y-m'));
    }

    public function routeId(): ?int
    {
        $routeId = $this->data['route_id'] ?? null;

        return $routeId === null || $routeId === '' ? null : (int) $routeId;
    }

    /** @return array{period: string, route_id: ?int, rows: array<int,array<string,mixed>>, summary: array<string,int>, invoiced: float, created: int} */
    public function run(): array
    {
        return BillingService::periodRun($this->period(), $this->routeId());
    }

    public function generate(): void
    {
        $result = BillingService::periodRun($this->period(), $this->routeId(), true, auth()->id());
        $billIds = collect($result['rows'])->pluck('bill_id')->filter()->values()->all();

        $this->lastRun = [
            'period' => $result['period'],
            'route_id' => $result['route_id'],
            'created' => $result['created'],
            'invoiced' => $result['invoiced'],
            'bill_ids' => $billIds,
        ];

        Notification::make()
            ->title($result['created'] > 0
                ? __('gen.notif_created', ['count' => $result['created']])
                : __('gen.notif_none'))
            ->body(__('gen.notif_body', [
                'total' => money($result['invoiced']),
                'skipped' => $result['summary'][BillingService::RUN_NO_READING] + $result['summary'][BillingService::RUN_INACTIVE] + $result['summary'][BillingService::RUN_ZERO],
            ]))
            ->status($result['created'] > 0 ? 'success' : 'warning')
            ->send();
    }

    /** Bills the print/PDF buttons should cover: the fresh run, else the whole filter. */
    public function printParams(): array
    {
        if ($this->lastRun && $this->lastRun['bill_ids']) {
            return ['ids' => implode(',', $this->lastRun['bill_ids'])];
        }

        return array_filter([
            'period' => $this->period(),
            'route_id' => $this->routeId(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function printedCount(): int
    {
        if ($this->lastRun && $this->lastRun['bill_ids']) {
            return count($this->lastRun['bill_ids']);
        }

        return collect($this->run()['rows'])
            ->whereIn('state', [BillingService::RUN_CREATE, BillingService::RUN_EXISTS])
            ->count();
    }

    protected function getHeaderActions(): array
    {
        $params = $this->printParams();

        return [
            Action::make('generate')
                ->label(__('gen.run_btn'))
                ->icon(Heroicon::DocumentDuplicate)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading(__('gen.confirm_heading'))
                ->modalDescription(__('gen.confirm_body', [
                    'count' => collect($this->run()['rows'])->where('state', BillingService::RUN_CREATE)->count(),
                    'period' => $this->monthLabel($this->period()),
                ]))
                ->modalSubmitActionLabel(__('gen.run_btn'))
                ->action('generate'),
            Action::make('print')
                ->label(__('gen.print_all'))
                ->icon(Heroicon::Printer)
                ->url(route('print.bills', [...$params, 'size' => config('pdf.default_bill_size')]))
                ->openUrlInNewTab()
                ->visible($this->printedCount() > 0),
            Action::make('pdf')
                ->label(__('gen.pdf_all'))
                ->icon(Heroicon::DocumentArrowDown)
                ->url(route('print.bills', [...$params, 'size' => config('pdf.default_bill_size'), 'format' => 'pdf']))
                ->openUrlInNewTab()
                ->visible($this->printedCount() > 0),
            Action::make('zip')
                ->label(__('gen.zip_all'))
                ->icon(Heroicon::ArchiveBoxArrowDown)
                ->url(route('print.bills', [...$params, 'size' => config('pdf.default_bill_size'), 'format' => 'zip']))
                ->visible($this->printedCount() > 0),
        ];
    }
}
