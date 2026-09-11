<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\ReadingBoard as BoardComponent;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\ReadingBoard as Board;
use App\Services\BillingService;
use App\Services\ReadingQuality;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The office's reading board: who is missing a reading this cycle, and how long the missing
 * ones have been missing.
 *
 * The screen exists to answer two questions a coverage count cannot — WHICH house, and since
 * WHEN — so what is pinned here is that the right houses are flagged, that a leak is
 * separated from a dead meter and from a reading that merely looks odd, and that the counts
 * agree with the list. A board that lists the wrong houses is worse than no board: the office
 * stops walking the routes it believes are done.
 */
class ReadingBoardTest extends TestCase
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
            'name' => 'Generator Admin', 'email' => 'admin@board.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@board.test', 'phone' => '03 111222',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);
        $this->otherRoute = Route::create(['name' => 'Tariq El Jdideh']);
    }

    // ---- fixtures ------------------------------------------------------------

    private function subscriber(
        string $name,
        string $meter,
        Route $route = null,
        string $status = Subscriber::STATUS_ACTIVE,
        string $type = Subscriber::TYPE_METERED,
    ): Subscriber {
        return Subscriber::create([
            'route_id' => ($route ?? $this->route)->id,
            'name' => $name,
            'meter_no' => $type === Subscriber::TYPE_METERED ? $meter : null,
            'type' => $type,
            'flat_fee' => $type === Subscriber::TYPE_FLAT ? 50 : null,
            'amperage' => 10,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => $status,
        ]);
    }

    private function reading(Subscriber $subscriber, string $period, float $consumption, float $end = 1000, ?User $by = null): Reading
    {
        return Reading::create([
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'current_reading' => $end,
            'previous_reading' => $end - $consumption,
            'consumption' => $consumption,
            'entered_by' => ($by ?? $this->collector)->id,
        ]);
    }

    private function monthsBack(int $months): string
    {
        return now()->startOfMonth()->subMonths($months)->format('Y-m');
    }

    private function board(array $filters = []): Board
    {
        return Board::make(
            $filters['period'] ?? $this->period,
            $filters['route_id'] ?? null,
            $filters['state'] ?? Board::STATE_ATTENTION,
        );
    }

    private function rowsByAlert(Board $board): array
    {
        return $board->rows()->pluck('alert', 'name')->all();
    }

    // ---- the door ------------------------------------------------------------

    public function test_the_screen_renders_for_an_admin(): void
    {
        $this->subscriber('سمير خليل', '2011');

        $this->actingAs($this->admin)
            ->get(route('admin.readings-board'))
            ->assertOk()
            ->assertSee('متابعة القراءات')
            ->assertSee('سمير خليل');
    }

    public function test_a_collector_cannot_open_the_board(): void
    {
        $this->actingAs($this->collector)
            ->get(route('admin.readings-board'))
            ->assertForbidden();
    }

    public function test_the_board_is_reachable_from_the_sidebar(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.readings-board'));
    }

    // ---- who is missing, and since when --------------------------------------

    public function test_a_house_read_this_cycle_is_not_flagged_with_the_default_filter(): void
    {
        $done = $this->subscriber('محمد عيتاني', '1123');
        $this->reading($done, $this->period, 70, 1340);

        $missing = $this->subscriber('سمير خليل', '2011');
        $this->reading($missing, $this->monthsBack(1), 120, 880);

        $board = $this->board();

        $this->assertSame(['سمير خليل' => Board::ALERT_PENDING], $this->rowsByAlert($board));

        // …and the recorded one is still part of the cycle's figures.
        $summary = $board->summary();
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['recorded']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(50, $summary['percent']);
    }

    public function test_the_all_filter_lists_the_quiet_houses_too(): void
    {
        $done = $this->subscriber('محمد عيتاني', '1123');
        $this->reading($done, $this->period, 70, 1340);
        $this->subscriber('سمير خليل', '2011');

        $this->assertSame(2, $this->board(['state' => Board::STATE_ALL])->rows()->count());
        $this->assertSame(1, $this->board()->rows()->count());
    }

    public function test_a_house_nobody_has_read_for_months_is_flagged_as_a_leak(): void
    {
        $leak = $this->subscriber('مخبز الشام', '8823');
        $this->reading($leak, $this->monthsBack(Board::STALE_AFTER), 100, 500);

        // Two cycles back: still an ordinary miss, not yet a leak.
        $recent = $this->subscriber('ليلى نصر', '3390');
        $this->reading($recent, $this->monthsBack(2), 90, 400);

        $alerts = $this->rowsByAlert($this->board());

        $this->assertSame(Board::ALERT_STALE, $alerts['مخبز الشام']);
        $this->assertSame(Board::ALERT_PENDING, $alerts['ليلى نصر']);

        // The row carries the age of the gap, not just the fact of one.
        $row = $this->board()->rows()->firstWhere('name', 'مخبز الشام');
        $this->assertSame(Board::STALE_AFTER, $row['gap']);
        $this->assertSame($this->monthsBack(Board::STALE_AFTER), $row['last_period']);

        $this->assertSame(1, $this->board()->summary()['stale']);
    }

    public function test_a_metered_subscriber_with_no_reading_at_all_is_flagged_separately(): void
    {
        $this->subscriber('جورج صليبا', '1125');

        $row = $this->board()->rows()->first();

        $this->assertSame(Board::ALERT_NEVER, $row['alert']);
        $this->assertNull($row['last_period']);
        $this->assertNull($row['gap']);
        $this->assertSame(1, $this->board()->summary()['stale'], 'a never-read house is part of the unread total');
    }

    public function test_a_long_gap_sorts_above_a_short_one(): void
    {
        // Both are leaks, so this is the tie-break inside one alert: the older gap leads.
        $short = $this->subscriber('قصير', '1001');
        $this->reading($short, $this->monthsBack(4), 50, 200);

        $long = $this->subscriber('طويل', '1002');
        $this->reading($long, $this->monthsBack(6), 50, 200);

        $names = $this->board()->rows()->pluck('name')->all();

        $this->assertSame(['طويل', 'قصير'], $names);
    }

    // ---- the three kinds of trouble, kept apart ------------------------------

    public function test_a_reported_dead_meter_is_flagged_and_outranks_everything(): void
    {
        $dead = $this->subscriber('مخبز الشام', '8823');
        $dead->reportMeterBroken('العدّاد محروق', $this->collector->id);

        $leak = $this->subscriber('مخبز تاني', '8824');
        $this->reading($leak, $this->monthsBack(6), 100, 500);

        $rows = $this->board()->rows();

        $this->assertSame(Board::ALERT_BROKEN, $rows->first()['alert'], 'a meter waiting on a decision is the first row');
        $this->assertSame(1, $this->board()->summary()['broken']);
    }

    public function test_a_recorded_reading_of_zero_consumption_is_flagged_as_suspect(): void
    {
        $stable = $this->subscriber('ثابت', '1003');
        foreach ([3, 2, 1] as $back) {
            $this->reading($stable, $this->monthsBack($back), 100, 1000 + (3 - $back) * 100);
        }

        $flat = $this->subscriber('ليلى نصر', '3390');
        $this->reading($flat, $this->monthsBack(1), 100, 400);
        // This cycle: the same value as last cycle. Nothing was measured.
        $this->reading($flat, $this->period, 0, 400);

        $board = $this->board();
        $row = $board->rows()->firstWhere('name', 'ليلى نصر');

        $this->assertSame(Board::ALERT_SUSPECT, $row['alert']);
        $this->assertSame(ReadingQuality::ZERO, $row['verdict']);
        $this->assertSame('صفر استهلاك', $board->alertLabel($row));
        $this->assertSame(1, $board->summary()['suspect']);
    }

    public function test_a_reading_far_above_the_house_own_average_is_flagged_as_suspect(): void
    {
        $house = $this->subscriber('مؤسسة النور', '7712');

        // Its own history: three ordinary months around 100 kWh.
        foreach ([3, 2, 1] as $back) {
            $this->reading($house, $this->monthsBack($back), 100, 1000 + (3 - $back) * 100);
        }

        // This cycle it reads 400 kWh: a real month for a house that added an appliance, and
        // still worth a second look before it is invoiced.
        $this->reading($house, $this->period, 400, 1600);

        $board = $this->board();
        $row = $board->rows()->firstWhere('name', 'مؤسسة النور');

        $this->assertSame(Board::ALERT_SUSPECT, $row['alert']);
        $this->assertSame(ReadingQuality::JUMP, $row['verdict']);
        $this->assertSame(100.0, $row['average']);
        $this->assertStringContainsString('100 kWh', $board->alertLabel($row));
    }

    public function test_a_suspect_reading_does_not_hide_the_house_from_the_coverage_count(): void
    {
        $house = $this->subscriber('مؤسسة النور', '7712');
        $this->reading($house, $this->period, 0, 1000);

        $summary = $this->board()->summary();

        $this->assertSame(1, $summary['recorded'], 'a zero reading is still a reading that arrived');
        $this->assertSame(0, $summary['pending']);
        $this->assertSame(1, $summary['suspect']);
    }

    // ---- the other two groupings ---------------------------------------------

    public function test_the_board_reports_coverage_per_area(): void
    {
        $first = $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('سمير خليل', '2011');
        $this->reading($first, $this->period, 70, 1340);

        $other = $this->subscriber('حسن عواد', '3301', $this->otherRoute);
        $this->reading($other, $this->period, 19, 150);

        $coverage = $this->board()->coverage();

        $this->assertSame(3, $coverage['total']);
        $this->assertSame(2, $coverage['recorded']);
        $this->assertSame(1, $coverage['pending']);

        // Worst first: the area with a reading still to take leads.
        $this->assertSame('Ain El Remmaneh', $coverage['routes'][0]['name']);
        $this->assertSame(1, $coverage['routes'][0]['pending']);
        $this->assertSame(0, $coverage['routes'][1]['pending']);
    }

    public function test_the_board_reports_who_recorded_this_cycle_readings(): void
    {
        $first = $this->subscriber('محمد عيتاني', '1123');
        $second = $this->subscriber('سمير خليل', '2011');
        $third = $this->subscriber('ليلى نصر', '3390');

        $this->reading($first, $this->period, 70, 1340, $this->collector);
        $this->reading($third, $this->period, 40, 700, $this->collector);
        $this->reading($second, $this->period, 40, 1120, $this->admin);

        // An estimate from an earlier cycle must not be counted into this one.
        Reading::create([
            'subscriber_id' => $first->id, 'period' => $this->monthsBack(1),
            'current_reading' => 1200, 'previous_reading' => 1100, 'consumption' => 100,
            'is_estimated' => true, 'entered_by' => $this->collector->id,
        ]);

        $byCollector = $this->board()->byCollector();

        // Busiest first: the ranking is what makes this table worth reading.
        $this->assertSame(['Jad Haddad', 'Generator Admin'], $byCollector->pluck('name')->all());
        $this->assertSame(2, $byCollector->first()['count']);
        $this->assertSame('03 111222', $byCollector->first()['phone']);
        $this->assertSame(0, $byCollector->first()['estimated'], 'an estimate from another cycle is not this cycle work');
    }

    public function test_a_reading_with_no_user_behind_it_is_labelled_rather_than_dropped(): void
    {
        $house = $this->subscriber('محمد عيتاني', '1123');

        Reading::create([
            'subscriber_id' => $house->id, 'period' => $this->period,
            'current_reading' => 1340, 'previous_reading' => 1270, 'consumption' => 70,
            'note' => 'استيراد',
        ]);

        $byCollector = $this->board()->byCollector();

        $this->assertSame('بلا مستخدم', $byCollector->first()['name']);
    }

    // ---- filters -------------------------------------------------------------

    public function test_the_route_filter_narrows_the_board_and_its_figures(): void
    {
        $this->subscriber('محمد عيتاني', '1123');
        $this->subscriber('حسن عواد', '3301', $this->otherRoute);

        $board = $this->board(['route_id' => $this->otherRoute->id, 'state' => Board::STATE_ALL]);

        $this->assertSame(['حسن عواد'], $board->rows()->pluck('name')->all());
        $this->assertSame(1, $board->summary()['total']);
    }

    public function test_the_pending_filter_lists_only_houses_without_a_reading(): void
    {
        $done = $this->subscriber('محمد عيتاني', '1123');
        $this->reading($done, $this->period, 70, 1340);
        $this->subscriber('سمير خليل', '2011');

        $board = $this->board(['state' => Board::STATE_PENDING]);

        $this->assertSame(['سمير خليل'], $board->rows()->pluck('name')->all());

        // A filter changes what is listed, not what is true: the summary still counts both.
        $this->assertSame(2, $board->summary()['total']);
    }

    /** Flat-rate houses have no meter and inactive ones must not be billed: neither is a missing reading. */
    public function test_the_board_only_considers_live_metered_subscribers(): void
    {
        $this->subscriber('ناديا خليل', '', null, Subscriber::STATUS_ACTIVE, Subscriber::TYPE_FLAT);
        $this->subscriber('خليل حداد', '3302', null, Subscriber::STATUS_INACTIVE);
        $metered = $this->subscriber('محمد عيتاني', '1123');

        $names = $this->board(['state' => Board::STATE_ALL])->rows()->pluck('name')->all();

        $this->assertSame(['محمد عيتاني'], $names);
        $this->assertSame(1, $this->board()->summary()['total'], 'neither the flat nor the stopped house counts as missing');

        // …but the route coverage service agrees, since it is the same scope.
        $this->assertSame(1, $this->board()->coverage()['total']);
        $this->assertSame($metered->id, $this->board()->rows()->first()['id']);
    }

    // ---- the component and the export ----------------------------------------

    public function test_the_component_defaults_to_this_cycle_and_the_attention_filter(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(BoardComponent::class)
            ->assertSet('filters.period', now()->format('Y-m'))
            ->assertSet('filters.state', Board::STATE_ATTENTION);
    }

    public function test_the_component_lists_the_missing_and_names_them_on_screen(): void
    {
        $leak = $this->subscriber('مخبز الشام', '8823');
        $this->reading($leak, $this->monthsBack(4), 100, 500);

        $this->actingAs($this->admin);

        Livewire::test(BoardComponent::class)
            ->assertSee('مخبز الشام')
            ->assertSee('بلا قراءة من 4 أشهر')
            ->assertSee('التغطية حسب المنطقة')
            ->assertSee('من سجّل القراءات');
    }

    public function test_the_csv_carries_the_missing_houses_and_their_gap(): void
    {
        $leak = $this->subscriber('مخبز الشام', '8823');
        $this->reading($leak, $this->monthsBack(4), 100, 500);

        $csv = $this->board()->csv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'the BOM is what makes Arabic open correctly in Excel');
        $this->assertStringContainsString('مخبز الشام', $csv);
        $this->assertStringContainsString($this->monthsBack(4), $csv);
        $this->assertStringContainsString('بلا قراءة من 4 أشهر', $csv);
    }

    // ---- the rule itself ------------------------------------------------------

    public function test_the_quality_rule_is_the_same_one_both_screens_use(): void
    {
        $house = $this->subscriber('مؤسسة النور', '7712');

        foreach ([3, 2, 1] as $back) {
            $this->reading($house, $this->monthsBack($back), 100, 1000 + (3 - $back) * 100);
        }

        $this->assertSame(100.0, ReadingQuality::average($house, $this->period));

        $this->assertSame(ReadingQuality::ZERO, ReadingQuality::verdict($house, $this->period, 0.0));
        $this->assertNull(ReadingQuality::verdict($house, $this->period, 150.0));
        // Exactly at the threshold is not over it.
        $this->assertNull(ReadingQuality::verdict($house, $this->period, 300.0));
        $this->assertSame(ReadingQuality::JUMP, ReadingQuality::verdict($house, $this->period, 301.0));
    }

    public function test_a_house_with_no_history_is_never_called_a_jump(): void
    {
        $fresh = $this->subscriber('جورج صليبا', '1125');

        // A first reading cannot be compared to anything, so it is not suspect.
        $this->assertNull(ReadingQuality::average($fresh, $this->period));
        $this->assertNull(ReadingQuality::verdict($fresh, $this->period, 9000.0));
    }

    // ---- wording --------------------------------------------------------------

    public function test_the_new_counted_labels_use_the_arabic_plural_forms(): void
    {
        $this->assertSame('بلا قراءة من شهر', plural('rdgb.chip_gap', 1));
        $this->assertSame('بلا قراءة من شهرين', plural('rdgb.chip_gap', 2));
        $this->assertSame('بلا قراءة من 3 أشهر', plural('rdgb.chip_gap', 3));
        $this->assertSame('بلا قراءة من 11 شهر', plural('rdgb.chip_gap', 11));

        $this->assertSame('من شهر', plural('rdgb.months_ago', 1));
        $this->assertSame('من 4 أشهر', plural('rdgb.months_ago', 4));
        $this->assertSame('هالدورة', plural('rdgb.months_ago', 0));

        $this->assertSame('قراءة واحدة', plural('rdgb.readings_count', 1));
        $this->assertSame('4 قراءات', plural('rdgb.readings_count', 4));
    }

    public function test_every_board_string_exists_in_both_languages(): void
    {
        $board = Board::make($this->period);
        $labels = [];

        foreach ([Board::ALERT_BROKEN, Board::ALERT_NEVER, Board::ALERT_STALE, Board::ALERT_PENDING, Board::ALERT_SUSPECT, Board::ALERT_OK] as $alert) {
            $labels[$alert] = $board->alertLabel([
                'alert' => $alert,
                'gap' => 4,
                'verdict' => ReadingQuality::JUMP,
                'average' => 100.0,
            ]);
        }

        foreach ($labels as $alert => $label) {
            $this->assertNotSame('', $label, "The {$alert} chip has no wording.");
            $this->assertStringNotContainsString('rdgb.', $label, "The {$alert} chip fell back to its raw key.");
            $this->assertStringNotContainsString('rdg.', $label, "The {$alert} chip fell back to its raw key.");
        }
    }
}
