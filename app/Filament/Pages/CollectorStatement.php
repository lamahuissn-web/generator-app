<?php

namespace App\Filament\Pages;

use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Cash ledger of one collector: what they took from each customer and what they
 * handed over, with the running cash-in-hand balance.
 *
 * Opened from the collection report (the collector name is a link) with the
 * report's date range and a quick "record handover" action.
 */
class CollectorStatement extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::DocumentText;

    protected static ?string $slug = 'collector-statement';

    /** Reachable from the collection report, not a separate nav entry. */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.collector-statement';

    public array $data = [];

    /** Query-driven: the ledger either follows the range or covers everything. */
    public bool $allTime = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function getTitle(): string
    {
        return $this->collector()
            ? __('col.led_title', ['name' => $this->collector()->name])
            : __('col.led_title_generic');
    }

    public function mount(): void
    {
        $this->allTime = request()->boolean('all_time');

        $this->form->fill([
            'collector_id' => request()->integer('collector_id') ?: User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->value('id'),
            'from' => request()->input('from') ?: now()->startOfMonth()->toDateString(),
            'to' => request()->input('to') ?: now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('collector_id')
                ->label(__('col.collector'))
                ->options(User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live(),
            DatePicker::make('from')->label(__('col.from'))->required()->live(),
            DatePicker::make('to')->label(__('col.to'))->required()->live(),
        ])->statePath('data')->columns(3);
    }

    // ---- accessors -----------------------------------------------------------

    public function collector(): ?User
    {
        $id = $this->data['collector_id'] ?? null;

        return $id ? User::find((int) $id) : null;
    }

    public function from(): ?Carbon
    {
        $value = $this->data['from'] ?? null;

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    public function to(): ?Carbon
    {
        $value = $this->data['to'] ?? null;

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    /** The ledger, or null when no collector is selected. */
    public function ledger(): ?array
    {
        $collector = $this->collector();
        if (! $collector) {
            return null;
        }

        return CollectionService::collectorLedger($collector, $this->allTime ? null : $this->from());
    }

    public function inHand(): float
    {
        $collector = $this->collector();

        return $collector ? CollectionService::inHand($collector->id) : 0.0;
    }

    public function handedInRange(): float
    {
        $collector = $this->collector();
        if (! $collector || ! $this->from() || ! $this->to()) {
            return 0.0;
        }

        return (float) CashHandover::query()
            ->where('collector_id', $collector->id)
            ->whereBetween('handover_date', [$this->from()->toDateString(), $this->to()->toDateString()])
            ->sum('amount');
    }

    public function periodLabel(): string
    {
        if ($this->allTime) {
            return __('col.st_period_all');
        }

        return $this->from()?->format('Y-m-d').' — '.$this->to()?->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function printParams(): array
    {
        return array_filter([
            'collector_id' => $this->collector()?->id,
            'from' => $this->from()?->toDateString(),
            'to' => $this->to()?->toDateString(),
            'all_time' => $this->allTime ? 1 : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    // ---- actions -------------------------------------------------------------

    protected function getHeaderActions(): array
    {
        $collector = $this->collector();

        return [
            Action::make('handover')
                ->label(__('col.quick_handover'))
                ->icon(Heroicon::Banknotes)
                ->color('warning')
                ->visible((bool) $collector)
                ->modalHeading(fn (): string => __('col.quick_handover_heading', ['name' => $collector?->name ?? '']))
                ->modalDescription(fn (): string => __('col.quick_handover_desc', ['amount' => money($this->inHand())]))
                ->schema([
                    TextInput::make('amount')
                        ->label(__('col.amount'))
                        ->numeric()->minValue(0.01)->required()->prefix('$')
                        ->helperText(__('col.quick_handover_helper')),
                    DatePicker::make('handover_date')
                        ->label(__('col.date'))
                        ->default(now())
                        ->required(),
                    TextInput::make('note')->label(__('col.note'))->maxLength(200),
                ])
                ->action(function (array $data) use ($collector): void {
                    if (! $collector) {
                        return;
                    }

                    CashHandoverService::create([
                        'collector_id' => $collector->id,
                        'amount' => $data['amount'],
                        'handover_date' => $data['handover_date'],
                        'note' => $data['note'] ?? null,
                    ], auth()->id());

                    Notification::make()
                        ->title(__('col.quick_handover_done', ['amount' => money((float) $data['amount'])]))
                        ->body(__('col.quick_handover_after', ['amount' => money($this->inHand())]))
                        ->success()
                        ->send();
                }),
            Action::make('all_time')
                ->label(fn (): string => $this->allTime ? __('col.led_back_to_range') : __('col.st_all_time'))
                ->icon(Heroicon::ArrowsRightLeft)
                ->color('gray')
                ->url(fn (): string => self::getUrl($this->allTime
                    ? array_filter([
                        'collector_id' => $this->collector()?->id,
                        'from' => $this->from()?->toDateString(),
                        'to' => $this->to()?->toDateString(),
                    ])
                    : [...$this->printParams(), 'all_time' => 1])),
            Action::make('print')
                ->label(__('col.print'))
                ->icon(Heroicon::Printer)
                ->visible((bool) $collector)
                ->url(fn (): string => route('print.collector-statement', [...$this->printParams(), 'size' => 'a4']))
                ->openUrlInNewTab(),
            Action::make('pdf')
                ->label(__('col.pdf'))
                ->icon(Heroicon::DocumentArrowDown)
                ->visible((bool) $collector)
                ->url(fn (): string => route('print.collector-statement', [...$this->printParams(), 'size' => 'a4', 'format' => 'pdf']))
                ->openUrlInNewTab(),
            Action::make('report')
                ->label(__('col.back_to_report'))
                ->icon(Heroicon::ArrowUturnLeft)
                ->color('gray')
                ->url(fn (): string => CollectionReport::getUrl(array_filter([
                    'from' => $this->from()?->toDateString(),
                    'to' => $this->to()?->toDateString(),
                    'collector_id' => $this->collector()?->id,
                ]))),
        ];
    }
}
