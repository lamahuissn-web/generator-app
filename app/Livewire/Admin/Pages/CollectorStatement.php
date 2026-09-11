<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Livewire\Component;

/**
 * One collector's cash ledger, plus the handover form that settles it.
 *
 * The balance shown is ALL-TIME (everything collected minus everything handed over), never
 * scoped to the range on screen: that is the number the operator counts in the drawer, and a
 * range-scoped figure would make it look as if cash had gone missing.
 */
class CollectorStatement extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('col.led_sub');
    }

    /** Range or everything. Not a filter field — a mode, and it changes the ledger's shape. */
    public bool $allTime = false;

    /** Handover modal. */
    public bool $handoverOpen = false;

    /** @var array<string, mixed> */
    public array $handover = [];

    public function mount(): void
    {
        $this->allTime = request()->boolean('all_time');

        $this->filters = [
            'collector_id' => request()->integer('collector_id')
                ?: User::query()->where('role', User::ROLE_COLLECTOR)->orderBy('name')->value('id'),
            'from' => request()->input('from') ?: now()->startOfMonth()->toDateString(),
            'to' => request()->input('to') ?: now()->toDateString(),
        ];
    }

    public function filterSchema(): array
    {
        return [
            [
                'key' => 'collector_id',
                'label' => __('col.collector'),
                'type' => 'select',
                'options_from' => fn (): array => User::query()->where('role', User::ROLE_COLLECTOR)
                    ->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['required', 'integer', 'exists:users,id'],
            ],
            [
                'key' => 'from',
                'label' => __('col.from'),
                'type' => 'date',
                'default' => now()->startOfMonth()->toDateString(),
                'rules' => ['nullable', 'date'],
            ],
            [
                'key' => 'to',
                'label' => __('col.to'),
                'type' => 'date',
                'default' => now()->toDateString(),
                'rules' => ['nullable', 'date'],
            ],
        ];
    }

    // ---- accessors ---------------------------------------------------------

    public function collector(): ?User
    {
        $id = $this->filter('collector_id');

        return $id ? User::find((int) $id) : null;
    }

    public function from(): ?Carbon
    {
        $value = $this->filter('from');

        return is_string($value) && $value !== '' ? Carbon::parse($value)->startOfDay() : null;
    }

    public function to(): ?Carbon
    {
        $value = $this->filter('to');

        return is_string($value) && $value !== '' ? Carbon::parse($value)->endOfDay() : null;
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

    public function ledger(): ?array
    {
        $collector = $this->collector();

        return $collector ? CollectionService::collectorLedger($collector, $this->allTime ? null : $this->from()) : null;
    }

    public function printParams(): array
    {
        return array_filter([
            'collector_id' => $this->collector()?->id,
            'from' => $this->from()?->toDateString(),
            'to' => $this->to()?->toDateString(),
            'all_time' => $this->allTime ? 1 : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** Flip between range and all-time without losing the selected collector. */
    public function toggleAllTime(): void
    {
        $this->allTime = ! $this->allTime;
    }

    // ---- handover ----------------------------------------------------------

    public function openHandover(): void
    {
        $this->handover = [
            'amount' => null,
            'handover_date' => now()->toDateString(),
            'note' => null,
        ];
        $this->resetErrorBag();
        $this->handoverOpen = true;
    }

    public function closeHandover(): void
    {
        $this->handoverOpen = false;
        $this->resetErrorBag();
    }

    public function saveHandover(): void
    {
        $collector = $this->collector();
        abort_if($collector === null, 404);

        $this->validate([
            'handover.amount' => ['required', 'numeric', 'min:0.01'],
            'handover.handover_date' => ['required', 'date'],
            'handover.note' => ['nullable', 'string', 'max:200'],
        ]);

        CashHandoverService::create([
            'collector_id' => $collector->id,
            'amount' => (float) $this->handover['amount'],
            'handover_date' => $this->handover['handover_date'],
            'note' => $this->handover['note'] ?: null,
        ], auth()->id());

        $this->handoverOpen = false;

        session()->flash('status', __('col.quick_handover_done', ['amount' => money_ltr((float) $this->handover['amount'])]));
    }

    public function render()
    {
        return view('livewire.admin.pages.collector-statement', [
            'collector' => $this->collector(),
            'ledger' => $this->ledger(),
            'inHand' => $this->inHand(),
            'handedInRange' => $this->handedInRange(),
            'periodLabel' => $this->periodLabel(),
            'exportParams' => $this->printParams(),
        ]);
    }
}
