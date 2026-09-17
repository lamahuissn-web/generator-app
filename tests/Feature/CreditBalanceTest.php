<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The money a bill rounds and a collector cannot make change for.
 *
 * The office's reality, in his words: a bill of 51.60 and a collector who has no change takes 52 —
 * a whole number, every month. That extra is the SUBSCRIBER'S, not the office's, and these tests pin
 * both directions of the rule:
 *
 *   what the rounding adds          -> his balance, and a later month spends it
 *   what the collector takes above  -> his balance too, and the receipt records both the cash and
 *                                      the part that settled bills, which is why the drawer and the
 *                                      books agree
 *
 * The last test is the fairness proof rather than an example: over two years of one fractional fee
 * he pays the exact sum owed, and his balance never reaches a dollar.
 */
class CreditBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'a@a.com', 'password' => 'x', 'role' => 'admin', 'locale' => 'en']);
    }

    private function flatSub(float $fee = 51.60): Subscriber
    {
        $route = Route::create(['name' => 'Test Area']);

        return Subscriber::create([
            'route_id' => $route->id,
            'name' => 'بيت الرصيد',
            'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => $fee,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    private function roundUp(): void
    {
        Setting::set(BillingService::ROUNDING_KEY, BillingService::ROUND_CEIL_DOLLAR);
    }

    private function creditOf(Subscriber $sub): float
    {
        return (float) $sub->fresh()->credit;
    }

    // ---- what the rounding adds ----------------------------------------------------------

    public function test_a_rounded_up_bill_leaves_the_difference_on_his_balance(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(51.60);

        $bill = BillingService::ensureFlatBill($sub, '2026-09');

        $this->assertSame(52.00, (float) $bill->total, 'the office rounds the total up');
        $this->assertSame(0.0, (float) $bill->credit_applied, 'nothing of his was spent on this one');
        $this->assertSame(0.40, $this->creditOf($sub), 'the 40 cents over the exact charge are HIS');
    }

    public function test_the_balance_is_read_before_the_rounding(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(51.60);
        BillingService::saveCredit($sub, 0.40);

        $bill = BillingService::ensureFlatBill($sub, '2026-10');

        // 51.60 − 0.40 = 51.20, and the ceiling still lands on 52.00: the balance did not buy
        // anything this month, so it stays — and grows by this month's own rounding.
        $this->assertSame(52.00, (float) $bill->total);
        $this->assertSame(0.0, (float) $bill->credit_applied);
        $this->assertSame(0.80, $this->creditOf($sub));
    }

    public function test_a_month_where_the_rounding_lands_under_spends_the_balance(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(51.60);
        BillingService::saveCredit($sub, 0.80);

        $bill = BillingService::ensureFlatBill($sub, '2026-11');

        // 51.60 − 0.80 = 50.80 -> 51.00: a dollar cheaper than the usual month, which is how the
        // money comes back to him. The invoice names the 60 cents it used, and still adds up.
        $this->assertSame(51.00, (float) $bill->total);
        $this->assertSame(0.60, (float) $bill->credit_applied);
        $this->assertSame(0.20, $this->creditOf($sub));
        // The invoice's own addition comes out: the charge line (51.60, the exact fee) − the credit
        // it names (0.60) = the total (51.00). `charges()` is that addition, so it IS the total.
        $this->assertSame(51.00, round($bill->charges(), 2), 'the printed lines add up to the total');
        $this->assertSame((float) $bill->total, round($bill->charges(), 2));
    }

    public function test_the_balance_is_never_negative_and_survives_a_cycle_that_costs_nothing(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(20.00);
        BillingService::saveCredit($sub, 25.00);

        $bill = BillingService::ensureFlatBill($sub, '2026-09');

        $this->assertSame(0.0, (float) $bill->total, 'nothing left to charge');
        $this->assertSame(5.00, $this->creditOf($sub), 'the rest stays his — never a negative balance');
    }

    // ---- what the collector takes at the door ---------------------------------------------

    public function test_a_collector_who_takes_a_round_dollar_credits_the_rest(): void
    {
        $sub = $this->flatSub(51.60);
        $bill = BillingService::ensureFlatBill($sub, '2026-09');

        $result = BillingService::collect($sub, 52.00, $this->admin->id);

        $payment = $result['payment']->fresh();

        $this->assertSame(52.00, (float) $payment->amount, 'the receipt carries the CASH the drawer holds');
        $this->assertSame(51.60, (float) $payment->applied, 'and the part that settled the bill');
        $this->assertSame(0.40, (float) $result['credited'], 'the 40 cents wait on his account');
        $this->assertSame(0.40, $this->creditOf($sub));
        $this->assertSame(0.0, (float) $sub->fresh()->outstanding(), 'the bill is settled');
    }

    public function test_reversing_a_receipt_takes_the_credit_back(): void
    {
        $sub = $this->flatSub(51.60);
        BillingService::ensureFlatBill($sub, '2026-09');

        $result = BillingService::collect($sub, 52.00, $this->admin->id);
        $this->assertSame(0.40, $this->creditOf($sub));

        BillingService::reversePayment($result['payment']);

        $this->assertSame(0.0, $this->creditOf($sub), 'no balance is left behind by a receipt that was undone');
        $this->assertSame(51.60, (float) $sub->fresh()->outstanding(), 'and the debt is back where it was');
    }

    public function test_a_lira_collection_hands_the_extra_back_and_credits_nothing(): void
    {
        $sub = $this->flatSub(100.00);
        BillingService::ensureFlatBill($sub, '2026-09');
        Setting::set('lbp_rate', '89500');

        $result = BillingService::collectInLira($sub, 10000000, null, $this->admin->id);
        $payment = $result['payment']->fresh();

        $this->assertSame(100.00, (float) $payment->amount, 'the lira above the balance is change handed back');
        $this->assertSame(0.0, $this->creditOf($sub), 'change changes hands in cash, so it is not a balance');
        $this->assertSame(0.0, (float) $sub->fresh()->outstanding());
    }

    // ---- the fairness proof ---------------------------------------------------------------

    public function test_over_two_years_he_pays_the_exact_sum_and_his_balance_stays_under_a_dollar(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(51.60);
        $exact = 0.0;
        $paid = 0.0;
        $highest = 0.0;

        for ($month = 1; $month <= 24; $month++) {
            // 24 DISTINCT cycles: a period is billed once, so each turn is a real month of service.
            $period = sprintf('%d-%02d', 2026 + intdiv($month - 1, 12), (($month - 1) % 12) + 1);
            $bill = BillingService::ensureFlatBill($sub->fresh(), $period);

            $exact += 51.60;
            $paid += (float) $bill->total;

            // The collector takes the whole number at the door, never change — the invoice is whole,
            // so this is exactly what the office's own rounding produced.
            BillingService::collect($sub->fresh(), (float) $bill->unpaidAmount(), $this->admin->id);

            $highest = max($highest, $this->creditOf($sub));
            $this->assertGreaterThanOrEqual(0.0, $this->creditOf($sub), 'the balance never goes negative');
        }

        // 24 months of 51.60 charged, and what he actually handed over: inside a dollar of the exact
        // sum, because the difference is still HIS balance rather than the office's money.
        $this->assertSame(51.60 * 24, round($exact, 2));
        $this->assertLessThan(1.00, $highest, 'his balance never reaches a dollar');
        $this->assertLessThan(1.00, abs($paid - $exact), 'and he ends up paying the exact sum, within his own balance');
    }

    // ---- a bill that cannot be raised must not move his money ------------------------------

    public function test_a_flat_bill_that_cannot_be_raised_leaves_his_balance_untouched(): void
    {
        $this->roundUp();
        $sub = $this->flatSub(51.60);
        BillingService::saveCredit($sub, 0.80);

        // The insert itself fails, exactly as a duplicate number or a dying connection would.
        Bill::creating(fn () => throw new \RuntimeException('the bill could not be raised'));

        try {
            BillingService::ensureFlatBill($sub->fresh(), '2026-11');
            $this->fail('an invoice that was never raised must not return as if it had been');
        } catch (\RuntimeException $e) {
            $this->assertSame('the bill could not be raised', $e->getMessage());
        }

        $this->assertSame(0.80, $this->creditOf($sub), 'his money did not move without its invoice');
        $this->assertDatabaseCount('bills', 0);
    }

    public function test_a_metered_bill_that_cannot_be_raised_leaves_his_balance_untouched(): void
    {
        $this->roundUp();
        $route = Route::create(['name' => 'Test Area']);
        $sub = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'بيت الرصيد',
            'meter_no' => 'M-9',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 15,
            'maintenance_fee' => 51.60, // a real charge, so his balance genuinely moves this cycle
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
        Reading::create([
            'subscriber_id' => $sub->id,
            'period' => '2026-06',
            'current_reading' => 1000,
            'previous_reading' => 0,
            'consumption' => 0,
        ]);
        BillingService::saveCredit($sub, 0.80);

        Bill::creating(fn () => throw new \RuntimeException('the bill could not be raised'));

        try {
            BillingService::enterMeteredReading($sub->fresh(), '2026-07', 1040, $this->admin->id);
            $this->fail('an invoice that was never raised must not return as if it had been');
        } catch (\RuntimeException $e) {
            $this->assertSame('the bill could not be raised', $e->getMessage());
        }

        $this->assertSame(0.80, $this->creditOf($sub), 'his money did not move without its invoice');
        $this->assertDatabaseCount('bills', 0);
        $this->assertDatabaseCount('readings', 1); // only the baseline: the failed reading rolled back
    }
}
