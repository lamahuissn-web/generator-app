<?php

namespace Tests\Feature;

use App\Admin\Actions\CollectPayment;
use App\Livewire\Admin\ActionForm;
use App\Livewire\Collector\SubscriberShow as CollectorSubscriberShow;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Collecting in lira.
 *
 * The street pays in lira and the ledger is dollars. Before this, a collector who came back with
 * five million lira did the division in his head, typed a dollar figure, and the lira was gone:
 * the office could not add up a run in lira, nor check a cash box against the money in it.
 *
 * The rules these tests pin:
 *   - the rate is stored ON THE RECEIPT, so an August receipt keeps August's number and a rate
 *     change today cannot rewrite what was taken last month;
 *   - the lira that is recorded is the lira that SETTLED bills: anything above the balance is
 *     change handed back, and change is not money received;
 *   - a lira collection and a dollar collection land in the books identically (same FIFO
 *     allocation, same cap, same remaining balance).
 */
class LiraCollectionTest extends TestCase
{
    use RefreshDatabase;

    private const RATE = 89500;

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        Setting::set(Currency::RATE_KEY, (string) self::RATE);
        app('cache')->flush();

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@lira.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@lira.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
    }

    private function bill(string $period, float $total): Bill
    {
        return Bill::create([
            'number' => 'INV-'.$period.'-9001', 'subscriber_id' => $this->subscriber->id,
            'period' => $period, 'type' => Subscriber::TYPE_METERED, 'total' => $total,
        ]);
    }

    // ---- the conversion -----------------------------------------------------------

    public function test_lira_is_converted_at_the_rate_the_receipt_carries(): void
    {
        $this->bill('2026-08', 100.0);

        // 8,950,000 ÷ 89,500 is exactly the 100 owed.
        $result = BillingService::collectInLira($this->subscriber, 8950000, null, $this->collector->id);
        $payment = $result['payment']->fresh();

        $this->assertSame(100.0, (float) $payment->amount, 'the ledger is dollars');
        $this->assertSame(8950000.0, $payment->lbp_amount, 'what was handed over, and it settled the bill');
        $this->assertSame((float) self::RATE, $payment->lbp_rate, "the rate is stored WITH the receipt");
        $this->assertSame(0.0, (float) $result['remaining']);
        $this->assertSame(0.0, $result['lbp_change']);
        $this->assertTrue($payment->isLira());
        $this->assertSame('LBP 8,950,000', $payment->liraLabel());
    }

    public function test_a_rate_typed_on_the_spot_wins_over_the_setting(): void
    {
        $this->bill('2026-08', 50.0);

        // The street rate today, entered by the collector: 100,000 to the dollar.
        $payment = BillingService::collectInLira($this->subscriber, 5000000, 100000, $this->collector->id)['payment']->fresh();

        $this->assertSame(50.0, (float) $payment->amount);
        $this->assertSame(5000000.0, $payment->lbp_amount);
        $this->assertSame(100000.0, $payment->lbp_rate, 'the receipt carries the rate that was actually used');
    }

    public function test_change_is_not_recorded_as_money_received(): void
    {
        $this->bill('2026-08', 100.0);

        // He hands over 10,000,000 lira for a 100 dollar debt: the extra is change.
        $result = BillingService::collectInLira($this->subscriber, 10000000, null, $this->collector->id);
        $payment = $result['payment']->fresh();

        $this->assertSame(100.0, (float) $payment->amount, 'capped at the balance');
        $this->assertSame(8950000.0, (float) $payment->lbp_amount, 'the lira that settled the bills');
        $this->assertSame(1050000.0, $result['lbp_change'], 'the rest went back in the subscriber hand');
        $this->assertSame(0.0, (float) $this->subscriber->fresh()->outstanding());
    }

    // ---- the ledger is unaffected by the currency ------------------------------------

    public function test_a_lira_collection_is_allocated_exactly_like_a_dollar_one(): void
    {
        $july = $this->bill('2026-07', 40.0);
        $august = $this->bill('2026-08', 60.0);

        // 100 dollars' worth, in lira: oldest bill first, then the next.
        BillingService::collectInLira($this->subscriber, 8950000, null, $this->collector->id);

        $this->assertSame(40.0, (float) $july->fresh()->paid_amount);
        $this->assertSame(60.0, (float) $august->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $this->subscriber->fresh()->outstanding());
    }

    public function test_a_part_lira_payment_leaves_the_rest_owed(): void
    {
        $this->bill('2026-08', 100.0);

        // Half the debt, in lira: 4,475,000 = 50.00.
        $result = BillingService::collectInLira($this->subscriber, 4475000, null, $this->collector->id);

        $this->assertSame(50.0, $result['applied']);
        $this->assertSame(50.0, $result['remaining']);
        $this->assertSame(4475000.0, $result['payment']->fresh()->lbp_amount);
    }

    // ---- refusals ---------------------------------------------------------------------

    public function test_without_a_rate_nothing_is_written_and_the_refusal_says_why(): void
    {
        Setting::set(Currency::RATE_KEY, '0');
        app('cache')->flush();
        $this->bill('2026-08', 100.0);

        try {
            BillingService::collectInLira($this->subscriber, 8950000, null, $this->collector->id);
            $this->fail('a lira collection without a rate must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('refuse.no_lbp_rate'), $e->getMessage());
            $this->assertStringContainsString('سعر صرف', $e->getMessage());
        }

        $this->assertSame(0, Payment::count(), 'no half-made receipt');
        $this->assertSame(100.0, (float) $this->subscriber->fresh()->outstanding());
    }

    public function test_a_zero_lira_amount_is_refused(): void
    {
        $this->bill('2026-08', 100.0);

        $this->expectException(\RuntimeException::class);

        BillingService::collectInLira($this->subscriber, 0, null, $this->collector->id);
    }

    // ---- the rate moves, the receipts do not ---------------------------------------------

    public function test_a_later_rate_does_not_rewrite_an_earlier_receipt(): void
    {
        $this->bill('2026-08', 50.0);
        $august = BillingService::collectInLira($this->subscriber, 4475000, 89500, $this->collector->id)['payment']->fresh();

        $this->bill('2026-09', 50.0);
        // The rate slides to 100,000.
        $september = BillingService::collectInLira($this->subscriber, 5000000, 100000, $this->collector->id)['payment']->fresh();

        $this->assertSame(89500.0, $august->lbp_rate);
        $this->assertSame('LBP 4,475,000', $august->liraLabel());
        $this->assertSame(100000.0, $september->lbp_rate);
        $this->assertSame('LBP 5,000,000', $september->liraLabel());
    }

    public function test_a_dollar_receipt_carries_no_lira_at_all(): void
    {
        $this->bill('2026-08', 30.0);

        $payment = BillingService::collect($this->subscriber, 30, $this->collector->id)['payment']->fresh();

        // Null, not zero: "no lira" and "zero lira" are different facts, and only the first is
        // true of a cash-USD collection.
        $this->assertNull($payment->lbp_amount);
        $this->assertNull($payment->lbp_rate);
        $this->assertFalse($payment->isLira());
        $this->assertNull($payment->liraLabel());
    }

    // ---- the two screens that take money ------------------------------------------------

    public function test_the_collector_screen_takes_the_lira_in_his_hand(): void
    {
        $this->bill('2026-08', 100.0);

        $component = Livewire::actingAs($this->collector)
            ->test(CollectorSubscriberShow::class, ['subscriber' => $this->subscriber])
            // Switching the unit is also what picks the matching method: cash in lira.
            ->call('setPaymentUnit', 'lbp')
            ->assertSet('paymentMethod', Payment::METHOD_CASH_LBP)
            ->set('paymentLbp', '8950000')
            ->set('paymentRate', (string) self::RATE);

        // What the cash is worth is on screen BEFORE he saves.
        $this->assertSame(100.0, $component->instance()->liraEquivalent());

        // …and the form itself offers the switch, the lira field and the rate: a screen the
        // collector cannot reach is a screen that does not exist for him.
        $component->call('switchView', 'payment')
            ->assertSee('ليرة')
            ->assertSee('المبلغ بالليرة')
            ->assertSee('سعر الصرف')
            ->assertSee('LBP 8,950,000', false);

        $component->call('savePayment')->assertHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->assertSame(100.0, (float) $payment->amount);
        $this->assertSame(8950000.0, $payment->lbp_amount);
        $this->assertSame((float) self::RATE, $payment->lbp_rate);
        $this->assertStringContainsString('LBP 8,950,000', $component->instance()->flash ?? '');
    }

    public function test_the_collector_screen_refuses_lira_without_a_rate(): void
    {
        $this->bill('2026-08', 100.0);

        Livewire::actingAs($this->collector)
            ->test(CollectorSubscriberShow::class, ['subscriber' => $this->subscriber])
            ->call('setPaymentUnit', 'lbp')
            ->set('paymentLbp', '8950000')
            ->set('paymentRate', '0')
            ->call('savePayment')
            ->assertHasErrors('paymentRate');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_office_takes_a_lira_payment_from_the_subscriber_file(): void
    {
        $this->bill('2026-08', 100.0);

        $component = Livewire::actingAs($this->admin)
            ->test(ActionForm::class, [
                'action' => CollectPayment::class,
                'subjectId' => $this->subscriber->id,
                'returnTo' => url('/admin/subscribers/'.$this->subscriber->id),
            ])
            ->call('openModal')
            // The lira field is prefilled from the balance and the setting, so the common case
            // (the whole debt, in lira) is still one click.
            ->assertSet('state.unit', 'usd')
            ->set('state.unit', 'lbp')
            ->assertSet('state.rate', (string) self::RATE);

        // The dollar field is not asked for at all in lira mode; its rules go with it.
        $component->set('state.amount', '')
            ->set('state.lbp', '8950000')
            ->call('submit')
            ->assertHasNoErrors();

        $payment = Payment::firstOrFail();
        $this->assertSame(8950000.0, $payment->lbp_amount);
        $this->assertSame((float) self::RATE, $payment->lbp_rate);
        // The office is told what it just took, in both currencies (the modal flashes + reloads).
        $this->assertStringContainsString('LBP 8,950,000', (string) session('status'));
    }

    public function test_the_office_is_told_what_the_lira_was_worth(): void
    {
        $this->bill('2026-08', 100.0);

        $component = Livewire::actingAs($this->admin)
            ->test(ActionForm::class, [
                'action' => CollectPayment::class,
                'subjectId' => $this->subscriber->id,
                'returnTo' => url('/admin/subscribers/'.$this->subscriber->id),
            ])
            ->call('openModal')
            ->set('state.unit', 'lbp')
            ->set('state.amount', '')
            ->set('state.lbp', '10000000')
            ->call('submit')
            ->assertHasNoErrors();

        $message = (string) session('status');

        $this->assertStringContainsString('LBP 8,950,000', $message, 'the lira that settled the debt');
        $this->assertStringContainsString('$100.00', $message, 'what it was worth in the ledger');
        $this->assertStringContainsString('LBP 1,050,000', $message, 'and the change handed back');
    }

    public function test_the_receipts_list_shows_the_lira_once_lira_is_in_use(): void
    {
        $this->bill('2026-08', 100.0);

        // A dollar-only office sees no lira column at all.
        $this->actingAs($this->admin)
            ->get('/admin/payments')
            ->assertOk()
            ->assertDontSee('المقبوض بالليرة');

        BillingService::collectInLira($this->subscriber, 8950000, null, $this->collector->id);

        $this->actingAs($this->admin)
            ->get('/admin/payments')
            ->assertOk()
            ->assertSee('المقبوض بالليرة')
            ->assertSee('LBP 8,950,000')
            // …next to the dollars the ledger recorded, not instead of them.
            ->assertSee('$100.00');
    }
}
