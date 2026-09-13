<?php

namespace App\Livewire\Admin\Pages;

use App\Livewire\Admin\Concerns\HasFilters;
use App\Models\Route;
use App\Models\Tariff;
use App\Services\BillingService;
use Livewire\Component;

/**
 * The monthly billing run.
 *
 * Two steps on purpose: the screen first shows what the run WOULD do (BillingService
 * previews without writing), and only a confirmation commits it. Raising an invoice is the
 * one action in this panel that is expensive to undo, so it never happens on a stray click.
 */
class GenerateBills extends Component
{
    use HasFilters;

    public static function subheading(): string
    {
        return __('gen.sub');
    }

    /** The confirmation modal. */
    public bool $confirming = false;

    /** What the last committed run produced, for the print/PDF/ZIP buttons. */
    public ?array $lastRun = null;

    /**
     * Memoised run.
     *
     * periodRun() is not free — it resolves each subscriber's existing bill and prior debt —
     * and the old screen called it once per render from several accessors. Computing it once
     * per request keeps a billing screen from being the slowest page in the panel.
     */
    private ?array $runCache = null;

    private ?string $runCacheKey = null;

    public function mount(): void
    {
        $this->mountFilters();
    }

    public function filterSchema(): array
    {
        return [
            [
                'key' => 'period',
                'label' => __('gen.period'),
                'type' => 'month',
                'default' => now()->format('Y-m'),
                'rules' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            ],
            [
                'key' => 'route_id',
                'label' => __('gen.route'),
                'type' => 'select',
                'placeholder' => __('gen.all_routes'),
                'options_from' => fn (): array => Route::query()->orderBy('name')->pluck('name', 'id')->all(),
                'rules' => ['nullable', 'integer', 'exists:routes,id'],
            ],
        ];
    }

    public function period(): string
    {
        $value = $this->filter('period');

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1
            ? $value
            : now()->format('Y-m');
    }

    public function routeId(): ?int
    {
        $value = $this->filter('route_id');

        return $value === null ? null : (int) $value;
    }

    /** Never writes: BillingService::periodRun() previews until $commit is true. */
    public function run(): array
    {
        $key = $this->period().'|'.($this->routeId() ?? '');

        if ($this->runCache === null || $this->runCacheKey !== $key) {
            $this->runCache = BillingService::periodRun($this->period(), $this->routeId());
            $this->runCacheKey = $key;
        }

        return $this->runCache;
    }

    /** kWh price for the period and where it comes from. */
    public function tariff(): array
    {
        $period = $this->period();

        return [
            'price' => BillingService::kwhPrice($period),
            'from_tariff' => BillingService::priceSource($period) === 'tariff',
            'row' => Tariff::forPeriod($period),
        ];
    }

    /** "All readings in?" — the gate the operator checks before committing. */
    public function progress(): array
    {
        return BillingService::readingProgress($this->period(), $this->routeId());
    }

    public function countInState(string $state): int
    {
        return (int) ($this->run()['summary'][$state] ?? 0);
    }

    /** Rows the operator would actually print: fresh or already-existing invoices. */
    public function printedCount(): int
    {
        if ($this->lastRun && $this->lastRun['bill_ids']) {
            return count($this->lastRun['bill_ids']);
        }

        return $this->countInState(BillingService::RUN_CREATE) + $this->countInState(BillingService::RUN_EXISTS);
    }

    // ---- commit ------------------------------------------------------------

    public function confirm(): void
    {
        $this->resetErrorBag();
        $this->confirming = true;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    public function generate(): void
    {
        $result = BillingService::periodRun($this->period(), $this->routeId(), true, auth()->id());

        $this->lastRun = [
            'period' => $result['period'],
            'route_id' => $result['route_id'],
            'created' => $result['created'],
            'invoiced' => $result['invoiced'],
            // Captured HERE, not recomputed on render: once the bills exist, a fresh preview
            // reports them as "already exists" and the skipped count would read zero.
            'skipped' => $this->skipped($result),
            'bill_ids' => collect($result['rows'])->pluck('bill_id')->filter()->values()->all(),
        ];

        // The preview is stale the moment bills exist; drop it so the next render re-reads.
        $this->runCache = null;
        $this->runCacheKey = null;
        $this->confirming = false;

        session()->flash(
            $result['created'] > 0 ? 'status' : 'error',
            $result['created'] > 0
                ? __('gen.notif_created', ['count' => $result['created']]).' '.__('gen.notif_body', [
                    'total' => money_ltr($result['invoiced']),
                    'skipped' => $this->skipped($result),
                ])
                : __('gen.notif_none'),
        );
    }

    /** Subscribers the run passed over, and why — summed for the notification body. */
    private function skipped(array $result): int
    {
        return collect([
            BillingService::RUN_NO_READING,
            BillingService::RUN_INACTIVE,
            BillingService::RUN_ZERO,
        ])->sum(fn (string $state): int => (int) ($result['summary'][$state] ?? 0));
    }

    /** The bills the print/PDF/ZIP buttons cover: the fresh run, else the whole filter. */
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

    public function render()
    {
        return view('livewire.admin.pages.generate-bills', [
            'run' => $this->run(),
            'tariff' => $this->tariff(),
            'progress' => $this->progress(),
            'printedCount' => $this->printedCount(),
            'printParams' => $this->printParams(),
            'stateLabels' => [
                BillingService::RUN_CREATE => __('gen.state_create'),
                BillingService::RUN_EXISTS => __('gen.state_exists'),
                BillingService::RUN_NO_READING => __('gen.state_no_reading'),
                BillingService::RUN_INACTIVE => __('gen.state_inactive'),
                BillingService::RUN_ZERO => __('gen.state_zero'),
            ],
        ]);
    }
}
