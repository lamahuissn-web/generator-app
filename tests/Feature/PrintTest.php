<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\GenerateBills;
use App\Livewire\Collector\SubscriberShow;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PrintTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private User $arAdmin;

    private Route $route;

    private Subscriber $subscriber;

    private Bill $bill;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->arAdmin = User::create(['name' => 'مدير', 'email' => 'ar@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'ar']);
        $this->collector = User::create(['name' => 'Collector One', 'email' => 'c@x.com', 'password' => 'x', 'role' => 'collector', 'locale' => 'en']);

        $this->route = Route::create(['name' => 'Corniche']);
        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Ali Hassan',
            'phone' => '03 123456',
            'meter_no' => 'M-77',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        Reading::create([
            'subscriber_id' => $this->subscriber->id,
            'period' => '2026-06',
            'current_reading' => 1000,
            'previous_reading' => 0,
            'consumption' => 0,
        ]);

        // 40 kWh x $0.25 = $10 usage + $15 maintenance = $25 bill.
        $this->bill = BillingService::enterMeteredReading($this->subscriber, '2026-07', 1040, $this->admin->id)['bill'];
        $this->subscriber->refresh();
        $this->payment = BillingService::collect($this->subscriber, 10, $this->collector->id, 'partial cash', '2026-07')['payment'];
    }

    public function test_admin_can_open_the_bill_print_view(): void
    {
        $response = $this->actingAs($this->admin)->get(route('print.bill', ['bill' => $this->bill->id]));

        $response->assertOk()
            ->assertSee('Ali Hassan')
            ->assertSee('Subscription bill')
            ->assertSee('$25.00')      // bill total
            ->assertSee('$15.00')      // maintenance line
            ->assertSee('1,040');     // current reading, thousands-separated Latin digits
    }

    public function test_bill_pdf_is_a_real_pdf(): void
    {
        $response = $this->actingAs($this->admin)->get(route('print.bill.pdf', ['bill' => $this->bill->id]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertGreaterThan(5000, strlen($response->getContent()));
    }

    public function test_bill_batch_renders_html_pdf_and_zip(): void
    {
        $params = ['period' => '2026-07'];

        $this->actingAs($this->admin)->get(route('print.bills', $params))
            ->assertOk()
            ->assertSee('Ali Hassan')
            ->assertSee('Print all');

        $pdf = $this->actingAs($this->admin)->get(route('print.bills', [...$params, 'format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $zip = $this->actingAs($this->admin)->get(route('print.bills', [...$params, 'format' => 'zip']));
        $zip->assertOk();
        $this->assertSame('application/zip', $zip->headers->get('content-type'));
        $this->assertStringStartsWith('PK', $zip->streamedContent()); // real zip archive bytes
    }

    public function test_batch_can_be_driven_by_explicit_ids(): void
    {
        $other = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Sami Nour',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        BillingService::ensureFlatBill($other, '2026-07');

        $this->actingAs($this->admin)
            ->get(route('print.bills', ['ids' => (string) $this->bill->id]))
            ->assertOk()
            ->assertSee('Ali Hassan')
            ->assertDontSee('Sami Nour');
    }

    public function test_receipt_print_for_admin_collector_and_pdf(): void
    {
        $this->actingAs($this->collector)->get(route('print.receipt', ['payment' => $this->payment->id]))
            ->assertOk()
            ->assertSee('Ali Hassan')
            ->assertSee('$10.00')          // amount received
            ->assertSee('Collector One');

        $this->actingAs($this->admin)->get(route('print.receipts', ['ids' => (string) $this->payment->id, 'size' => 'a5']))
            ->assertOk()
            ->assertSee('Ali Hassan');

        $pdf = $this->actingAs($this->collector)->get(route('print.receipt.pdf', ['payment' => $this->payment->id]));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_the_customer_statement_reads_as_invoice_payment_and_balance(): void
    {
        // 2026-07 bill of 25.00 with 10.00 collected on it -> 15.00 still due.
        // The customer gets five columns they can read; «مدين»/«دائن» are for accountants and
        // are not on this sheet at all.
        $response = $this->actingAs($this->admin)
            ->get(route('print.statement', ['subscriber' => $this->subscriber->id, 'size' => 'a4']));

        $response->assertOk()
            ->assertSee(__('col.st_date'))
            ->assertSee(__('col.st_description'))
            ->assertSee(__('col.cst_type'))
            ->assertSee(__('prt.amount'))
            ->assertSee(__('col.st_balance'))
            ->assertDontSee(__('col.st_debit'))
            ->assertDontSee(__('col.st_credit'))
            ->assertDontSee(__('col.st_ref'));

        // What happened, how much, who took it, and what is left.
        $response->assertSee(__('col.cst_kind_bill'))
            ->assertSee(__('col.cst_kind_payment'))
            ->assertSee('$25.00')                    // the invoice
            ->assertSee('$10.00')                    // the payment
            ->assertSee('Collector One')             // the collector, on the payment line
            ->assertSee(__('col.cst_total_bills'))
            ->assertSee(__('col.cst_total_paid'))
            ->assertSee(__('col.cst_due'))
            ->assertSee('$15.00');                   // the remaining amount, in the line and in the band

        $this->assertSame(15.0, (float) $this->subscriber->fresh()->outstanding());

        // Amounts are pinned into an LTR island. An amount that follows an Arabic line in the
        // same cell is otherwise re-ordered by mPDF and prints as "25.00$"; the defect does not
        // exist in the browser, so it is asserted on the markup.
        $response->assertSee('<span class="ltr">$25.00</span>', false);
    }

    public function test_the_statement_from_a_date_carries_the_opening_line_and_a_third_box(): void
    {
        // Bill in July, money collected in August: from August the bill is no longer a movement,
        // it becomes the opening balance — and the summary has to name it, or the three figures
        // stop adding up on paper.
        $this->bill->forceFill(['created_at' => Carbon::parse('2026-07-26 10:00')])->save();
        $this->payment->forceFill(['created_at' => Carbon::parse('2026-08-05 12:00')])->save();

        $this->actingAs($this->admin)
            ->get(route('print.statement', ['subscriber' => $this->subscriber->id, 'size' => 'a4', 'from' => '2026-08-01']))
            ->assertOk()
            ->assertSee(__('col.cst_opening'))
            ->assertSee('$25.00')                    // the carried-over balance
            ->assertSee('$15.00')                    // opening 25 - the 10 collected in August
            ->assertSee('2026-08-01')                // the note saying older movements are folded in
            ->assertDontSee('2026-07-26');           // the July bill is not listed as a movement any more
    }

    public function test_the_customer_statement_pdf_is_a4_and_arabic(): void
    {
        $pdf = $this->actingAs($this->arAdmin)
            ->get(route('print.statement', ['subscriber' => $this->subscriber->id, 'size' => 'a4', 'format' => 'pdf']));

        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertGreaterThan(5000, strlen($pdf->getContent()));
    }

    public function test_bill_prints_are_admin_only(): void
    {
        $this->actingAs($this->collector)->get(route('print.bill', ['bill' => $this->bill->id]))->assertForbidden();
        $this->actingAs($this->collector)->get(route('print.bills', ['period' => '2026-07']))->assertForbidden();
        $this->actingAs($this->collector)->get(route('print.bills', ['period' => '2026-07', 'format' => 'pdf']))->assertForbidden();
        $this->actingAs($this->collector)->get(route('print.bills', ['period' => '2026-07', 'format' => 'zip']))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('print.bill', ['bill' => $this->bill->id]))->assertRedirect();
        $this->get(route('print.bills', ['period' => '2026-07']))->assertRedirect();
        $this->get(route('print.receipt', ['payment' => $this->payment->id]))->assertRedirect();
    }

    public function test_unknown_size_falls_back_to_the_default(): void
    {
        $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $this->bill->id, 'size' => 'xxl']))
            ->assertOk()
            ->assertSee('A5');
    }

    public function test_arabic_locale_prints_arabic_with_latin_digits(): void
    {
        $this->actingAs($this->arAdmin)
            ->get(route('print.bill', ['bill' => $this->bill->id]))
            ->assertOk()
            ->assertSee('فاتورة اشتراك')
            ->assertSee('Ali Hassan')          // subscriber names stay as stored
            ->assertSee('25.00')               // Latin digits only
            ->assertDontSee('٢٥');             // never Arabic-Indic numerals
    }

    public function test_collector_portal_receipt_offers_a_printable_receipt(): void
    {
        // 2026-07 bill is 25.00 with 10.00 already collected -> 15.00 left.
        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber])
            ->set('view', 'payment')
            ->set('paymentAmount', '15')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSee('Print receipt')
            ->assertSee('/print/receipt/');

        $payment = Payment::where('subscriber_id', $this->subscriber->id)->latest('id')->first();
        $this->assertSame(15.0, (float) $payment->amount);

        // And that printed receipt is reachable by the collector.
        $this->actingAs($this->collector)
            ->get(route('print.receipt', ['payment' => $payment->id, 'size' => '80mm']))
            ->assertOk()
            ->assertSee('Ali Hassan');
    }

    public function test_generate_bills_page_previews_then_generates(): void
    {
        $flat = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Flat Customer',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 45,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get('/admin/generate-bills')->assertOk();

        // The metered subscriber is already billed for 2026-07 and the flat one is not.
        Livewire::actingAs($this->admin)
            ->test(GenerateBills::class)
            ->set('filters.period', '2026-07')
            ->set('filters.route_id', $this->route->id)
            ->assertOk()
            ->assertSee('Flat Customer')
            ->call('generate')
            ->assertHasNoErrors();

        $this->assertSame(1, $flat->bills()->where('period', '2026-07')->count());
        $this->assertSame(45.0, (float) $flat->fresh()->outstanding());
        // The already-billed metered subscriber was left alone.
        $this->assertSame(1, $this->subscriber->bills()->where('period', '2026-07')->count());
    }
}
