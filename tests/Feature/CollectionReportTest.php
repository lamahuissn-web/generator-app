<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\CollectionReport;
use App\Models\AuditLog;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CollectionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collectorA;

    private User $collectorB;

    private Route $routeA;

    private Route $routeB;

    private Subscriber $subA;

    private Subscriber $subB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->collectorA = User::create(['name' => 'Collector A', 'email' => 'a@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);
        $this->collectorB = User::create(['name' => 'Collector B', 'email' => 'b@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);

        $this->routeA = Route::create(['name' => 'Area A']);
        $this->routeB = Route::create(['name' => 'Area B']);

        // September bills created on the 1st, collections on the 2nd/3rd.
        $this->subA = $this->flatSubscriber('Flat A', $this->routeA, 60);
        $this->subB = $this->flatSubscriber('Flat B', $this->routeB, 40);

        $this->pay($this->subA, 60, $this->collectorA, '2026-09-02');
        $this->pay($this->subB, 40, $this->collectorB, '2026-09-03');
    }

    private function flatSubscriber(string $name, Route $route, float $fee): Subscriber
    {
        $sub = Subscriber::create([
            'route_id' => $route->id,
            'name' => $name,
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $bill = BillingService::ensureFlatBill($sub, '2026-09');
        $bill->forceFill(['created_at' => Carbon::parse('2026-09-01 09:00')])->save();

        return $sub;
    }

    private function pay(Subscriber $sub, float $amount, User $collector, string $date): Payment
    {
        $payment = BillingService::collect($sub, $amount, $collector->id, null, '2026-09')['payment'];
        $payment->forceFill(['created_at' => Carbon::parse($date.' 11:00')])->save();

        return $payment;
    }

    public function test_totals_count_only_payments_inside_the_range(): void
    {
        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        $this->assertSame(100.0, CollectionService::collectedBetween($from, $to));
        $this->assertSame(60.0, CollectionService::collectedBetween($from, $to, $this->collectorA->id));
        $this->assertSame(60.0, CollectionService::collectedBetween($from, $to, null, $this->routeA->id));

        // A range that ends before the payments shows nothing.
        $this->assertSame(0.0, CollectionService::collectedBetween(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31')));

        $totals = CollectionService::totals($from, $to);
        $this->assertSame(100.0, $totals['collected']);
        $this->assertSame(2, $totals['payments_count']);
        $this->assertSame(0.0, $totals['handed']);
        $this->assertSame(100.0, $totals['in_hand']);
    }

    public function test_by_collector_reports_range_activity_and_all_time_cash_in_hand(): void
    {
        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        CashHandoverService::create([
            'collector_id' => $this->collectorA->id,
            'amount' => 25,
            'handover_date' => '2026-09-05',
            'note' => 'Partial',
        ], $this->admin->id);

        $byName = CollectionService::byCollector($from, $to)->keyBy('name');

        $this->assertSame(60.0, $byName['Collector A']['collected']);
        $this->assertSame(25.0, $byName['Collector A']['handed']);
        $this->assertSame(35.0, $byName['Collector A']['in_hand']);
        $this->assertSame(1, $byName['Collector A']['payments_count']);

        $this->assertSame(40.0, $byName['Collector B']['collected']);
        $this->assertSame(0.0, $byName['Collector B']['handed']);
        $this->assertSame(40.0, $byName['Collector B']['in_hand']);

        // Collective cash position: 100 collected all time, 25 handed over.
        $this->assertSame(75.0, CollectionService::inHand());

        // A handover dated outside the range reduces the all-time cash in hand
        // but is not counted as handed over inside the range.
        CashHandoverService::create([
            'collector_id' => $this->collectorB->id,
            'amount' => 10,
            'handover_date' => '2026-08-20',
        ], $this->admin->id);

        $august = CollectionService::totals(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $this->assertSame(0.0, $august['collected']);
        $this->assertSame(10.0, $august['handed']);
        $this->assertSame(65.0, $august['in_hand']); // 100 collected all time - 35 handed all time
    }

    public function test_by_route_and_by_day_groupings(): void
    {
        $extra = $this->flatSubscriber('Flat A2', $this->routeA, 30);
        $this->pay($extra, 30, $this->collectorA, '2026-09-04');

        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-09-30');

        $routes = CollectionService::byRoute($from, $to)->keyBy('name');
        $this->assertSame(90.0, $routes['Area A']['collected']);
        $this->assertSame(2, $routes['Area A']['payments_count']);
        $this->assertSame(40.0, $routes['Area B']['collected']);

        $days = CollectionService::byDay($from, $to)->keyBy('day');
        $this->assertSame(60.0, $days['2026-09-02']['collected']);
        $this->assertSame(40.0, $days['2026-09-03']['collected']);
        $this->assertSame(30.0, $days['2026-09-04']['collected']);

        // Scoping by collector drops the other collector's area.
        $this->assertSame(
            ['Area A'],
            CollectionService::byRoute($from, $to, $this->collectorA->id)->pluck('name')->all()
        );
    }

    public function test_handover_service_audits_and_reports_cash_in_hand(): void
    {
        $handover = CashHandoverService::create([
            'collector_id' => $this->collectorA->id,
            'amount' => 20,
            'handover_date' => '2026-09-06',
            'note' => 'first',
        ], $this->admin->id);

        $this->assertSame(40.0, CollectionService::inHand($this->collectorA->id));
        $this->assertSame(1, AuditLog::where('action', 'handover.created')->count());

        CashHandoverService::update($handover, [
            'collector_id' => $this->collectorA->id,
            'amount' => 35,
            'handover_date' => '2026-09-06',
            'note' => 'corrected',
        ]);
        $this->assertSame(25.0, CollectionService::inHand($this->collectorA->id));
        $this->assertSame(1, AuditLog::where('action', 'handover.updated')->count());

        CashHandoverService::delete($handover->fresh());
        $this->assertSame(60.0, CollectionService::inHand($this->collectorA->id));
        $this->assertDatabaseCount('cash_handovers', 0);
        $this->assertSame(1, AuditLog::where('action', 'handover.deleted')->count());
    }

    public function test_statement_lines_close_at_the_outstanding_balance(): void
    {
        $sub = $this->meteredSubscriber();

        $july = BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id)['bill']; // 40 kWh + 15 = 25
        $august = BillingService::enterMeteredReading($sub, '2026-08', 1080, $this->admin->id)['bill']; // 10 + 15 = 25
        $july->forceFill(['created_at' => Carbon::parse('2026-07-26 10:00')])->save();
        $august->forceFill(['created_at' => Carbon::parse('2026-08-26 10:00')])->save();

        $payment = BillingService::collect($sub, 30, $this->collectorA->id, null, '2026-08')['payment'];
        $payment->forceFill(['created_at' => Carbon::parse('2026-08-28 12:00')])->save();

        $statement = CollectionService::statement($sub->fresh());
        $rows = $statement['rows'];

        $this->assertCount(3, $rows);
        $this->assertSame('bill', $rows[0]['kind']);
        $this->assertSame(25.0, $rows[0]['balance']);
        $this->assertSame(50.0, $rows[1]['balance']);   // August bill on top of July's unpaid 25
        $this->assertSame(20.0, $rows[2]['balance']);   // 50 - 30 collected
        $this->assertSame(20.0, $statement['totals']['closing']);
        $this->assertSame(50.0, $statement['totals']['debit']);
        $this->assertSame(30.0, $statement['totals']['credit']);

        // Closing balance always equals what the subscriber still owes.
        $this->assertSame($sub->fresh()->outstanding(), $statement['totals']['closing']);
    }

    public function test_statement_from_a_date_carries_an_opening_balance(): void
    {
        $sub = $this->meteredSubscriber();
        $july = BillingService::enterMeteredReading($sub, '2026-07', 1040, $this->admin->id)['bill']; // 25
        $july->forceFill(['created_at' => Carbon::parse('2026-07-26 10:00')])->save();

        $payment = BillingService::collect($sub, 10, $this->collectorA->id, null, '2026-07')['payment'];
        $payment->forceFill(['created_at' => Carbon::parse('2026-08-05 09:00')])->save();

        // From August: July's bill is the opening balance, only the payment is listed.
        $august = CollectionService::statement($sub->fresh(), Carbon::parse('2026-08-01'));
        $this->assertSame(25.0, $august['totals']['opening']);
        $this->assertCount(1, $august['rows']);
        $this->assertSame('payment', $august['rows'][0]['kind']);
        $this->assertSame(15.0, $august['totals']['closing']);
        $this->assertSame(2, $august['rows_total']);
        $this->assertSame(1, $august['rows_shown']);

        // Everything: two movements, no opening line.
        $all = CollectionService::statement($sub->fresh());
        $this->assertCount(2, $all['rows']);
        $this->assertSame(0.0, $all['totals']['opening']);
        $this->assertSame(15.0, $all['totals']['closing']);
        $this->assertSame(25.0, $all['totals']['debit']);
        $this->assertSame(10.0, $all['totals']['credit']);
    }

    private function meteredSubscriber(): Subscriber
    {
        $sub = Subscriber::create([
            'route_id' => $this->routeA->id,
            'name' => 'Metered One',
            'meter_no' => 'M-9',
            'type' => Subscriber::TYPE_METERED,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        Reading::create([
            'subscriber_id' => $sub->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);

        return $sub;
    }

    public function test_report_page_filters_and_exports(): void
    {
        $this->actingAs($this->admin)->get('/admin/collection-report')->assertOk()->assertSee('Collector A');

        $component = Livewire::actingAs($this->admin)
            ->test(CollectionReport::class)
            ->set('filters.from', '2026-09-01')
            ->set('filters.to', '2026-09-30')
            ->assertOk()
            ->assertSee('Collector A')
            ->assertSee('Collector B')
            // The CSV and the print/PDF links are on the screen; the PDF itself is asserted
            // below through its route, which is what actually has to work.
            ->assertSee(__('col.export'));

        $this->assertSame(100.0, $component->instance()->totals()['collected']);
        $this->assertCount(2, $component->instance()->collectors());

        // Scoping by collector narrows the reconciliation table to one row.
        $component->set('filters.collector_id', $this->collectorA->id);
        $this->assertCount(1, $component->instance()->collectors());
        $this->assertSame('Collector A', $component->instance()->collectors()->first()['name']);
        $this->assertSame(60.0, $component->instance()->totals()['collected']);
        $this->assertSame('Area A', $component->instance()->routeRows()->first()['name']);

        // The print view is driven by the same filters.
        $this->assertSame(
            ['from' => '2026-09-01', 'to' => '2026-09-30', 'collector_id' => $this->collectorA->id],
            $component->instance()->exportParams()
        );
    }

    public function test_handover_resource_pages_render_and_the_report_prints(): void
    {
        $handover = CashHandover::create([
            'collector_id' => $this->collectorA->id,
            'amount' => 20,
            'handover_date' => '2026-09-06',
            'recorded_by' => $this->admin->id,
        ]);

        foreach ([
            '/admin/handovers',
            '/admin/handovers/create',
            '/admin/handovers/'.$handover->id.'/edit',
        ] as $path) {
            $this->actingAs($this->admin)->get($path)->assertOk($path.' failed to render');
        }

        $this->actingAs($this->admin)
            ->get(route('print.collection-report', ['from' => '2026-09-01', 'to' => '2026-09-30', 'size' => 'a4']))
            ->assertOk()
            ->assertSee('Collector A')
            ->assertSee('$100.00')
            ->assertSee('$20.00');

        $pdf = $this->actingAs($this->admin)->get(route('print.collection-report', [
            'from' => '2026-09-01', 'to' => '2026-09-30', 'format' => 'pdf',
        ]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Finance data: collectors must not reach the report.
        $this->actingAs($this->collectorA)->get(route('print.collection-report'))->assertForbidden();
        $this->actingAs($this->collectorA)->get('/admin/collection-report')->assertForbidden();
    }

    public function test_statement_prints_for_admin_and_collector_with_pdf(): void
    {
        $this->actingAs($this->admin)
            ->get(route('print.statement', ['subscriber' => $this->subA->id, 'size' => 'a4']))
            ->assertOk()
            ->assertSee('Flat A')
            ->assertSee('Account statement')
            ->assertSee('$60.00');

        $pdf = $this->actingAs($this->collectorA)->get(route('print.statement', [
            'subscriber' => $this->subA->id, 'format' => 'pdf',
        ]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_collector_dashboard_shows_cash_in_hand(): void
    {
        CashHandoverService::create([
            'collector_id' => $this->collectorA->id,
            'amount' => 20,
            'handover_date' => '2026-09-06',
        ], $this->admin->id);

        $this->actingAs($this->collectorA)
            ->get('/collector')
            ->assertOk()
            ->assertSee('$40.00')   // 60 collected - 20 handed over
            ->assertSee('2026-09-06');
    }
}
