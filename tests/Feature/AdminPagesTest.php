<?php

namespace Tests\Feature;

use App\Admin\AdminNav;
use App\Livewire\Admin\Pages\Collections;
use App\Livewire\Admin\Pages\GenerateBills;
use App\Livewire\Admin\Pages\OverdueReport;
use App\Livewire\Admin\Pages\ProfitLoss;
use App\Livewire\Admin\Pages\Settings;
use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\OverdueReport as OverdueReportData;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The page screens: reports, the billing run, settings.
 *
 * These screens are read-only apart from the billing run and the handover, so most of what
 * follows pins down that the FIGURES are right and that nothing writes unless the operator
 * confirmed. A report that renders but reports the wrong total is worse than one that 500s.
 */
class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        // Component tests do not run middleware, so the locale the panel sets per request has
        // to be applied here; the assertions below read the Arabic labels on purpose.
        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@pages.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@pages.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);

        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'لينا مرعب',
            'phone' => '03 555789',
            'meter_no' => '2202',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 25,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    /** A bill for a period, unpaid unless a paid amount is given. */
    private function bill(string $period, float $total, float $paid = 0, ?Subscriber $subscriber = null): Bill
    {
        $subscriber ??= $this->subscriber;

        return Bill::create([
            'number' => 'INV-'.$period.'-'.str_pad((string) $subscriber->id, 4, '0', STR_PAD_LEFT),
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'type' => Subscriber::TYPE_METERED,
            'usage_amount' => $total - 25,
            'maintenance_fee' => 25,
            'total' => $total,
            'paid_amount' => $paid,
        ]);
    }

    /** A payment dated in the past: the range reports read created_at, not a paid_at column. */
    private function payment(float $amount, string $on, ?User $collector = null): Payment
    {
        $payment = Payment::create([
            'subscriber_id' => $this->subscriber->id,
            'collector_id' => ($collector ?? $this->collector)->id,
            'amount' => $amount,
            'method' => Payment::METHOD_CASH_USD,
        ]);

        $payment->forceFill(['created_at' => $on.' 10:00:00'])->save();

        return $payment;
    }

    // ---- the door and the spec -------------------------------------------

    public function test_every_page_screen_renders_for_an_admin(): void
    {
        $pages = AdminNav::pages();

        $this->assertNotEmpty($pages, 'AdminNav::pages() returned nothing — no page routes would be registered.');

        foreach ($pages as $page) {
            $this->actingAs($this->admin)
                ->get(route($page['route']))
                ->assertOk()
                // The login screen is also a 200; asserting content is the only real check.
                ->assertSee($page['label'])
                ->assertDontSee('name="password"', false);
        }
    }

    /** The reports carry every subscriber's debts; the portal must never reach them. */
    public function test_a_collector_cannot_open_any_page_screen(): void
    {
        foreach (AdminNav::pages() as $page) {
            $this->actingAs($this->collector)->get(route($page['route']))->assertForbidden();
        }
    }

    // ---- the billing run --------------------------------------------------

    /**
     * The preview is the whole safety of this screen: it resolves each subscriber's existing
     * bill, price and prior debt WITHOUT writing. If it ever writes, opening the page raises
     * a month of invoices.
     */
    public function test_the_billing_run_preview_writes_nothing(): void
    {
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-09',
            'current_reading' => 1200, 'previous_reading' => 1100, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GenerateBills::class)
            ->assertSet('filters.period', now()->format('Y-m'))
            ->assertSee('تغطية القراءات');

        $this->assertSame(0, Bill::count(), 'Rendering the billing preview wrote a bill.');
    }

    public function test_the_billing_run_creates_the_invoices_and_reports_what_it_skipped(): void
    {
        // One metered subscriber with a reading (creatable), one without (skipped).
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-09',
            'current_reading' => 1200, 'previous_reading' => 1100, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        $skipped = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Ali Hassan', 'meter_no' => '9999',
            'type' => Subscriber::TYPE_METERED, 'maintenance_fee' => 25,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(GenerateBills::class)
            ->set('filters.period', '2026-09')
            ->call('confirm')
            ->assertSet('confirming', true)
            ->call('generate')
            ->assertSet('confirming', false);

        $this->assertSame(1, Bill::count());
        $this->assertSame($this->subscriber->id, Bill::firstOrFail()->subscriber_id);
        $this->assertSame(0, $skipped->bills()->count(), 'A subscriber with no reading got an invoice.');

        // "Skipped" is captured at commit time: a fresh preview would now report the created
        // bill as "already exists" and the figure would read zero afterwards.
        $lastRun = $component->get('lastRun');
        $this->assertNotNull($lastRun, 'The run did not record what it produced.');
        $this->assertSame(1, $lastRun['created']);
        $this->assertSame(1, $lastRun['skipped'], 'The subscriber with no reading was not reported.');
    }

    /** Cancelling the confirmation must leave the month untouched. */
    public function test_cancelling_the_billing_confirmation_writes_nothing(): void
    {
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-09',
            'current_reading' => 1200, 'previous_reading' => 1100, 'consumption' => 100,
            'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(GenerateBills::class)
            ->set('filters.period', '2026-09')
            ->call('confirm')
            ->call('cancel')
            ->assertSet('confirming', false);

        $this->assertSame(0, Bill::count());
    }

    /** A half-typed period must fall back, not throw mid-render. */
    public function test_the_billing_run_survives_a_malformed_period(): void
    {
        $component = Livewire::actingAs($this->admin)->test(GenerateBills::class);

        // Bypassing the select entirely, the way a crafted request would.
        $component->set('filters.period', 'not-a-period')->assertOk();

        $this->assertSame(now()->format('Y-m'), $component->instance()->period());
    }

    // ---- overdue ----------------------------------------------------------

    /** The bucketing rule IS the report. */
    public function test_the_overdue_bucket_rule(): void
    {
        $this->assertSame('red', OverdueReportData::bucketFor(50, 2), 'two months behind is not red');
        $this->assertSame('red', OverdueReportData::bucketFor(50, 7));
        $this->assertSame('yellow', OverdueReportData::bucketFor(50, 1));
        $this->assertSame('current', OverdueReportData::bucketFor(50, 0), 'this month is not "current"');
        $this->assertSame('settled', OverdueReportData::bucketFor(0, 3), 'a cleared account is not settled');
    }

    public function test_the_overdue_report_ages_and_sorts_the_debts(): void
    {
        // This subscriber is two periods behind (red), with the newer period unpaid.
        $this->bill('2026-07', 36.50);
        $this->bill('2026-09', 40.00);

        $settled = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Paid Up', 'meter_no' => '1111',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Bill::create([
            'number' => 'INV-2026-09-1111', 'subscriber_id' => $settled->id, 'period' => '2026-09',
            'type' => Subscriber::TYPE_METERED, 'usage_amount' => 10, 'maintenance_fee' => 25,
            'total' => 35, 'paid_amount' => 35,
        ]);

        // Pin "now" so the ageing is not a function of the day the suite runs.
        $this->travelTo(\Carbon\Carbon::parse('2026-09-15'));

        $rows = OverdueReportData::make()->rows();

        $this->assertSame('لينا مرعب', $rows->first()['name'], 'The debtor is not first.');
        $this->assertSame(76.50, round((float) $rows->first()['due'], 2));
        $this->assertSame('red', $rows->first()['bucket']);
        $this->assertSame('2026-07', $rows->first()['oldest']);

        // A fully paid subscriber is reported as settled, never as a debt.
        $settledRow = $rows->firstWhere('name', 'Paid Up');
        $this->assertSame('settled', $settledRow['bucket']);
        $this->assertSame(0.0, (float) $settledRow['due']);
    }

    public function test_the_overdue_report_filters_by_bucket_and_area(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-15'));

        $this->bill('2026-09', 40);   // current month -> current bucket
        $this->bill('2026-08', 30);   // one month back -> yellow

        $red = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Old Debt', 'meter_no' => '2222',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        $this->bill('2026-06', 60, 0, $red);

        // Oldest unpaid month decides: 2026-06 is two back (red), 2026-08 one back (yellow),
        // 2026-09 is current. One subscriber each.
        $this->assertCount(1, OverdueReportData::make('red')->rows());
        $this->assertCount(1, OverdueReportData::make('yellow')->rows());
        // Two subscribers in debt in total: the first has two unpaid periods, the second one.
        $this->assertCount(2, OverdueReportData::make('all')->rows());

        $otherRoute = Route::create(['name' => 'Ramlet El Baida']);
        $this->assertCount(0, OverdueReportData::make('all', $otherRoute->id)->rows(), 'An empty area reported rows.');

        Subscriber::create([
            'route_id' => $otherRoute->id, 'name' => 'Other Area', 'meter_no' => '3333',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        $this->bill('2026-05', 25, 0, Subscriber::where('name', 'Other Area')->firstOrFail());

        $filtered = OverdueReportData::make('all', $otherRoute->id)->rows();
        $this->assertCount(1, $filtered);
        $this->assertSame('Other Area', $filtered->first()['name']);
    }

    /** The CSV is what gets emailed around: no zero rows, and Arabic survives Excel. */
    public function test_the_overdue_csv_drops_settled_rows_and_carries_a_bom(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-09-15'));
        $this->bill('2026-07', 36.50);

        $settled = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Paid Up', 'meter_no' => '1111',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Bill::create([
            'number' => 'INV-2026-09-1111', 'subscriber_id' => $settled->id, 'period' => '2026-09',
            'type' => Subscriber::TYPE_METERED, 'usage_amount' => 10, 'maintenance_fee' => 25,
            'total' => 35, 'paid_amount' => 35,
        ]);

        $csv = OverdueReportData::make()->csv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'No UTF-8 BOM: Excel will mangle the Arabic.');
        $this->assertStringContainsString('لينا مرعب', $csv);
        $this->assertStringNotContainsString('Paid Up', $csv, 'A settled subscriber is noise in a debt report.');
    }

    // ---- collection report ------------------------------------------------

    public function test_the_collection_report_totals_and_splits_the_range(): void
    {
        $this->payment(100, '2026-09-10');
        $this->payment(50, '2026-09-11');
        $this->payment(999, '2026-08-01'); // outside the range

        CashHandover::create([
            'collector_id' => $this->collector->id, 'amount' => 120,
            'handover_date' => '2026-09-12', 'recorded_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(Collections::class)
            ->set('filters.from', '2026-09-01')
            ->set('filters.to', '2026-09-30')
            ->call('$refresh')
            ->assertViewHas('totals', fn (array $totals): bool => round($totals['collected'], 2) === 150.0
                && round($totals['handed'], 2) === 120.0
                && $totals['payments_count'] === 2);
    }

    /**
     * In hand is ALL-TIME, not the range.
     *
     * A range-scoped figure would show a collector holding nothing whenever the operator
     * looked at an empty week — and the drawer would not match.
     */
    public function test_in_hand_ignores_the_report_range(): void
    {
        $this->payment(300, '2025-01-01'); // long before any range on screen

        $totals = CollectionService::totals(
            \Carbon\Carbon::parse('2026-09-01'),
            \Carbon\Carbon::parse('2026-09-30'),
            $this->collector->id,
        );

        $this->assertSame(0.0, round($totals['collected'], 2), 'A payment outside the range was counted.');
        $this->assertSame(300.0, round($totals['in_hand'], 2), 'In hand was scoped to the range.');
    }

    /** A reversed range is clamped, not fatal: the operator often types the second date first. */
    public function test_the_collection_report_clamps_a_reversed_range(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)
            ->set('filters.from', '2026-09-30')
            ->set('filters.to', '2026-09-01')
            ->call('$refresh');

        $instance = $component->instance();

        $this->assertTrue($instance->to()->greaterThanOrEqualTo($instance->from()));
        $this->assertSame('2026-09-30', $instance->from()->toDateString(), 'The range moved unexpectedly.');
    }

    public function test_the_collection_report_survives_a_garbage_date(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Collections::class)
            ->set('filters.from', 'not-a-date')
            ->call('$refresh')
            ->assertOk();
    }

    public function test_the_collection_report_accepts_a_deep_link(): void
    {
        // withQueryParams, not request()->merge(): Livewire mounts the component against its
        // own request, and merging into the test's request never reaches it.
        $instance = Livewire::actingAs($this->admin)
            ->withQueryParams(['from' => '2026-08-01', 'to' => '2026-08-31', 'collector_id' => $this->collector->id])
            ->test(Collections::class)
            ->instance();

        $this->assertSame('2026-08-01', $instance->from()->toDateString());
        $this->assertSame('2026-08-31', $instance->to()->toDateString());
        $this->assertSame($this->collector->id, $instance->collector()?->id);
    }

    // ---- collector ledger -------------------------------------------------

    public function test_the_ledger_closing_balance_equals_the_cash_in_hand(): void
    {
        $this->payment(100, '2026-09-05');
        $this->payment(50, '2026-09-06');
        CashHandover::create([
            'collector_id' => $this->collector->id, 'amount' => 60,
            'handover_date' => '2026-09-07', 'recorded_by' => $this->admin->id,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->assertSee('Jad Haddad');

        $instance = $component->instance();
        $ledger = $instance->ledger();

        // 100 + 50 collected, 60 handed over.
        $this->assertSame(90.0, round($ledger['totals']['closing'], 2));
        $this->assertSame(round($instance->inHand(), 2), round($ledger['totals']['closing'], 2));

        // The closing balance must hold even when the range starts after the movements.
        $component->set('filters.from', '2026-09-06');
        $this->assertSame(90.0, round($component->instance()->ledger()['totals']['closing'], 2));
    }

    /** Another collector's money must never appear in this ledger. */
    public function test_the_ledger_only_shows_its_own_collector(): void
    {
        $other = User::create([
            'name' => 'Rami Nasser', 'email' => 'rami@pages.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->payment(100, '2026-09-05');
        $this->payment(777, '2026-09-05', $other);

        $ledger = Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->instance()
            ->ledger();

        $this->assertSame(100.0, round($ledger['totals']['closing'], 2), 'Another collector money leaked in.');
    }

    public function test_recording_a_handover_from_the_ledger_reduces_the_cash_in_hand(): void
    {
        $this->payment(200, '2026-09-05');

        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->call('openHandover')
            ->assertSet('handoverOpen', true)
            ->set('handover.amount', '120')
            ->set('handover.handover_date', '2026-09-08')
            ->call('saveHandover')
            ->assertHasNoErrors();

        // No assertSet('handoverOpen') here on purpose: a redirect response carries no updated
        // snapshot, and the page it lands on is a fresh render where the modal is closed by its
        // own default. Asserting the property would only be testing the test.

        // A flash is only feedback if something renders it. The merged screen prints the
        // confirmation inside its own markup, so the operator sees it without the page — and the
        // collector they were looking at — being thrown away by a redirect.
        $component->assertSee(__('col.handover_saved', ['amount' => money_ltr(120.0)]));

        $this->assertSame(1, CashHandover::count());
        $this->assertSame(80.0, round($component->instance()->inHand(), 2));
        $this->assertSame($this->admin->id, CashHandover::firstOrFail()->recorded_by);
    }

    /**
     * The ledger must never be driven negative by a handover.
     *
     * The modal already states what the collector is holding; recording more than that is the
     * one mistake an operator can make here, and it used to go through and leave the collector
     * with a negative balance that no report can explain.
     */
    public function test_the_quick_handover_refuses_more_than_the_collector_holds(): void
    {
        $this->payment(200, '2026-09-05');

        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->call('openHandover')
            ->set('handover.amount', '250')
            ->set('handover.handover_date', '2026-09-08')
            ->call('saveHandover')
            ->assertHasErrors('handover.amount')
            // The modal stays open with the operator's typing, so the figure can be corrected
            // in place rather than being retyped from memory.
            ->assertSet('handoverOpen', true)
            ->assertSet('handover.amount', '250');

        $this->assertSame(0, CashHandover::count(), 'A refused handover was written anyway.');
        $this->assertSame(200.0, round($component->instance()->inHand(), 2), 'The ledger moved.');
    }

    /** The whole of it is still accepted — that is the ordinary end-of-day handover. */
    public function test_the_quick_handover_accepts_exactly_what_the_collector_holds(): void
    {
        $this->payment(200, '2026-09-05');

        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->call('openHandover')
            ->set('handover.amount', '200')
            ->set('handover.handover_date', '2026-09-08')
            ->call('saveHandover')
            ->assertHasNoErrors();

        $this->assertSame(1, CashHandover::count());
        $this->assertSame(0.0, round($component->instance()->inHand(), 2));
    }

    public function test_a_handover_of_zero_is_refused(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->call('openHandover')
            ->set('handover.amount', '0')
            ->call('saveHandover')
            ->assertHasErrors('handover.amount');

        $this->assertSame(0, CashHandover::count());
    }

    // ---- profit and loss --------------------------------------------------

    public function test_the_profit_loss_report_nets_collections_against_expenses(): void
    {
        $this->payment(500, '2026-09-10');
        $this->bill('2026-09', 400);

        Expense::create([
            'category' => Expense::CAT_FUEL, 'expense_date' => '2026-09-05',
            'amount' => 180, 'description' => 'Diesel', 'entered_by' => $this->admin->id,
        ]);
        Expense::create([
            'category' => Expense::CAT_RENT, 'expense_date' => '2026-08-31',
            'amount' => 400, 'description' => 'August rent', 'entered_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin)
            ->test(ProfitLoss::class)
            ->set('filters.month', '2026-09')
            ->call('$refresh')
            ->assertViewHas('report', function (array $report): bool {
                // Invoiced is the promise; collected is the fact. Only September's expense
                // counts, and August's rent must not leak in.
                return round($report['invoiced'], 2) === 400.0
                    && round($report['collected'], 2) === 500.0
                    && round($report['expenses'], 2) === 180.0
                    && round($report['net'], 2) === 320.0
                    && $report['byCategory']->count() === 1;
            });
    }

    // ---- settings ---------------------------------------------------------

    public function test_settings_load_the_stored_values_and_save_them_back(): void
    {
        Setting::set('kwh_price', '0.30');
        Setting::set('company_name', 'Generator Sal');

        $component = Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->assertSet('state.kwh_price', '0.30')
            ->assertSet('state.company_name', 'Generator Sal')
            ->set('state.kwh_price', '0.42')
            ->set('state.default_maintenance_fee', '30')
            ->set('state.company_phone', '03 123456')
            ->call('save')
            ->assertHasNoErrors()
            // see the handover test: without a redirect the saved confirmation is never rendered
            ->assertRedirect(route('admin.settings'));

        $this->assertSame('0.42', Setting::get('kwh_price'));
        $this->assertSame('30', Setting::get('default_maintenance_fee'));
        $this->assertSame('03 123456', Setting::get('company_phone'));
        $this->assertSame('Generator Sal', Setting::get('company_name'), 'An untouched field was blanked.');
    }

    /** A negative kWh price would silently zero every invoice the run produces. */
    public function test_settings_refuse_negative_billing_values(): void
    {
        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->set('state.kwh_price', '-1')
            ->call('save')
            ->assertHasErrors('state.kwh_price');

        Livewire::actingAs($this->admin)
            ->test(Settings::class)
            ->set('state.default_maintenance_fee', 'abc')
            ->call('save')
            ->assertHasErrors('state.default_maintenance_fee');
    }

    // ---- filters ----------------------------------------------------------

    public function test_resetting_the_filters_restores_the_defaults(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(Collections::class)
            ->set('filters.from', '2020-01-01')
            ->set('filters.to', '2020-01-31')
            ->call('resetFilters');

        $this->assertSame(now()->startOfMonth()->toDateString(), $component->get('filters.from'));
        $this->assertSame(now()->toDateString(), $component->get('filters.to'));
    }
}
