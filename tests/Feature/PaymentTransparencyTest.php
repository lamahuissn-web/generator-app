<?php

namespace Tests\Feature;

use App\Filament\Resources\SubscriberResource\Pages\ViewSubscriber;
use App\Filament\Resources\SubscriberResource\RelationManagers\PaymentsRelationManager;
use App\Livewire\Collector\SubscriberShow;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CollectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1 "transparency pack": payment methods, the invoices a payment settled, and
 * printing a receipt from the payment rows.
 */
class PaymentTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
        $this->collector = User::create(['name' => 'Jad Haddad', 'email' => 'j@x.com', 'password' => 'x', 'role' => 'collector', 'phone' => '03 111222', 'locale' => 'en']);
        $this->route = Route::create(['name' => 'Corniche']);

        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'Ali Hassan',
            'meter_no' => 'M-1',
            'type' => Subscriber::TYPE_METERED,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
    }

    /** Two billed periods: July 25 and August 25 (which carries July's debt). */
    private function billTwoPeriods(): array
    {
        $july = BillingService::enterMeteredReading($this->subscriber, '2026-07', 1040, $this->admin->id)['bill'];
        $august = BillingService::enterMeteredReading($this->subscriber, '2026-08', 1080, $this->admin->id)['bill'];
        $july->forceFill(['created_at' => Carbon::parse('2026-07-26 10:00')])->save();
        $august->forceFill(['created_at' => Carbon::parse('2026-08-26 10:00')])->save();

        return [$july, $august];
    }

    public function test_collect_records_the_payment_method_and_audits_it(): void
    {
        $this->billTwoPeriods();

        $payment = BillingService::collect($this->subscriber, 20, $this->collector->id, 'whish ref 88', '2026-08', Payment::METHOD_WHISH)['payment'];

        $this->assertSame(Payment::METHOD_WHISH, $payment->method);
        $this->assertSame('Whish Money', $payment->methodLabel());
        $this->assertSame(Payment::METHOD_WHISH, $payment->fresh()->method);

        // The default stays cash in USD.
        $second = BillingService::collect($this->subscriber, 10, $this->collector->id, null, '2026-08')['payment'];
        $this->assertSame(Payment::METHOD_CASH_USD, $second->method);

        $log = AuditLog::where('action', 'payment.created')->latest('id')->first();
        $this->assertSame(Payment::METHOD_CASH_USD, $log->payload['method']);
    }

    public function test_each_method_has_a_label_and_unknown_values_fall_back(): void
    {
        $this->assertCount(5, Payment::methodOptions());
        $this->assertSame('Cash (USD)', Payment::label(Payment::METHOD_CASH_USD));
        $this->assertSame('OMT', Payment::label(Payment::METHOD_OMT));
        $this->assertSame('legacy_value', Payment::label('legacy_value'));
        $this->assertSame('Cash (USD)', Payment::label(null));
    }

    public function test_payment_lists_the_invoices_it_settled(): void
    {
        [$july, $august] = $this->billTwoPeriods();

        // 30 paid against 50 owed: FIFO clears July (25) then puts 5 on August.
        $payment = BillingService::collect($this->subscriber, 30, $this->collector->id, null, '2026-08')['payment'];

        $settled = $payment->settledBills();
        $this->assertCount(2, $settled);
        $this->assertSame($july->id, $settled[0]->id);
        $this->assertSame($august->id, $settled[1]->id);

        $label = $payment->settledBillsLabel();
        $this->assertStringContainsString($july->number, $label);
        $this->assertStringContainsString($august->number, $label);

        // A payment that settled nothing reports an em dash, not an empty cell.
        $orphan = Payment::create([
            'subscriber_id' => $this->subscriber->id, 'collector_id' => $this->collector->id,
            'amount' => 1, 'method' => Payment::METHOD_CASH_LBP,
        ]);
        $this->assertSame('—', $orphan->settledBillsLabel());
    }

    public function test_statement_and_ledger_describe_the_method_and_the_invoices(): void
    {
        [$july] = $this->billTwoPeriods();
        $payment = BillingService::collect($this->subscriber, 25, $this->collector->id, null, '2026-08', Payment::METHOD_OMT)['payment'];

        $statement = CollectionService::statement($this->subscriber->fresh());
        $paymentRow = collect($statement['rows'])->firstWhere('kind', 'payment');

        $this->assertStringContainsString('OMT', $paymentRow['description']);
        $this->assertStringContainsString('Jad Haddad', $paymentRow['description']);
        $this->assertStringContainsString($july->number, $paymentRow['description']);

        $ledger = CollectionService::collectorLedger($this->collector->fresh());
        $this->assertStringContainsString($july->number, $ledger['rows'][0]['description']);
    }

    public function test_collector_portal_picks_a_method_and_shows_the_settled_invoices(): void
    {
        [$july, $august] = $this->billTwoPeriods();

        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber])
            ->set('view', 'payment')
            ->set('paymentMethod', Payment::METHOD_CASH_LBP)
            ->set('paymentAmount', '30')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSee('Cash (LBP)')            // method on the receipt card
            ->assertSee($july->number)           // invoices this money settled
            ->assertSee($august->number);

        $payment = Payment::where('subscriber_id', $this->subscriber->id)->firstOrFail();
        $this->assertSame(Payment::METHOD_CASH_LBP, $payment->method);
        $this->assertSame(30.0, (float) $payment->amount);
    }

    public function test_receipt_note_explains_a_balance_that_is_the_next_cycle(): void
    {
        [$july, $august] = $this->billTwoPeriods();

        // Pay July in full: the receipt must say the 25 still owed is August, not arrears.
        $payment = BillingService::collect($this->subscriber, 25, $this->collector->id, null, '2026-07')['payment'];

        $note = $payment->remainingNote();
        $this->assertSame('new', $note['kind']);
        $this->assertSame(['2026-08'], $note['new_periods']);
        $this->assertSame(25.0, $note['new_total']);
        $this->assertSame(0.0, $note['leftover_total']);
        $this->assertStringContainsString($july->number, $payment->settledBillsLabel());

        app()->setLocale('en');
        $this->actingAs($this->admin)
            ->get(route('print.receipt', ['payment' => $payment->id, 'size' => 'a4']))
            ->assertOk()
            ->assertSee('not covered by this payment', false)
            ->assertSee('Aug 2026')
            ->assertSee('$25.00');
    }

    public function test_receipt_note_calls_a_partial_payment_what_it_is(): void
    {
        $this->billTwoPeriods();

        // 10 against a 25 bill: a genuine shortfall on a cycle this payment reached.
        $payment = BillingService::collect($this->subscriber, 10, $this->collector->id, null, '2026-07')['payment'];

        $note = $payment->remainingNote();
        $this->assertSame('mixed', $note['kind']);
        $this->assertSame(15.0, $note['leftover_total']);
        $this->assertSame(['2026-07'], $note['leftover_periods']);
        $this->assertSame(25.0, $note['new_total']);

        app()->setLocale('en');
        $this->actingAs($this->admin)
            ->get(route('print.receipt', ['payment' => $payment->id, 'size' => '80mm']))
            ->assertOk()
            ->assertSee('$15.00 unpaid on Jul 2026', false)
            ->assertSee('$25.00 for a later cycle', false);

        // A single unpaid bill on the same cycle is pure leftover, not "new charges".
        $subscriber = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'Nadia Khoury', 'meter_no' => 'M-2',
            'type' => Subscriber::TYPE_METERED, 'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        Reading::create(['subscriber_id' => $subscriber->id, 'period' => '2026-06', 'current_reading' => 500, 'previous_reading' => 0, 'consumption' => 0]);
        BillingService::enterMeteredReading($subscriber, '2026-07', 520, $this->admin->id);
        $partial = BillingService::collect($subscriber, 5, $this->collector->id, null, '2026-07')['payment'];

        $partialNote = $partial->remainingNote();
        $this->assertSame('leftover', $partialNote['kind']);
        $this->assertSame(['2026-07'], $partialNote['leftover_periods']);
        $this->assertSame(15.0, $partialNote['leftover_total']);   // $20 bill (20 kWh + $15) minus $5
    }

    public function test_receipt_note_stays_silent_when_there_is_nothing_to_explain(): void
    {
        [$july, $august] = $this->billTwoPeriods();

        // Everything settled: no note.
        $paid = BillingService::collect($this->subscriber, 50, $this->collector->id, null, '2026-08')['payment'];
        $this->assertNull($paid->remainingNote());

        app()->setLocale('en');
        $this->actingAs($this->admin)
            ->get(route('print.receipt', ['payment' => $paid->id, 'size' => 'a4']))
            ->assertOk()
            ->assertDontSee('still due', false)
            ->assertSee($july->number)
            ->assertSee($august->number);

        // An unallocated cash entry says nothing about the balance.
        $orphan = Payment::create([
            'subscriber_id' => $this->subscriber->id, 'collector_id' => $this->collector->id,
            'amount' => 5, 'method' => Payment::METHOD_OTHER,
        ]);
        $this->assertNull($orphan->remainingNote());
    }

    public function test_collector_portal_shows_the_note_on_the_receipt_card(): void
    {
        $this->billTwoPeriods();

        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber])
            ->set('view', 'payment')
            ->set('paymentAmount', '25')
            ->call('savePayment')
            ->assertHasNoErrors()
            ->assertSee('not covered by this payment', false)
            ->assertSee('Aug 2026');
    }

    public function test_payments_table_shows_method_and_linked_invoices_and_prints(): void
    {
        [$july] = $this->billTwoPeriods();
        $payment = BillingService::collect($this->subscriber, 25, $this->collector->id, 'cash box 1', '2026-08', Payment::METHOD_WHISH)['payment'];

        $this->actingAs($this->admin)
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee('Whish Money')
            ->assertSee($july->number);

        // Subscriber profile: the payments tab renders its own table (Filament only
        // boots the active relation manager tab, so test the manager itself).
        $this->actingAs($this->admin)->get('/admin/subscribers/'.$this->subscriber->id)->assertOk();

        Livewire::actingAs($this->admin)
            ->test(PaymentsRelationManager::class, [
                'ownerRecord' => $this->subscriber,
                'pageClass' => ViewSubscriber::class,
            ])
            ->assertOk()
            ->assertSee('Whish Money')
            ->assertSee($july->number)
            ->assertSee('/print/receipt/');   // print action present on the row

        $this->actingAs($this->collector)
            ->get(route('print.receipt', ['payment' => $payment->id, 'size' => 'a4']))
            ->assertOk()
            ->assertSee('Whish Money')            // method printed, not hardcoded cash
            ->assertSee($payment->number)
            ->assertSee($july->number)            // the invoice it settled
            ->assertSee('cash box 1');
    }

    public function test_receipt_print_lists_every_settled_invoice_with_its_amount(): void
    {
        [$july, $august] = $this->billTwoPeriods();
        $payment = BillingService::collect($this->subscriber, 30, $this->collector->id, null, '2026-08')['payment'];

        $response = $this->actingAs($this->admin)->get(route('print.receipt', ['payment' => $payment->id, 'size' => 'a5']));

        $response->assertOk()
            ->assertSee($july->number)
            ->assertSee($august->number)
            ->assertSee('$25.00')   // July fully settled
            ->assertSee('$5.00');   // the remaining 5 went on August
    }
}
