<?php

namespace Tests\Feature;

use App\Admin\Tables\SubscriberBillsTable;
use App\Admin\Tables\SubscriberPaymentsTable;
use App\Admin\Tables\SubscriberReadingsTable;
use App\Livewire\Admin\DataTable;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\SubscriberFile;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A subscriber's file: their account read as of ONE cycle.
 *
 * The screen this replaces could only say "this subscriber owes $110" — an all-time number — and
 * the three records it was made of sat behind tabs, unlinked: a bill never said which reading
 * produced it, and no single month could be discussed on its own ("شو دفع آب؟"). These tests pin
 * the three rules that make the file answerable:
 *
 *   1. the numbers belong to the CYCLE being read, and whatever the cycle leaves out is reported
 *      beside them, so a scoped view can never look square while another month is unpaid;
 *   2. a receipt belongs to a cycle when its money actually reached one of THAT cycle's invoices;
 *   3. the cycle survives a Livewire round trip — sorting or paging a log inside the file must not
 *      quietly widen it back to every cycle.
 */
class SubscriberFileTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed cycles: the assertions must not move with the calendar. */
    private const JULY = '2026-07';

    private const AUGUST = '2026-08';

    private const SEPTEMBER = '2026-09';

    private User $admin;

    private User $collector;

    private Subscriber $subscriber;

    private Bill $julyBill;

    private Bill $augustBill;

    private Bill $septemberBill;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@file.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@file.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->subscriber = Subscriber::create([
            'route_id' => $route->id, 'name' => 'محمد عيتاني', 'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);

        // July: the meter's starting point (a baseline reading bills nothing) plus a 40 bill that
        //       carries no reading at all.
        // August: read and billed 60.
        // September: the cycle being worked — read and billed 50.
        // Then ONE receipt of 50, which clears July (40) and puts 10 towards August.
        $this->reading(self::JULY, 0, 1200);
        $augustReading = $this->reading(self::AUGUST, 1200, 1270);
        $septemberReading = $this->reading(self::SEPTEMBER, 1270, 1370);

        $this->julyBill = $this->bill(self::JULY, 40.0);
        $this->augustBill = $this->bill(self::AUGUST, 60.0, $augustReading);
        $this->septemberBill = $this->bill(self::SEPTEMBER, 50.0, $septemberReading);

        BillingService::collect($this->subscriber, 50, $this->collector->id);
    }

    private function reading(string $period, float $previous, float $current, bool $estimated = false): Reading
    {
        return Reading::create([
            'subscriber_id' => $this->subscriber->id,
            'period' => $period,
            'previous_reading' => $previous,
            'current_reading' => $current,
            'consumption' => $current - $previous,
            'is_estimated' => $estimated,
            'entered_by' => $this->admin->id,
        ]);
    }

    private function bill(string $period, float $total, ?Reading $reading = null): Bill
    {
        return Bill::create([
            'number' => 'INV-'.$period.'-9001',
            'subscriber_id' => $this->subscriber->id,
            'period' => $period,
            'type' => Subscriber::TYPE_METERED,
            'reading_id' => $reading?->id,
            'consumption' => $reading?->consumption ?? 0,
            'usage_amount' => $total,
            'total' => $total,
            'paid_amount' => 0,
        ]);
    }

    private function file(?string $period = null): SubscriberFile
    {
        return new SubscriberFile($this->subscriber, $period);
    }

    private function page(string $query = ''): TestResponse
    {
        return $this->actingAs($this->admin)
            ->get('/admin/subscribers/'.$this->subscriber->id.$query);
    }

    // ---- the three numbers ----------------------------------------------------

    public function test_the_whole_account_adds_up(): void
    {
        $totals = $this->file()->totals();

        $this->assertSame(150.0, $totals['billed'], 'July 40 + August 60 + September 50');
        $this->assertSame(50.0, $totals['paid'], 'the one receipt of 50');
        $this->assertSame(100.0, $totals['due']);
        // With every cycle in scope there is nothing left out, whatever the arithmetic says.
        $this->assertSame(0.0, $totals['other_cycles_due']);

        $this->page()
            ->assertOk()
            ->assertSee('فواتير الاستهلاك')
            ->assertSee('$150.00')
            ->assertSee('$100.00')
            ->assertDontSee('منها');
    }

    public function test_a_cycle_is_read_on_its_own(): void
    {
        $july = $this->file(self::JULY)->totals();
        $this->assertSame(40.0, $july['billed'], "July's own invoice only");
        $this->assertSame(40.0, $july['paid'], 'the receipt swept July first — it is the oldest debt');
        $this->assertSame(0.0, $july['due']);

        $august = $this->file(self::AUGUST)->totals();
        $this->assertSame(60.0, $august['billed']);
        $this->assertSame(10.0, $august['paid'], 'what was left of the receipt after July');
        $this->assertSame(50.0, $august['due']);

        $this->page('?period='.self::SEPTEMBER)
            ->assertOk()
            ->assertSee('$50.00')
            ->assertSee('سجل واحد');       // one bill in September, and the badge says so
    }

    public function test_the_cycles_the_scope_leaves_out_are_reported_next_to_it(): void
    {
        $september = $this->file(self::SEPTEMBER)->totals();

        // September's own invoice is unpaid…
        $this->assertSame(50.0, $september['due']);
        // …and so is August's remaining 50, which the filter must not hide.
        $this->assertSame(50.0, $september['other_cycles_due']);

        // July is settled, so a file read as of July shows a clean card PLUS the 100 elsewhere.
        $july = $this->file(self::JULY)->totals();
        $this->assertSame(0.0, $july['due']);
        $this->assertSame(100.0, $july['other_cycles_due']);

        $this->page('?period='.self::JULY)
            ->assertOk()
            ->assertSee('منها')
            ->assertSee('$100.00');
    }

    public function test_an_unknown_cycle_falls_back_to_the_whole_account(): void
    {
        // A hand-edited or stale link must not filter the account down to nothing.
        $this->page('?period=1999-01')
            ->assertOk()
            ->assertSee('$150.00')
            ->assertDontSee('منها');
    }

    public function test_the_cycles_offered_come_from_the_house_itself(): void
    {
        $periods = $this->file()->periods();

        $this->assertArrayHasKey(self::JULY, $periods);
        $this->assertArrayHasKey(self::AUGUST, $periods);
        $this->assertArrayHasKey(self::SEPTEMBER, $periods);
        // The cycle being worked is always offered, even before anything is billed in it.
        $this->assertArrayHasKey(BillingService::currentPeriod(), $periods);
        // Nothing invented out of the panel's calendar: three bills, three readings, this month.
        $this->assertLessThanOrEqual(5, count($periods));
    }

    // ---- counts -----------------------------------------------------------------

    public function test_each_log_carries_its_own_count(): void
    {
        $counts = $this->file()->counts();

        $this->assertSame(3, $counts['bills']);
        $this->assertSame(3, $counts['readings']);
        $this->assertSame(1, $counts['payments']);

        // Scoped to one cycle, the count follows the scope.
        $scoped = $this->file(self::SEPTEMBER)->counts();
        $this->assertSame(1, $scoped['bills']);
        $this->assertSame(1, $scoped['readings']);
        $this->assertSame(0, $scoped['payments']);

        $this->page()
            ->assertOk()
            ->assertSee('3 سجلات')        // three readings
            ->assertSee('سجل واحد');      // one receipt
    }

    public function test_a_cycle_with_no_receipt_says_so_instead_of_listing_nothing(): void
    {
        $this->page('?period='.self::SEPTEMBER)
            ->assertOk()
            ->assertSee('ما في سندات قبض بالمدى المختار.');
    }

    // ---- reading ↔ invoice -------------------------------------------------------

    public function test_a_readings_row_names_the_invoice_it_produced(): void
    {
        $this->page()
            ->assertOk()
            ->assertSee($this->augustBill->number)
            ->assertSee('/print/bill/'.$this->augustBill->id, false)
            // The baseline reading of July bills nothing, and says so instead of leaving a hole.
            ->assertSee('بلا فاتورة');
    }

    public function test_a_bill_row_shows_the_reading_it_was_made_from(): void
    {
        $this->page()
            ->assertOk()
            // One cell, one fact, isolated so the pair cannot render backwards in Arabic.
            ->assertSee(reading_pair(1200, 1270), false)
            ->assertSee('70.0')           // the consumption it represents
            // July's bill carries no reading at all, and the row admits it.
            ->assertSee('بلا قراءة');
    }

    public function test_the_profile_tables_drop_what_the_page_already_says(): void
    {
        $bills = array_column(SubscriberBillsTable::make()->columns(), 'key');
        $readings = array_column(SubscriberReadingsTable::make()->columns(), 'key');
        $payments = array_column(SubscriberPaymentsTable::make()->columns(), 'key');

        // The subscriber's own name on every row of their own file is noise in three tables.
        foreach ([$bills, $readings, $payments] as $keys) {
            $this->assertNotContains('subscriber.name', $keys);
        }

        // The pair of readings replaces the two columns the wide list needs.
        $this->assertNotContains('previous_reading', $readings);
        $this->assertNotContains('current_reading', $readings);
        $this->assertContains('reading_pair', $readings);
        $this->assertContains('bill', $readings);

        // An open bill's own action became redundant once the bill is linked in its column.
        $this->assertNotContains('bill', array_column(SubscriberReadingsTable::make()->rowActions(), 'key'));
    }

    // ---- receipts -----------------------------------------------------------------

    public function test_a_receipt_belongs_to_every_cycle_its_money_cleared(): void
    {
        $receipt = $this->subscriber->payments()->firstOrFail();

        // The one receipt of 50 swept July (40) and reached August (10), so both cycles hold it…
        $this->assertSame(1, $this->file(self::JULY)->paymentsQuery()->count());
        $this->assertSame(1, $this->file(self::AUGUST)->paymentsQuery()->count());
        // …and September, which it never touched, does not pretend to have one.
        $this->assertSame(0, $this->file(self::SEPTEMBER)->paymentsQuery()->count());

        $this->page('?period='.self::AUGUST)->assertOk()->assertSee($receipt->number);
        $this->page('?period='.self::SEPTEMBER)->assertOk()->assertDontSee($receipt->number);
    }

    public function test_a_receipt_belongs_to_the_cycle_it_cleared_and_not_the_one_after(): void
    {
        $first = $this->subscriber->payments()->firstOrFail();

        // August's remaining 50 is the oldest debt, so the next receipt settles AUGUST…
        $second = BillingService::collect($this->subscriber, 50, $this->collector->id)['payment'];

        $this->assertSame(0.0, $this->file(self::AUGUST)->totals()['due']);
        $this->assertContains($second->number, $this->file(self::AUGUST)->paymentsQuery()->pluck('number')->all());
        // …and September, still open, does not get to claim it.
        $this->assertSame([], $this->file(self::SEPTEMBER)->paymentsQuery()->pluck('number')->all());
        $this->assertSame(50.0, $this->file(self::SEPTEMBER)->totals()['due']);

        // Only when the money finally reaches September does its own log hold a receipt.
        $third = BillingService::collect($this->subscriber, 50, $this->collector->id)['payment'];

        $this->assertSame(0.0, $this->file(self::SEPTEMBER)->totals()['due']);
        $this->assertSame([$third->number], $this->file(self::SEPTEMBER)->paymentsQuery()->pluck('number')->all());
        $this->assertNotContains($third->number, $this->file(self::AUGUST)->paymentsQuery()->pluck('number')->all());
        $this->assertContains($first->number, $this->file(self::AUGUST)->paymentsQuery()->pluck('number')->all());
    }

    // ---- the cycle has to survive the component ------------------------------------

    public function test_sorting_a_log_inside_the_file_keeps_the_cycle(): void
    {
        Livewire::actingAs($this->admin)
            ->test(DataTable::class, [
                'table' => SubscriberBillsTable::class,
                'constraints' => ['subscriber_id' => $this->subscriber->id],
                'compact' => true,
                'period' => self::AUGUST,
            ])
            ->call('sortBy', 'total')
            ->assertSee($this->augustBill->number)
            // July and September are outside the scope: a re-render must not re-admit them.
            ->assertDontSee($this->julyBill->number)
            ->assertDontSee($this->septemberBill->number);
    }

    public function test_a_compact_log_ignores_state_meant_for_another_table(): void
    {
        // The file renders three logs on one page and Livewire binds `search`/`sort` to the URL per
        // component WITHOUT a namespace: a term or a sort aimed at one log would narrow or re-order
        // all three — and a compact log has no box or header to explain or undo it. It therefore
        // reads nothing from the query string, while the office's own list still does.
        $this->page('?q=nothing-matches-this&sort=consumption&direction=desc')
            ->assertOk()
            ->assertSee($this->julyBill->number)
            ->assertSee($this->septemberBill->number)
            ->assertSee(reading_pair(0, 1200), false);   // the July reading is still listed

        $this->actingAs($this->admin)
            ->get('/admin/bills?q=nothing-matches-this')
            ->assertOk()
            ->assertDontSee($this->julyBill->number);
    }

    public function test_a_compact_log_keeps_its_own_order_on_a_header_click(): void
    {
        Livewire::actingAs($this->admin)
            ->test(DataTable::class, [
                'table' => SubscriberBillsTable::class,
                'constraints' => ['subscriber_id' => $this->subscriber->id],
                'compact' => true,
            ])
            ->call('sortBy', 'total')
            // Newest cycle first, as the definition asks — not by the amount that was clicked.
            ->assertSeeInOrder([
                $this->septemberBill->number,
                $this->augustBill->number,
                $this->julyBill->number,
            ]);
    }

    public function test_a_compact_log_offers_no_second_set_of_controls(): void
    {
        // The page above already chose the subscriber AND the cycle, so the search box, the
        // filters and the export button are gone; the rows and their actions are not.
        Livewire::actingAs($this->admin)
            ->test(DataTable::class, [
                'table' => SubscriberReadingsTable::class,
                'constraints' => ['subscriber_id' => $this->subscriber->id],
                'compact' => true,
            ])
            ->assertDontSee('wire:model.live.debounce', false)
            ->assertDontSee('exportCsv', false)
            ->assertSee(reading_pair(0, 1200), false);

        // The same table on the office's own list keeps its toolbar: compact is the caller's choice.
        Livewire::actingAs($this->admin)
            ->test(DataTable::class, ['table' => SubscriberPaymentsTable::class])
            ->assertSee('wire:model.live.debounce', false);
    }
}
