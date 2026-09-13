<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Currency;
use Database\Seeders\TwoMonthsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two-month sample: ten subscribers, July and August, and nothing else.
 *
 * This is a seeder, so what is pinned here is the CONTRACT the office asked for rather than
 * behaviour — but the contract has teeth, because a sample is what every screen is judged against:
 * if it silently carries three cycles, or doubles itself when run twice, the office reads a report
 * that describes a network nobody has.
 *
 * The last test is the one that matters most in practice: running the seeder twice must produce the
 * same ledger, not two networks piled up. A reset command that does not reset is worse than none.
 */
class TwoMonthsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwice(): void
    {
        $this->seed(TwoMonthsSeeder::class);
        $this->seed(TwoMonthsSeeder::class);
    }

    public function test_it_leaves_ten_subscribers_and_nothing_besides_the_two_cycles(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $this->assertSame(10, Subscriber::count());
        $this->assertSame(3, Route::count());

        $periods = Bill::query()->orderBy('period')->pluck('period')->unique()->values()->all();
        $this->assertSame([TwoMonthsSeeder::JULY, TwoMonthsSeeder::AUGUST], $periods);

        // Ten houses, two bills each — and the baseline reading is not a bill.
        $this->assertSame(20, Bill::count());
        foreach (Subscriber::all() as $subscriber) {
            $this->assertSame(
                [TwoMonthsSeeder::JULY, TwoMonthsSeeder::AUGUST],
                $subscriber->bills()->orderBy('period')->pluck('period')->all(),
                "{$subscriber->name} is not billed for both cycles, and only those",
            );
        }
    }

    public function test_each_cycle_carries_its_own_price(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        // The ministry raised the price between the two months: a July bill has to keep July's price
        // on its own paper even though the program now bills at August's. Compared as numbers, not as
        // the stored string: MySQL hands a decimal back as text and SQLite as a float, and a test that
        // pins the storage format would pass on one engine and fail on the other.
        // A flat house is not priced by the kWh, so it is the metered bills that carry a price.
        $prices = fn (string $period): array => Bill::query()
            ->where('period', $period)
            ->where('type', Subscriber::TYPE_METERED)
            ->pluck('kwh_price')
            ->unique()
            ->map(fn ($price): float => round((float) $price, 4))
            ->values()
            ->all();

        $this->assertSame([0.25], $prices(TwoMonthsSeeder::JULY));
        $this->assertSame([0.3], $prices(TwoMonthsSeeder::AUGUST));

        $this->assertSame([0.0], Bill::query()
            ->where('type', Subscriber::TYPE_FLAT)
            ->pluck('kwh_price')
            ->unique()
            ->map(fn ($price): float => round((float) $price, 4))
            ->values()
            ->all(), 'a flat rate is not priced per kWh');

        // Every bill's own lines still add up to its total — the sample must not be the one ledger in
        // the program whose paper does not.
        $this->assertSame(0, Bill::query()
            ->whereRaw('ROUND(usage_amount + maintenance_fee + flat_amount + items_amount, 2) != ROUND(total, 2)')
            ->count());
    }

    public function test_the_money_the_receipts_say_was_taken_is_the_money_that_was_allocated(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $collected = round((float) Payment::query()->sum('amount'), 2);
        $allocated = round((float) DB::table('payment_allocations')->sum('amount'), 2);

        $this->assertSame($collected, $allocated);
        $this->assertGreaterThan(0, $collected);
    }

    /**
     * A receipt has to be numbered for the month it was written in.
     *
     * The app mints `REC-YYYY-MM-####` from the row's own date, and the seeder writes every row today
     * and then dates it back to when it happened — so without recomputing the number the office reads
     * a list of receipts numbered 09 dated 08, which is exactly the kind of small lie that makes a
     * ledger feel unreliable.
     */
    public function test_a_receipt_is_numbered_for_the_month_it_was_written_in(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $this->assertGreaterThan(0, Payment::count());

        foreach (Payment::all() as $payment) {
            $this->assertSame(
                'REC-'.$payment->created_at->format('Y-m'),
                substr((string) $payment->number, 0, 11),
                "receipt {$payment->number} is dated {$payment->created_at->toDateString()}",
            );
        }
    }

    public function test_it_leaves_a_realistic_mix_of_settled_and_unsettled_houses(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $owing = Subscriber::all()->map(fn (Subscriber $s): float => round($s->outstanding(), 2));

        $this->assertGreaterThanOrEqual(4, $owing->filter(fn (float $o): bool => $o === 0.0)->count(), 'some houses are clear');
        $this->assertGreaterThanOrEqual(3, $owing->filter(fn (float $o): bool => $o > 0)->count(), 'some are behind');
        $this->assertSame(
            2,
            Subscriber::all()->filter(fn (Subscriber $s): bool => $s->bills()->where('paid_amount', 0)->count() === 2)->count(),
            'exactly the houses that paid nothing across both cycles',
        );

        // One settlement taken in lira, storing the rate it was taken at — without it the lira work
        // in this program is a feature no screen ever shows.
        $lira = Payment::query()->whereNotNull('lbp_amount')->sole();
        $this->assertGreaterThan(0, (float) $lira->lbp_rate);
        $this->assertSame(
            round((float) $lira->amount * (float) $lira->lbp_rate, 0),
            round((float) $lira->lbp_amount, 0),
        );
    }

    public function test_running_it_again_produces_the_same_ledger_rather_than_a_second_network(): void
    {
        $this->seedTwice();

        $this->assertSame(10, Subscriber::count(), 'the wipe left the first run behind');
        $this->assertSame(20, Bill::count());
        $this->assertSame(14, Payment::count());
        $this->assertSame(3, Route::count());
        $this->assertSame(3, User::count());
        $this->assertSame(9, Expense::count());
        $this->assertSame(3, CashHandover::count());

        // Nothing points at a subscriber that no longer exists: a wipe that leaves dangling rows
        // makes every report quietly wrong rather than loudly broken.
        $ids = Subscriber::query()->pluck('id')->all();
        $this->assertSame(0, DB::table('readings')->whereNotIn('subscriber_id', $ids)->count());
        $this->assertSame(0, DB::table('bills')->whereNotIn('subscriber_id', $ids)->count());
        $this->assertSame(0, DB::table('payments')->whereNotIn('subscriber_id', $ids)->count());
        $this->assertSame(0, DB::table('payment_allocations')->whereNotIn('bill_id', Bill::query()->pluck('id'))->count());

        // The baseline reading is the value July reads from, not a bill: 6 metered houses × 3 periods.
        $this->assertSame(6, Subscriber::query()->where('type', Subscriber::TYPE_METERED)->count());
        $this->assertSame(18, Reading::count());
    }

    public function test_a_house_with_no_meter_is_billed_its_flat_rate_and_needs_no_reading(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $flat = Subscriber::query()->where('type', Subscriber::TYPE_FLAT)->get();

        $this->assertSame(4, $flat->count());
        foreach ($flat as $subscriber) {
            $this->assertSame(0, $subscriber->readings()->count(), "{$subscriber->name} has no meter to read");
            $this->assertSame(
                round((float) $subscriber->flat_fee * 2, 2),
                round((float) $subscriber->bills()->sum('total'), 2),
            );
        }
    }

    public function test_the_settings_a_screen_needs_to_print_and_take_lira_are_set(): void
    {
        $this->seed(TwoMonthsSeeder::class);

        $this->assertSame(89500.0, Currency::rate());
        $this->assertNotSame('', (string) Setting::get('company_name'));
        $this->assertSame(0.25, BillingService::kwhPrice(TwoMonthsSeeder::JULY));
        $this->assertSame(0.30, BillingService::kwhPrice(TwoMonthsSeeder::AUGUST));
    }
}
