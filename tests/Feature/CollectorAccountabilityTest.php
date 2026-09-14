<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The office settles ONE number: what the collector owes it (specs/RISKS.md §1).
 *
 * In the office's own words: «ما بهمني شو قبض الجابي سواء كاش او الكتروني، بس يحاسب المكتب، راح شوف
 * المبلغ المتوجب عليه». How a subscriber paid is a fact about the receipt and stays recorded there;
 * the figure the office works against is the money the collector collected and has not handed over
 * yet — every method alike.
 *
 * An earlier version of this change split cash from electronic and showed two figures. The office
 * turned it down: it is accountable for money, not for the form the money arrived in. These tests
 * pin what it asked for instead, so the split cannot come back by accident.
 */
class CollectorAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'مدير', 'email' => 'admin@owe.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@owe.test', 'password' => bcrypt('secret'),
            'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => 'محمد عيتاني', 'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED, 'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        Bill::create([
            'number' => 'INV-2026-08-9001', 'subscriber_id' => $this->subscriber->id,
            'period' => '2026-08', 'type' => Subscriber::TYPE_METERED, 'total' => 100.0,
        ]);
    }

    private function receipt(float $amount, string $method): Payment
    {
        return BillingService::collect(
            $this->subscriber, $amount, $this->collector->id, null, null, $method,
        )['payment'];
    }

    public function test_a_whish_receipt_counts_toward_what_he_owes(): void
    {
        $this->receipt(30.0, Payment::METHOD_WHISH);

        $this->assertSame(30.0, CollectionService::inHand($this->collector->id));
    }

    public function test_a_mixed_day_is_one_figure(): void
    {
        $this->receipt(20.0, Payment::METHOD_CASH_USD);
        $this->receipt(50.0, Payment::METHOD_OMT);

        $this->assertSame(70.0, CollectionService::inHand($this->collector->id));
    }

    public function test_a_settlement_may_cover_electronic_money(): void
    {
        $this->receipt(20.0, Payment::METHOD_CASH_USD);
        $this->receipt(50.0, Payment::METHOD_OMT);

        // What he collected is what he settles — the drawer takes cash, and a transfer into the
        // office's wallet is settled the same way: by recording it against his name.
        $this->assertSame(70.0, CashHandoverService::availableFor($this->collector->id));

        CashHandoverService::create([
            'collector_id' => $this->collector->id, 'amount' => 70.0, 'handover_date' => '2026-09-14',
        ]);

        $this->assertSame(0.0, CollectionService::inHand($this->collector->id));
    }

    public function test_the_method_stays_on_the_receipt(): void
    {
        $payment = $this->receipt(30.0, Payment::METHOD_WHISH);

        // The office does not settle by method — but the receipt still says how the money arrived,
        // which is what makes a later question answerable.
        $this->assertSame(Payment::METHOD_WHISH, $payment->fresh()->method);
        $this->assertNotSame('', $payment->methodLabel());
    }

    public function test_the_ledger_and_the_trail_close_on_the_owed_figure(): void
    {
        $this->receipt(20.0, Payment::METHOD_CASH_USD);
        $this->receipt(50.0, Payment::METHOD_OMT);

        $owed = CollectionService::inHand($this->collector->id);

        $ledger = CollectionService::collectorLedger($this->collector->fresh());
        $this->assertSame($owed, (float) $ledger['totals']['closing']);

        $trail = CollectionService::collectorAuditTrail($this->collector->fresh(), 50);
        $this->assertSame($owed, (float) $trail['closing']);

        // One number on the screen: no row carries a cash/electronic split.
        $this->assertFalse(collect($ledger['rows'])->contains(fn (array $row) => array_key_exists('electronic', $row)));
        $this->assertFalse(collect($trail['rows'])->contains(fn (array $row) => array_key_exists('electronic', $row)));
    }

    public function test_every_method_counts_in_the_period_total(): void
    {
        $this->receipt(20.0, Payment::METHOD_CASH_USD);
        $this->receipt(50.0, Payment::METHOD_OMT);

        $this->assertSame(70.0, CollectionService::collectedBetween(
            now()->copy()->startOfMonth(), now()->copy()->endOfMonth(),
        ));
    }
}
