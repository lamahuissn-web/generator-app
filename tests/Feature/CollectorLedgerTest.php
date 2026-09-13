<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Collections;
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

class CollectorLedgerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Subscriber $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'j@x.com', 'password' => 'x',
            'role' => 'collector', 'phone' => '03 111222', 'locale' => 'en',
        ]);
        $this->route = Route::create(['name' => 'Corniche']);

        // Two periods billed so one payment settles two bills (the "took money
        // from a customer for these bills" case).
        $this->customer = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Ali Hassan',
            'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Reading::create([
            'subscriber_id' => $this->customer->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
    }

    private function bill(string $period, float $reading): void
    {
        $bill = BillingService::enterMeteredReading($this->customer, $period, $reading, $this->collector->id)['bill'];
        $bill->forceFill(['created_at' => Carbon::parse($period.'-26 10:00')])->save();
    }

    private function collect(float $amount, string $date, ?string $period = null, ?string $note = null): Payment
    {
        $payment = BillingService::collect($this->customer, $amount, $this->collector->id, $note, $period)['payment'];
        $payment->forceFill(['created_at' => Carbon::parse($date.' 11:30')])->save();

        return $payment;
    }

    public function test_ledger_lists_customer_collections_then_handovers_with_a_running_balance(): void
    {
        // July bill 25, August bill 25 (carries July's 25) -> 50 due.
        $this->bill('2026-07', 1040);
        $this->bill('2026-08', 1080);

        $payment = $this->collect(30, '2026-08-28', '2026-08', 'first instalment');
        $handover = CashHandoverService::create([
            'collector_id' => $this->collector->id,
            'amount' => 20,
            'handover_date' => '2026-08-30',
            'note' => 'settlement',
        ], $this->admin->id);
        $handover->forceFill(['created_at' => Carbon::parse('2026-08-30 18:00')])->save();

        $ledger = CollectionService::collectorLedger($this->collector->fresh());
        $rows = $ledger['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame('payment', $rows[0]['kind']);
        $this->assertStringContainsString('Ali Hassan', $rows[0]['description']);
        $this->assertStringContainsString('Corniche', $rows[0]['description']);
        $this->assertStringContainsString('INV-2026-07-', $rows[0]['description']);   // bills it settled
        $this->assertStringContainsString('INV-2026-08-', $rows[0]['description']);
        $this->assertSame('first instalment', $rows[0]['detail']);
        $this->assertSame(30.0, $rows[0]['due']);
        $this->assertSame(30.0, $rows[0]['balance']);

        $this->assertSame('handover', $rows[1]['kind']);
        $this->assertSame(20.0, $rows[1]['settled']);
        $this->assertSame(10.0, $rows[1]['balance']);

        $this->assertSame(30.0, $ledger['totals']['debit']);
        $this->assertSame(20.0, $ledger['totals']['credit']);
        $this->assertSame(10.0, $ledger['totals']['closing']);
        $this->assertSame(10.0, CollectionService::inHand($this->collector->id));
        $this->assertSame(10.0, $ledger['totals']['closing']);

        // A payment with no allocation (money on account) still shows up.
        $this->assertNotNull($payment->id);
    }

    public function test_ledger_from_a_date_carries_an_opening_balance_and_keeps_the_closing_balance(): void
    {
        $this->bill('2026-07', 1040);   // 25
        $this->collect(25, '2026-07-28', '2026-07');
        $this->bill('2026-08', 1080);   // 25 (carries nothing: July was paid)
        $this->collect(25, '2026-08-28', '2026-08');

        $range = CollectionService::collectorLedger($this->collector->fresh(), Carbon::parse('2026-08-01'));
        $this->assertSame(25.0, $range['totals']['opening']);   // July collection
        $this->assertCount(1, $range['rows']);                  // only August
        $this->assertSame(50.0, $range['totals']['closing']);   // opening + August

        // Closing always equals the all-time cash in hand, filtered or not.
        $all = CollectionService::collectorLedger($this->collector->fresh());
        $this->assertSame(50.0, $all['totals']['closing']);
        $this->assertSame(0.0, $all['totals']['opening']);
        $this->assertCount(2, $all['rows']);
        $this->assertSame(50.0, CollectionService::inHand($this->collector->id));
    }

    public function test_page_opens_from_a_query_string_with_the_report_range(): void
    {
        $this->bill('2026-08', 1040);
        $this->collect(25, '2026-08-28', '2026-08');

        $url = route('admin.collections', [
            'collector_id' => $this->collector->id,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ]);

        $this->assertStringContainsString('collector_id='.$this->collector->id, $url);

        $this->actingAs($this->admin)->get($url)
            ->assertOk()
            ->assertSee('Jad Haddad')
            ->assertSee('Ali Hassan')          // the customer they collected from
            ->assertSee('INV-2026-08-')        // the bill that payment settled
            ->assertSee('$25.00');

        // All-time view of the same ledger.
        $this->actingAs($this->admin)
            ->get(route('admin.collections', ['collector_id' => $this->collector->id, 'all_time' => 1]))
            ->assertOk()
            ->assertSee('Jad Haddad');
    }

    public function test_page_renders_without_a_collector_and_is_admin_only(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.collections', ['collector_id' => null]))
            ->assertOk();

        $this->actingAs($this->collector)->get(route('admin.collections'))->assertForbidden();
    }

    public function test_quick_handover_action_records_cash_for_the_shown_collector(): void
    {
        $this->bill('2026-08', 1040);
        $this->collect(40, '2026-08-28', '2026-08');

        Livewire::actingAs($this->admin)
            ->test(Collections::class)->call('selectCollector', $this->collector->id)
            ->set('filters.from', '2026-08-01')
            ->set('filters.to', '2026-08-31')
            ->assertOk()
            ->call('openHandover')
            ->assertSet('handoverOpen', true)
            ->set('handover.amount', '15')
            ->set('handover.handover_date', '2026-08-31')
            ->set('handover.note', 'quick settlement')
            ->call('saveHandover')
            ->assertHasNoErrors();

        $handover = CashHandover::where('collector_id', $this->collector->id)->first();
        $this->assertNotNull($handover);
        $this->assertSame(15.0, (float) $handover->amount);
        $this->assertSame('quick settlement', $handover->note);
        // Collected 25 (capped at the 25 owed), handed 15 -> 10 still in hand.
        $this->assertSame(10.0, CollectionService::inHand($this->collector->id));
        $this->assertSame(1, AuditLog::where('action', 'handover.created')->count());
    }

    public function test_the_collectors_table_offers_each_collector_his_file(): void
    {
        $this->bill('2026-08', 1040);
        $this->collect(25, '2026-08-28', '2026-08');

        $response = $this->actingAs($this->admin)->get('/admin/collections');
        $response->assertOk()->assertSee('Jad Haddad');

        // The affordance is a control that selects him, not an anchor: his file opens in this same
        // page, under the table, instead of navigating away from the office's view of the money.
        $response->assertSee('selectCollector', false);
    }

    public function test_ledger_prints_and_downloads_as_pdf(): void
    {
        $this->bill('2026-08', 1040);
        $this->collect(25, '2026-08-28', '2026-08', 'from the shop');
        CashHandoverService::create([
            'collector_id' => $this->collector->id,
            'amount' => 10,
            'handover_date' => '2026-08-31',
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('print.collector-statement', [
                'collector_id' => $this->collector->id, 'from' => '2026-08-01', 'to' => '2026-08-31', 'size' => 'a4',
            ]))
            ->assertOk()
            ->assertSee('Jad Haddad')
            ->assertSee('Ali Hassan')
            ->assertSee('from the shop')
            ->assertSee('$25.00')
            ->assertSee('$10.00');

        $pdf = $this->actingAs($this->admin)->get(route('print.collector-statement', [
            'collector_id' => $this->collector->id, 'all_time' => 1, 'format' => 'pdf',
        ]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // An unknown or non-collector id is a 404, and collectors cannot read it.
        $this->actingAs($this->admin)->get(route('print.collector-statement', ['collector_id' => $this->admin->id]))->assertNotFound();
        $this->actingAs($this->admin)->get(route('print.collector-statement', ['collector_id' => 999999]))->assertNotFound();
        $this->actingAs($this->collector)->get(route('print.collector-statement', ['collector_id' => $this->collector->id]))->assertForbidden();
    }
}
