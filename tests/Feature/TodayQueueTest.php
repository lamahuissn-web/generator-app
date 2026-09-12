<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\Today as TodayComponent;
use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\TodayQueue;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The office's worklist: what is unfinished today, and what that row stops saying once it is
 * answered.
 *
 * The screen exists to be READ and EMPTIED, so what is pinned here is that each row appears
 * exactly when it has something to say and disappears when it does not — a worklist that keeps
 * listing finished work is a worklist the operator learns to scroll past, and one that lists
 * nothing while a collector holds a month of cash is worse than no screen at all. The rows that
 * carry a figure also carry its AGE, since "3 محصّلين عندهم كاش" is a fact and "من 5 أيام" is a
 * decision.
 */
class TodayQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Route $otherRoute;

    private string $period;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->period = BillingService::currentPeriod();

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@today.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'سامر', 'email' => 'samer@today.test', 'phone' => '03 111222',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'حي الشمالية']);
        $this->otherRoute = Route::create(['name' => 'حي الجنوبية']);
    }

    // ---- fixtures -------------------------------------------------------------

    private function subscriber(
        string $name,
        ?string $meter = '2011',
        ?Route $route = null,
        string $type = Subscriber::TYPE_METERED,
        ?string $phone = '03 999888',
    ): Subscriber {
        return Subscriber::create([
            'route_id' => ($route ?? $this->route)->id,
            'name' => $name,
            'phone' => $phone,
            'meter_no' => $type === Subscriber::TYPE_METERED ? $meter : null,
            'type' => $type,
            'flat_fee' => $type === Subscriber::TYPE_FLAT ? 50 : null,
            'amperage' => 10,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
    }

    private function reading(Subscriber $subscriber, string $period, float $consumption = 70): Reading
    {
        return Reading::create([
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'current_reading' => 1340,
            'previous_reading' => 1340 - $consumption,
            'consumption' => $consumption,
            'entered_by' => $this->collector->id,
        ]);
    }

    private function bill(Subscriber $subscriber, string $period, float $total = 100, float $paid = 0): Bill
    {
        return Bill::create([
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'type' => $subscriber->type,
            'total' => $total,
            'paid_amount' => $paid,
        ]);
    }

    /**
     * Cash in a collector's pocket, written straight to the ledger (no bill to allocate).
     *
     * The default payer is a FLAT house with no meter: a payment needs a subscriber, and a
     * metered one would need a unique meter number per call — while a flat house with no bill
     * changes nothing else the queue looks at.
     */
    private function payment(float $amount, ?User $collector = null, ?Subscriber $subscriber = null): Payment
    {
        return Payment::create([
            'subscriber_id' => ($subscriber ?? $this->subscriber('مشترك دافع', null, null, Subscriber::TYPE_FLAT))->id,
            'collector_id' => ($collector ?? $this->collector)->id,
            'amount' => $amount,
            'method' => Payment::METHOD_CASH_USD,
        ]);
    }

    private function queue(?string $period = null): TodayQueue
    {
        return TodayQueue::make($period ?? $this->period);
    }

    /** One row, by key. Fails loudly when the queue is not saying what the test expects. */
    private function row(TodayQueue $queue, string $key): array
    {
        $row = $queue->items()->firstWhere('key', $key);

        $this->assertNotNull($row, "The queue has no open '{$key}' row.");

        return $row;
    }

    private function doneRow(TodayQueue $queue, string $key): array
    {
        $row = $queue->done()->firstWhere('key', $key);

        $this->assertNotNull($row, "The queue does not list '{$key}' as closed.");

        return $row;
    }

    /** Everything the queue is saying, as one string — for the wording checks. */
    private function words(TodayQueue $queue): string
    {
        $strip = collect($queue->strip());

        return $strip->pluck('label')->implode(' ')
            .' '.$strip->pluck('hint')->implode(' ')
            .' '.$queue->items()->pluck('title')->implode(' ')
            .' '.$queue->items()->pluck('detail')->flatten()->implode(' ')
            .' '.$queue->done()->pluck('title')->implode(' ')
            .' '.$queue->done()->pluck('detail')->flatten()->implode(' ');
    }

    /** A network with nothing outstanding: this is the state the screen is meant to reach. */
    private function settleEverything(): void
    {
        // One metered house, read and billed, with no debt and no expenses owed.
        $house = Subscriber::query()->first() ?? $this->subscriber('محمد عيتاني');
        if (! Reading::query()->where('subscriber_id', $house->id)->where('period', $this->period)->exists()) {
            $this->reading($house, $this->period);
        }
        if (! Bill::query()->where('subscriber_id', $house->id)->where('period', $this->period)->exists()) {
            // Paid in full: a bill still owed would leave the debt row open, and the point of
            // this fixture is a network with nothing left to chase.
            $this->bill($house, $this->period, 100, 100);
        }

        Expense::create([
            'category' => Expense::CAT_FUEL, 'expense_date' => now()->toDateString(),
            'amount' => 180, 'description' => 'Diesel', 'entered_by' => $this->admin->id,
        ]);
    }

    // ---- the door -------------------------------------------------------------

    public function test_the_screen_renders_for_an_admin(): void
    {
        $this->subscriber('محمد عيتاني');

        $this->actingAs($this->admin)
            ->get(route('admin.today'))
            ->assertOk()
            ->assertSee('اليوم')
            ->assertSee('بدو شغل هلّق')
            ->assertSee('متابعة القراءات');
    }

    public function test_a_collector_cannot_open_the_day(): void
    {
        $this->actingAs($this->collector)
            ->get(route('admin.today'))
            ->assertForbidden();
    }

    public function test_the_day_is_reachable_from_the_sidebar(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.today'));
    }

    // ---- readings -------------------------------------------------------------

    public function test_a_missing_reading_is_the_most_urgent_row_and_names_the_area(): void
    {
        $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('سمير خليل', '2011');

        $queue = $this->queue();
        $row = $this->row($queue, 'readings');

        $this->assertSame(TodayQueue::TONE_DANGER, $row['tone']);
        $this->assertSame($row['key'], $queue->items()->first()['key'], 'the reading row leads');
        $this->assertStringContainsString('قراءتان ناقصتان', $row['title']);
        $this->assertStringContainsString('حي الشمالية', implode(' ', $row['detail']));
        $this->assertStringContainsString('ولا قراءة انقبلت', implode(' ', $row['detail']));
    }

    public function test_the_reading_row_closes_once_the_cycle_is_read(): void
    {
        $house = $this->subscriber('محمد عيتاني');

        $this->assertSame('readings', $this->queue()->items()->first()['key']);

        $this->reading($house, $this->period);

        $this->assertNull($this->queue()->items()->firstWhere('key', 'readings'));
        $this->assertStringContainsString('الدورة كاملة', $this->doneRow($this->queue(), 'readings')['title']);
    }

    public function test_the_reading_row_ages_the_last_reading_of_the_cycle(): void
    {
        $house = $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('سمير خليل', '2011');

        Reading::create([
            'subscriber_id' => $house->id,
            'period' => $this->period,
            'current_reading' => 1340, 'previous_reading' => 1270, 'consumption' => 70,
            'entered_by' => $this->collector->id,
            'updated_at' => now()->subHours(3),
        ]);

        $this->assertStringContainsString('آخر قراءة انقبلت', implode(' ', $this->row($this->queue(), 'readings')['detail']));
    }

    public function test_a_flat_rate_house_is_never_a_missing_reading(): void
    {
        $this->subscriber('ناديا خليل', null, null, Subscriber::TYPE_FLAT);

        // Nothing metered to read at all: the cycle is complete as far as the queue cares.
        $this->assertNull($this->queue()->items()->firstWhere('key', 'readings'));
    }

    // ---- dead meters ----------------------------------------------------------

    public function test_a_reported_dead_meter_is_a_case_the_office_has_not_answered(): void
    {
        $dead = $this->subscriber('فوّاز خليل', '8823');
        $dead->reportMeterBroken('العدّاد محروق', $this->collector->id);

        $row = $this->row($this->queue(), 'meters');

        $this->assertSame(TodayQueue::TONE_WARNING, $row['tone'], 'a fresh report is a visit, not a decision');
        $this->assertStringContainsString('عدّاد واحد معطّل', $row['title']);
        $this->assertStringContainsString('بلا حالات بدها قرار', implode(' ', $row['detail']));
    }

    public function test_a_case_that_spent_its_estimated_cycle_is_urgent(): void
    {
        $dead = $this->subscriber('فوّاز خليل', '8823');
        $dead->reportMeterBroken('العدّاد محروق', $this->collector->id);
        $dead->forceFill(['meter_broken_estimates' => 1])->save();

        $row = $this->row($this->queue(), 'meters');

        $this->assertSame(TodayQueue::TONE_DANGER, $row['tone'], 'somebody has to sign a decision');
        $this->assertStringContainsString('حالة وحدة بدها قرار', implode(' ', $row['detail']));
    }

    public function test_with_no_dead_meters_the_row_reads_as_closed(): void
    {
        $this->subscriber('محمد عيتاني');

        $this->assertNull($this->queue()->items()->firstWhere('key', 'meters'));
        $this->assertSame('ما في عدّاد معطّل معلّق', $this->doneRow($this->queue(), 'meters')['title']);
    }

    // ---- cash in hand ---------------------------------------------------------

    public function test_cash_in_a_collector_pocket_is_named_with_how_long_he_has_held_it(): void
    {
        $this->payment(2100);

        CashHandover::create([
            'collector_id' => $this->collector->id,
            'amount' => 400,
            'handover_date' => now()->subDays(5)->toDateString(),
            'recorded_by' => $this->admin->id,
        ]);

        $row = $this->row($this->queue(), 'cash');

        $this->assertSame(TodayQueue::TONE_WARNING, $row['tone']);
        $this->assertStringContainsString('سامر', implode(' ', $row['detail']));
        $this->assertStringContainsString('$1,700.00', implode(' ', $row['detail']));
        $this->assertStringContainsString('آخر تسليم منذ 5 أيام', implode(' ', $row['detail']));
    }

    public function test_a_collector_who_never_handed_over_is_said_so_plainly(): void
    {
        $this->payment(900);

        $this->assertStringContainsString('ما سلّم ولا مرة', implode(' ', $this->row($this->queue(), 'cash')['detail']));
    }

    public function test_handing_the_cash_over_closes_the_row(): void
    {
        $this->payment(900);

        CashHandover::create([
            'collector_id' => $this->collector->id,
            'amount' => 900,
            'handover_date' => now()->toDateString(),
            'recorded_by' => $this->admin->id,
        ]);

        $this->assertNull($this->queue()->items()->firstWhere('key', 'cash'));
        $this->assertSame('ولا محصّل عندو كاش بإيده', $this->doneRow($this->queue(), 'cash')['title']);
    }

    public function test_the_cash_row_names_the_three_largest_and_summarises_the_rest(): void
    {
        foreach (['سامر', 'علي', 'هدى', 'وسيم'] as $name) {
            $collector = User::create([
                'name' => $name, 'email' => mb_strtolower($name).'@today.test',
                'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
            ]);
            $this->payment(100, $collector);
        }

        $row = $this->row($this->queue(), 'cash');

        $this->assertCount(4, $row['detail'], 'three names plus the summary line');
        $this->assertStringContainsString('غيرهم', implode(' ', $row['detail']));
    }

    // ---- the billing run ------------------------------------------------------

    public function test_the_billing_row_waits_until_the_cycle_can_actually_be_run(): void
    {
        $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('سمير خليل', '2011');

        // One house read, one still out: the cycle is work in progress, not work waiting.
        $this->reading(Subscriber::query()->firstWhere('meter_no', '1123'), $this->period);

        $this->assertNull($this->queue()->items()->firstWhere('key', 'bills'));
    }

    public function test_the_billing_row_appears_when_every_reading_is_in(): void
    {
        $metered = $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('ناديا خليل', null, null, Subscriber::TYPE_FLAT);
        $this->reading($metered, $this->period);

        $row = $this->row($this->queue(), 'bills');

        $this->assertSame(TodayQueue::TONE_WARNING, $row['tone']);
        $this->assertStringContainsString('مشتركان جاهزان للفاتورة', $row['title']);
        $this->assertStringContainsString('عدّاد واحد جاهز', implode(' ', $row['detail']));
        $this->assertStringContainsString('فلات واحد جاهز', implode(' ', $row['detail']));
    }

    public function test_the_billing_row_closes_when_every_active_subscriber_has_a_bill(): void
    {
        $metered = $this->subscriber('محمد عيتاني', '1123');
        $flat = $this->subscriber('ناديا خليل', null, null, Subscriber::TYPE_FLAT);
        $this->reading($metered, $this->period);
        $this->bill($metered, $this->period);
        $this->bill($flat, $this->period);

        $this->assertNull($this->queue()->items()->firstWhere('key', 'bills'));
        $this->assertStringContainsString('كل مشترك فعّال', $this->doneRow($this->queue(), 'bills')['title']);
    }

    // ---- expenses -------------------------------------------------------------

    public function test_the_expense_row_fires_when_the_book_stops_moving(): void
    {
        Expense::create([
            'category' => Expense::CAT_FUEL, 'expense_date' => now()->subDays(9)->toDateString(),
            'amount' => 180, 'description' => 'Diesel', 'entered_by' => $this->admin->id,
        ]);

        // Nine days back only counts when it is inside this month; early in a month the entry
        // predates it and the silence is what has elapsed since the 1st.
        $expected = max((int) now()->startOfDay()->diffInDays(now()->startOfMonth()), 9);

        $row = $this->row($this->queue(), 'expenses');

        $this->assertSame(TodayQueue::TONE_MUTED, $row['tone'], 'a quiet book is not an emergency');
        $this->assertStringContainsString("{$expected} أيام بلا قيد مصاريف", $row['title']);
        $this->assertStringContainsString('آخر قيد', implode(' ', $row['detail']));
        $this->assertStringContainsString('$180.00', implode(' ', $row['detail']));
    }

    public function test_a_recent_expense_keeps_the_row_quiet(): void
    {
        Expense::create([
            'category' => Expense::CAT_FUEL, 'expense_date' => now()->toDateString(),
            'amount' => 180, 'description' => 'Diesel', 'entered_by' => $this->admin->id,
        ]);

        $this->assertNull($this->queue()->items()->firstWhere('key', 'expenses'));
        $this->assertSame('المصاريف مقيّدة', $this->doneRow($this->queue(), 'expenses')['title']);
    }

    // ---- records --------------------------------------------------------------

    public function test_a_house_no_collector_walks_or_the_office_cannot_call_is_flagged(): void
    {
        $this->subscriber('بلا منطقة', null, $this->route)->forceFill(['route_id' => null])->save();
        $this->subscriber('بلا تلفون', '3001', null, Subscriber::TYPE_METERED, null);

        $row = $this->row($this->queue(), 'records');

        $this->assertSame(TodayQueue::TONE_INFO, $row['tone']);
        $this->assertStringContainsString('مشتركان ناقصين البيانات', $row['title']);
        $this->assertStringContainsString('مشترك واحد بلا منطقة', implode(' ', $row['detail']));
        $this->assertStringContainsString('مشترك واحد بلا تلفون', implode(' ', $row['detail']));
    }

    public function test_a_complete_record_set_closes_the_row(): void
    {
        $this->subscriber('محمد عيتاني');

        $this->assertNull($this->queue()->items()->firstWhere('key', 'records'));
    }

    // ---- the standing debt ----------------------------------------------------

    public function test_the_debt_row_carries_what_is_red(): void
    {
        $house = $this->subscriber('محمد عيتاني');
        $this->bill($house, now()->subMonthsNoOverflow(2)->format('Y-m'), 300);

        $row = $this->row($this->queue(), 'overdue');

        $this->assertSame(TodayQueue::TONE_INFO, $row['tone'], 'a standing condition sits below the day\'s work');
        $this->assertStringContainsString('مشترك واحد متأخر', $row['title']);
        $this->assertStringContainsString('$300.00', implode(' ', $row['detail']));
    }

    public function test_a_network_with_no_debt_says_so(): void
    {
        $this->subscriber('محمد عيتاني');

        $this->assertSame('ما في ديون متأخرة', $this->doneRow($this->queue(), 'overdue')['title']);
    }

    // ---- the shape of the list ------------------------------------------------

    public function test_urgency_orders_the_rows(): void
    {
        // One row per tone: a missing reading (danger), cash in a pocket (warning), a house with
        // no phone (info) and a month with nothing written in the expense book (muted).
        $this->subscriber('بلا تلفون', '3001', null, Subscriber::TYPE_METERED, null);
        $this->payment(500);

        $tones = $this->queue()->items()->pluck('tone')->all();

        $this->assertSame(['danger', 'warning', 'info', 'muted'], $tones);
        $this->assertSame(
            $tones,
            collect($tones)->sortBy(fn (string $tone): int => TodayQueue::rank($tone))->values()->all(),
            'the list is already in the order rank() defines'
        );
    }

    public function test_an_office_with_nothing_outstanding_gets_an_empty_worklist(): void
    {
        $this->settleEverything();

        $queue = $this->queue();

        $this->assertTrue($queue->items()->isEmpty());
        $this->assertNotEmpty($queue->done());
        $this->assertSame(
            ['readings', 'meters', 'cash', 'bills', 'expenses', 'records', 'overdue'],
            $queue->done()->pluck('key')->all()
        );
    }

    public function test_the_empty_worklist_says_so_on_screen(): void
    {
        $this->settleEverything();
        $this->actingAs($this->admin);

        Livewire::test(TodayComponent::class)
            ->assertSee('خلص كل شي')
            ->assertSee('المصاريف مقيّدة');
    }

    // ---- the strip ------------------------------------------------------------

    public function test_the_strip_counts_what_today_brought_in(): void
    {
        $this->payment(120);
        $this->payment(80);
        $this->reading($this->subscriber('محمد عيتاني', '1123'), $this->period);

        // Looked up by label, not by value: two figures can legitimately read the same, and a
        // keyed lookup would then be reading whichever came last.
        $strip = collect($this->queue()->strip())->keyBy(fn (array $figure): string => $figure['label']);

        $cash = $strip->get(__('today.strip_cash'));
        $this->assertSame('$200.00', $cash['value'], 'cash taken today');
        $this->assertSame('وصلين', $cash['hint'], 'two receipts, in Arabic');

        $this->assertSame('1', $strip->get(__('today.strip_readings'))['value'], 'one reading walked today');
        $this->assertSame('$200.00', $strip->get(__('today.strip_in_hand'))['value'], 'and it is still in his pocket');
        $this->assertSame('محصّل واحد', $strip->get(__('today.strip_in_hand'))['hint']);
    }

    public function test_a_cycle_can_be_asked_for_by_name(): void
    {
        $house = $this->subscriber('محمد عيتاني');
        $past = now()->subMonthsNoOverflow(1)->format('Y-m');
        $this->reading($house, $this->period);

        // This cycle is read, so nothing is pending in it…
        $this->assertNull($this->queue($this->period)->items()->firstWhere('key', 'readings'));

        // …while asking about last month finds last month's hole, not today's figure.
        $this->assertSame($past, $this->queue($past)->period());
        $row = $this->row($this->queue($past), 'readings');
        $this->assertStringContainsString('قراءة واحدة ناقصة', $row['title']);
    }

    // ---- the component --------------------------------------------------------

    public function test_the_component_renders_the_rows_and_the_refresh(): void
    {
        $this->subscriber('محمد عيتاني');
        $this->actingAs($this->admin);

        Livewire::test(TodayComponent::class)
            ->assertSee('بدو شغل هلّق')
            ->assertSee('قراءة واحدة ناقصة')
            ->assertSee('تحديث')
            ->assertSee('متابعة القراءات');
    }

    // ---- wording --------------------------------------------------------------

    public function test_the_counted_labels_use_the_arabic_plural_forms(): void
    {
        $this->assertSame('قراءة واحدة ناقصة تمنع الفاتورة', plural('today.readings_title', 1));
        $this->assertSame('قراءتان ناقصتان تمنعان الفاتورة', plural('today.readings_title', 2));
        $this->assertSame('3 قراءات ناقصة تمنع الفاتورة', plural('today.readings_title', 3));
        $this->assertSame('13 قراءة ناقصة تمنع الفاتورة', plural('today.readings_title', 13));

        $this->assertSame('سلّم اليوم', plural('today.cash_days', 0));
        $this->assertSame('آخر تسليم منذ يومين', plural('today.cash_days', 2));
        $this->assertSame('آخر تسليم منذ 5 أيام', plural('today.cash_days', 5));
        $this->assertSame('آخر تسليم منذ 40 يوم', plural('today.cash_days', 40));

        $this->assertSame('يوم واحد بلا قيد مصاريف', plural('today.expenses_gap', 1));
        $this->assertSame('9 أيام بلا قيد مصاريف', plural('today.expenses_gap', 9));
    }

    public function test_every_string_exists_in_both_languages(): void
    {
        // A network where every row has something to say, so no row escapes the check by being
        // absent. The English pass is the interesting one: a key added to ar.json only would
        // otherwise render as the raw 'today.something' on the admin's own screen.
        $dead = $this->subscriber('فوّاز خليل', '8823');
        $dead->reportMeterBroken('العدّاد محروق', $this->collector->id);
        $this->subscriber('بلا تلفون', '3001', $this->otherRoute, Subscriber::TYPE_METERED, null);
        $this->payment(500);
        $this->bill($dead, now()->subMonthsNoOverflow(2)->format('Y-m'), 300);

        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);

            $words = $this->words($this->queue());

            $this->assertStringNotContainsString('today.', $words, "A key fell through in {$locale}.");
            foreach (['strip_', 'readings_', 'meters_', 'cash_', 'bills_', 'expenses_', 'records_', 'overdue_'] as $prefix) {
                $this->assertStringNotContainsString($prefix, $words, "A raw key survived in {$locale}.");
            }
        }

        app()->setLocale('ar');
    }
}
