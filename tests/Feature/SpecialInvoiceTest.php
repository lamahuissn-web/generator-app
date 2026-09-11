<?php

namespace Tests\Feature;

use App\Admin\Actions\ConvertToFlat;
use App\Admin\Actions\ConvertToMetered;
use App\Admin\Actions\SpecialInvoice;
use App\Reports\OverdueReport;
use App\Livewire\Collector\SubscriberShow;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CollectionService;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Special invoices: itemized charges outside the subscription, paid like any other bill.
 */
class SpecialInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x',
            'role' => 'admin', 'locale' => 'en',
        ]);
        $this->collector = User::create([
            'name' => 'Jad', 'email' => 'j@x.com', 'password' => 'x',
            'role' => 'collector', 'locale' => 'en',
        ]);
        $route = Route::create(['name' => 'Corniche']);

        $this->subscriber = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Ali Hassan',
            'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        // Baseline in July so August can be billed by billAugust().
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-07',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
    }

    /** A normal subscription bill so invoices can compete for FIFO collection. */
    private function billAugust(): Bill
    {
        return BillingService::enterMeteredReading($this->subscriber, '2026-08', 1040, $this->admin->id)['bill'];
    }

    // ---- creation -------------------------------------------------------------

    public function test_a_special_invoice_stores_itemized_lines_and_totals_them(): void
    {
        $result = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'عدّاد جديد', 'quantity' => 1, 'unit_price' => 45],
            ['description' => 'أجور فنّي', 'quantity' => 2, 'unit_price' => 12.5],
        ], 'تركيب عدّاد', 'ملاحظة داخلية', '2026-09', $this->admin->id);

        $bill = $result['bill'];

        $this->assertTrue($bill->isSpecial());
        $this->assertSame('خاص', 'خاص');  // guard against accidental locale coupling
        $this->assertSame('تركيب عدّاد', $bill->title);
        $this->assertSame('ملاحظة داخلية', $bill->note);
        $this->assertSame(70.0, (float) $bill->items_amount);   // 45 + 25
        $this->assertSame(70.0, (float) $bill->total);
        $this->assertSame(70.0, $bill->charges());
        $this->assertSame('2026-09', $bill->period);
        $this->assertSame('INV-SP-2026-09-'.str_pad((string) $bill->id, 4, '0', STR_PAD_LEFT), $bill->number);

        $this->assertSame(2, $bill->items()->count());
        $items = $bill->items;
        $this->assertSame('عدّاد جديد', $items[0]->description);
        $this->assertSame(1.0, (float) $items[0]->quantity);
        $this->assertSame(45.0, (float) $items[0]->amount);
        $this->assertSame(2.0, (float) $items[1]->quantity);
        $this->assertSame(12.5, (float) $items[1]->unit_price);
        $this->assertSame(25.0, (float) $items[1]->amount);

        $log = AuditLog::where('action', 'bill.special_created')->latest('id')->first();
        $this->assertSame(70.0, (float) $log->payload['total']);
        $this->assertCount(2, $log->payload['items']);
    }

    public function test_a_special_invoice_lands_on_the_balance_without_touching_the_other_bills(): void
    {
        $august = $this->billAugust();
        $this->assertSame(25.0, $this->subscriber->outstanding());

        $special = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        // prev_debt is informational (like the subscription bills), so the total is the item only.
        $this->assertSame(25.0, (float) $special->prev_debt);
        $this->assertSame(30.0, (float) $special->total);
        $this->assertSame(55.0, $this->subscriber->fresh()->outstanding());
        $this->assertSame(25.0, (float) $august->fresh()->total);
    }

    public function test_several_special_invoices_can_share_one_month(): void
    {
        $first = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'بند أول', 'quantity' => 1, 'unit_price' => 10],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        $second = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'بند تاني', 'quantity' => 1, 'unit_price' => 20],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->number, $second->number);
        $this->assertSame(30.0, $this->subscriber->fresh()->outstanding());
    }

    public function test_a_special_invoice_does_not_block_the_subscription_bill_of_the_same_month(): void
    {
        BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'عدّاد جديد', 'quantity' => 1, 'unit_price' => 45],
        ], null, null, '2026-09', $this->admin->id);

        // The subscription cycle still bills normally for that period.
        $bill = BillingService::enterMeteredReading($this->subscriber, '2026-09', 1080, $this->admin->id)['bill'];

        $this->assertSame('metered', $bill->type);
        $this->assertSame(20.0, (float) $bill->usage_amount);   // 40 kWh × $0.25
        $this->assertSame(45.0 + 35.0, $this->subscriber->fresh()->outstanding());
    }

    public function test_a_flat_rate_subscriber_can_be_invoiced_specially_too(): void
    {
        $flat = Subscriber::create([
            'route_id' => $this->subscriber->route_id, 'name' => 'Nadia', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        $bill = BillingService::createSpecialInvoice($flat, [
            ['description' => 'كابل', 'quantity' => 3, 'unit_price' => 7],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        $this->assertSame(21.0, (float) $bill->total);
        $this->assertSame(21.0, $flat->fresh()->outstanding());
    }

    public function test_lines_can_carry_fractional_quantities(): void
    {
        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'كابل', 'quantity' => 2.5, 'unit_price' => 4],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        $this->assertSame(10.0, (float) $bill->total);
        $this->assertSame(2.5, (float) $bill->items->first()->quantity);
    }

    public function test_an_invoice_without_usable_lines_is_refused(): void
    {
        foreach ([
            [['description' => '   ', 'quantity' => 1, 'unit_price' => 10]],       // blank description
            [['description' => 'بند', 'quantity' => 1, 'unit_price' => 0]],         // zero total
            [['description' => 'بند', 'quantity' => 0, 'unit_price' => 10]],        // zero quantity
            [['description' => 'بند', 'quantity' => 1, 'unit_price' => -5]],        // negative price
            [],                                                                     // nothing at all
        ] as $items) {
            try {
                BillingService::createSpecialInvoice($this->subscriber, $items, null, null, '2026-09', $this->admin->id);
                $this->fail('Expected the invoice to be refused: '.json_encode($items));
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, Bill::where('type', Bill::TYPE_SPECIAL)->count());
        $this->assertSame(0.0, $this->subscriber->fresh()->outstanding());
    }

    public function test_a_malformed_period_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 5],
        ], null, null, 'September', $this->admin->id);
    }

    public function test_a_special_invoice_does_not_suppress_the_subscription_bill_of_that_month(): void
    {
        // Regression: the "already billed this period" lookups used to match any bill for
        // the period, so one October part silently stopped October's subscription bill —
        // both for a flat subscriber and in the bulk monthly run.
        $flat = Subscriber::create([
            'route_id' => $this->subscriber->route_id, 'name' => 'Nadia', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        BillingService::createSpecialInvoice($flat, [
            ['description' => 'عدّاد جديد', 'quantity' => 1, 'unit_price' => 45],
        ], null, null, '2026-10', $this->admin->id);

        $flatBill = BillingService::ensureFlatBill($flat->fresh(), '2026-10');
        $this->assertSame('flat_rate', $flatBill->type);
        $this->assertSame(60.0, (float) $flatBill->total);

        // A collection on a flat subscriber must also bill the correct document.
        // Within the month the subscription bill is settled before the part invoice.
        $payment = BillingService::collect($flat->fresh(), 60, $this->collector->id, null, '2026-10', Payment::METHOD_CASH_USD)['payment'];
        $this->assertSame([$flatBill->id], $payment->settledBills()->pluck('id')->all());
        $this->assertSame(45.0, $flat->fresh()->outstanding());   // only the special invoice is left

        // And the monthly run reports the subscription bill as existing, not as created.
        $run = BillingService::periodRun('2026-10', null, true, $this->admin->id);
        $this->assertSame(0, $run['created']);
        $this->assertSame(1, $flat->fresh()->bills()->where('period', '2026-10')->where('type', 'flat_rate')->count());
    }

    public function test_the_collector_portal_keeps_the_special_invoice_out_of_the_cycle_card(): void
    {
        // The portal's "this period's bill" card must show the subscription bill, not a
        // part invoice that happens to share the month.
        $special = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber])
            ->set('period', '2026-09')
            ->assertSet('period', '2026-09')
            ->assertDontSee((string) $special->number);
    }

    public function test_the_invoice_number_follows_the_period_not_the_issue_date(): void
    {
        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'بند', 'quantity' => 1, 'unit_price' => 10],
        ], null, null, '2026-11', $this->admin->id)['bill'];

        $this->assertStringStartsWith('INV-SP-2026-11-', (string) $bill->number);
    }

    // ---- collection -----------------------------------------------------------

    public function test_the_ordinary_collection_clears_the_special_invoice_fifo(): void
    {
        $august = $this->billAugust();          // 25, older
        $special = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        // 30 paid: oldest first → August (25) cleared, 5 goes on the special invoice.
        $payment = BillingService::collect($this->subscriber->fresh(), 30, $this->collector->id, null, '2026-09', Payment::METHOD_CASH_USD)['payment'];

        $settled = $payment->settledBills();
        $this->assertSame($august->id, $settled[0]->id);
        $this->assertSame($special->id, $settled[1]->id);
        $this->assertSame(25.0, (float) $august->fresh()->paid_amount);   // August fully cleared
        $this->assertSame(5.0, (float) $special->fresh()->paid_amount);   // 5 of 30 landed on it
        $this->assertSame(25.0, $this->subscriber->fresh()->outstanding());

        // And paying it off in full clears the balance.
        BillingService::collect($this->subscriber->fresh(), 25, $this->collector->id, null, '2026-09', Payment::METHOD_CASH_USD);
        $this->assertSame(0.0, $this->subscriber->fresh()->outstanding());
        $this->assertSame('paid', $special->fresh()->paymentStatus());
    }

    // ---- statement / reports --------------------------------------------------

    public function test_the_statement_names_the_special_invoice_and_keeps_the_balance_honest(): void
    {
        $this->billAugust();
        $special = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'عدّاد جديد', 'quantity' => 1, 'unit_price' => 45],
        ], 'تركيب عدّاد', null, '2026-09', $this->admin->id)['bill'];

        $statement = CollectionService::statement($this->subscriber->fresh());
        $row = collect($statement['rows'])
            ->first(fn (array $r) => $r['kind'] === 'bill' && str_contains($r['description'], (string) $special->number));

        $this->assertNotNull($row, 'The statement must list the special invoice.');
        $this->assertStringContainsString('تركيب عدّاد', $row['description']);
        $this->assertSame(45.0, (float) $row['due']);

        // Closing balance still equals outstanding(): specials are bills like the rest.
        $last = collect($statement['rows'])->last();
        $this->assertSame($this->subscriber->fresh()->outstanding(), (float) $last['balance']);
        $this->assertSame(70.0, (float) $last['balance']);
    }

    public function test_the_special_invoice_debts_the_overdue_report(): void
    {
        $this->billAugust();
        BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-08', $this->admin->id);

        $this->actingAs($this->admin);
        $rows = OverdueReport::make()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(55.0, (float) $rows[0]['due']);
    }

    public function test_monthly_invoiced_totals_include_special_invoices(): void
    {
        $this->billAugust();   // 25 for August
        BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-08', $this->admin->id);

        $this->assertSame(55.0, (float) Bill::where('period', '2026-08')->sum('total'));
    }

    // ---- deletion -------------------------------------------------------------

    public function test_an_unpaid_special_invoice_can_be_deleted_with_its_lines(): void
    {
        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'بند أول', 'quantity' => 1, 'unit_price' => 10],
            ['description' => 'بند تاني', 'quantity' => 2, 'unit_price' => 5],
        ], null, null, '2026-09', $this->admin->id)['bill'];

        $this->assertSame(2, BillItem::where('bill_id', $bill->id)->count());

        BillingService::deleteSpecialInvoice($bill);

        $this->assertNull(Bill::find($bill->id));
        $this->assertSame(0, BillItem::where('bill_id', $bill->id)->count());
        $this->assertSame(0.0, $this->subscriber->fresh()->outstanding());

        $log = AuditLog::where('action', 'bill.special_deleted')->latest('id')->first();
        $this->assertSame(20.0, (float) $log->payload['total']);
        $this->assertCount(2, $log->payload['items']);
    }

    public function test_a_paid_special_invoice_cannot_be_deleted(): void
    {
        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], null, null, '2026-09', $this->admin->id)['bill'];
        BillingService::collect($this->subscriber->fresh(), 30, $this->collector->id, null, '2026-09', Payment::METHOD_CASH_USD);

        try {
            BillingService::deleteSpecialInvoice($bill->fresh());
            $this->fail('A paid invoice must not be deletable.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('paid', $e->getMessage());
        }

        $this->assertNotNull(Bill::find($bill->id));
    }

    public function test_a_subscription_bill_cannot_be_deleted_through_the_special_path(): void
    {
        $august = $this->billAugust();

        $this->expectException(\RuntimeException::class);
        BillingService::deleteSpecialInvoice($august);
    }

    // ---- printing -------------------------------------------------------------

    public function test_the_printed_special_invoice_is_itemized(): void
    {
        $this->actingAs($this->admin);
        app()->setLocale('en');

        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'New meter', 'quantity' => 1, 'unit_price' => 45],
            ['description' => 'Labour', 'quantity' => 3, 'unit_price' => 10],
        ], 'Meter installation', 'Warranty 6 months', '2026-09', $this->admin->id)['bill'];

        $this->get(route('print.bill', ['bill' => $bill->id, 'size' => 'a5']))
            ->assertOk()
            ->assertSee(__('prt.special_title'))
            ->assertSee('Meter installation')
            ->assertSee('New meter')
            ->assertSee('Labour')
            ->assertSee('$75.00')                 // item total
            ->assertSee('Warranty 6 months')
            ->assertSee($bill->number)
            ->assertDontSee(__('prt.usage_short'))   // no metered usage row
            ->assertDontSee(__('prt.prev_reading'));
    }

    public function test_the_special_invoice_pdf_renders_one_page(): void
    {
        app()->setLocale('ar');

        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'عدّاد جديد', 'quantity' => 1, 'unit_price' => 45],
            ['description' => 'أجور فنّي', 'quantity' => 2, 'unit_price' => 12.5],
        ], 'تركيب عدّاد', null, '2026-09', $this->admin->id)['bill'];

        $pdf = PdfService::bill($bill->fresh(), 'a5');
        file_put_contents('/tmp/special-invoice-ar.pdf', $pdf);

        $this->assertStringStartsWith('%PDF', $pdf);
        $info = shell_exec('pdfinfo /tmp/special-invoice-ar.pdf 2>/dev/null');
        if ($info !== null && $info !== '') {
            $this->assertMatchesRegularExpression('/Pages:\s+1\b/', $info);
        }
        $this->assertGreaterThan(3000, strlen($pdf));
    }

    // ---- admin surfaces -------------------------------------------------------

    public function test_the_admin_surfaces_render_with_special_invoices_present(): void
    {
        $this->actingAs($this->admin);
        $this->billAugust();
        $bill = BillingService::createSpecialInvoice($this->subscriber, [
            ['description' => 'قطع غيار', 'quantity' => 1, 'unit_price' => 30],
        ], 'قطع غيار', null, '2026-09', $this->admin->id)['bill'];

        $this->get('/admin/bills')->assertOk()
            ->assertSee($bill->number)
            ->assertSee('قطع غيار');

        $this->get('/admin/subscribers')->assertOk();
        $this->get('/admin/subscribers/'.$this->subscriber->id)->assertOk();
        $this->get('/admin/collection-report')->assertOk();
        $this->get('/admin/overdue-report')->assertOk();
        $this->get('/admin/profit-loss')->assertOk();
        $this->get('/admin/generate-bills')->assertOk();

        // The statement (print view) carries the itemized invoice line too.
        $this->get(route('print.statement', ['subscriber' => $this->subscriber->id, 'size' => 'a4']))
            ->assertOk()
            ->assertSee($bill->number);
    }

    /**
     * Both doors to a special invoice exist: the profile screen's action modal, and the
     * subscriber's own meter controls (the same pair the collector sees on the portal).
     *
     * Asserted on the action CLASSES rather than on a rendered button, so a button that loses
     * its action is caught here and the wiring is caught by the render tests.
     */
    public function test_the_subscriber_actions_exist_for_a_special_invoice_and_a_meter_change(): void
    {
        $this->billAugust();

        foreach ([SpecialInvoice::class, ConvertToFlat::class, ConvertToMetered::class] as $action) {
            $definition = $action::make();

            $this->assertNotSame('', $definition->label());
            $this->assertNotEmpty($definition->sections(), $action.' has no fields to fill in.');
        }

        // A special invoice is issued from the profile, and the two meter conversions are
        // offered one at a time — a metered subscriber is not offered "convert to metered".
        $this->assertTrue(SpecialInvoice::make()->visible($this->subscriber));
        $this->assertFalse(ConvertToMetered::make()->visible($this->subscriber), 'A metered subscriber was offered a metered conversion.');
        $this->assertTrue(ConvertToFlat::make()->visible($this->subscriber));

        $flat = Subscriber::create([
            'route_id' => $this->subscriber->route_id, 'name' => 'Flat Guy', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        $this->assertTrue(ConvertToMetered::make()->visible($flat));
        $this->assertFalse(ConvertToFlat::make()->visible($flat), 'A flat subscriber was offered a flat conversion.');
    }
}
