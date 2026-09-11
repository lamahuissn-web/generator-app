<?php

namespace Tests\Feature;

use App\Livewire\Admin\Pages\MeterCases;
use App\Livewire\Collector\SubscriberShow;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Reports\MeterCaseBoard as Report;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dead-meter decision room: every reported meter, what has been done about it, and what it is
 * waiting for.
 *
 * A dead meter used to leave no trace the moment its cycle was billed, so this suite pins the two
 * halves of the fix: the case SURVIVES an estimate, and the office gets one screen where the three
 * ways out of it (a reading, a replacement, a flat rate — plus the logged extension) are in reach.
 * It also pins the field's side of the same rule: a reported-dead meter has no input to type into.
 */
class MeterCasesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $collector;

    private Route $route;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        // The cycle under test is one month past the history built below, so "this cycle" is a
        // cycle with no reading yet — which is the state every case in this suite starts from.
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00'));

        app()->setLocale('ar');

        $this->admin = User::create([
            'name' => 'Generator Admin', 'email' => 'admin@cases.test',
            'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'locale' => 'ar',
        ]);
        $this->collector = User::create([
            'name' => 'Jad Haddad', 'email' => 'jad@cases.test', 'phone' => '03 111222',
            'password' => bcrypt('secret'), 'role' => User::ROLE_COLLECTOR, 'locale' => 'ar',
        ]);

        $this->route = Route::create(['name' => 'Ain El Remmaneh']);

        $this->subscriber = Subscriber::create([
            'route_id' => $this->route->id,
            'name' => 'مخبز الشام',
            'phone' => '76 888999',
            'meter_no' => '8823',
            'amperage' => 20,
            'type' => Subscriber::TYPE_METERED,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
            'status' => Subscriber::STATUS_ACTIVE,
        ]);

        // Three ordinary cycles, so the house has a history to show beside the case.
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
        foreach (['2026-07' => 1040, '2026-08' => 1090, '2026-09' => 1150] as $period => $value) {
            BillingService::enterMeteredReading($this->subscriber, $period, $value, $this->admin->id);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> the single row the report returns */
    private function onlyRow(?Report $report = null): array
    {
        return ($report ?? Report::make())->rows()->first();
    }

    // ---- the screen -----------------------------------------------------------

    public function test_the_decision_room_is_reachable_from_the_sidebar_and_the_reading_board(): void
    {
        $this->subscriber->reportMeterBroken('العدّاد محروق', $this->collector->id);

        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.meter-cases'));

        // The reading board counts them; the count is a link, because no reading can clear a case.
        $this->actingAs($this->admin)
            ->get(route('admin.readings-board'))->assertOk()
            ->assertSee(route('admin.meter-cases'));
    }

    public function test_the_room_lists_the_case_with_its_history_and_what_is_due(): void
    {
        $this->subscriber->reportMeterBroken('العدّاد محروق', $this->collector->id);

        $this->actingAs($this->admin)
            ->get(route('admin.meter-cases'))
            ->assertOk()
            ->assertSee('مخبز الشام')
            ->assertSee('العدّاد محروق')
            ->assertSee('Jad Haddad')          // who in the field reported it
            ->assertSee('جديد — بلا قرار')       // nothing decided yet, and the screen says so
            ->assertSee('العدّاد معطّل');
    }

    public function test_a_collector_cannot_open_the_decision_room(): void
    {
        $this->actingAs($this->collector)
            ->get(route('admin.meter-cases'))
            ->assertForbidden();
    }

    public function test_only_open_cases_are_listed(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);

        $healthy = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'سمير خليل', 'meter_no' => '2011',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);

        $names = Report::make()->rows()->pluck('name')->all();

        $this->assertSame(['مخبز الشام'], $names);
        $this->assertNotContains('سمير خليل', $names);
        $this->assertSame(1, Report::make()->summary()['total']);
        $this->assertNotNull($healthy->id);
    }

    // ---- the state of a case --------------------------------------------------

    public function test_a_case_with_no_estimate_yet_is_new(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);

        $row = $this->onlyRow();

        $this->assertSame(Report::STATE_NEW, $row['state']);
        $this->assertSame(0, $row['estimates_used']);
        $this->assertFalse($row['cycle_read']);
        $this->assertSame(now()->format('Y-m'), $row['case_period']);
        $this->assertSame('Jad Haddad', $row['reported_by']);
        $this->assertSame('8823', $row['meter_no']);

        // The history the deciding admin needs, as data rather than as a proposal.
        $this->assertSame(1150.0, $row['previous']);
        $this->assertSame(50.0, $row['average']);            // (40 + 50 + 60) / 3
        $this->assertSame('2026-09', $row['last_real_period']);
        $this->assertSame(60.0, $row['last_real_consumption']);
    }

    public function test_a_case_that_used_its_estimate_needs_a_decision(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);
        BillingService::enterEstimatedReading(
            $this->subscriber->fresh(), now()->format('Y-m'), 55, 'العدّاد معطّل', $this->admin->id,
        );

        $row = $this->onlyRow(Report::make(state: Report::STATE_ALL));

        $this->assertSame(Report::STATE_DECISION, $row['state']);
        $this->assertSame(1, $row['estimates_used']);
        $this->assertTrue($row['cycle_read'], 'the cycle is answered, the meter is not');
        $this->assertTrue($row['cycle_estimated']);

        // The default filter is the whole list: both kinds of case are work.
        $this->assertSame(1, Report::make()->rows()->count());
        $this->assertSame(1, Report::make(state: Report::STATE_DECISION)->rows()->count());
        $this->assertSame(0, Report::make(state: Report::STATE_NEW)->rows()->count());
    }

    public function test_the_summary_counts_what_the_office_still_owes(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);
        BillingService::enterEstimatedReading(
            $this->subscriber->fresh(), now()->format('Y-m'), 55, 'العدّاد معطّل', $this->admin->id,
        );

        // A second case, reported but untouched.
        $second = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'جورج صليبا', 'meter_no' => '1125',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);
        $second->reportMeterBroken(null, $this->collector->id);

        $summary = Report::make()->summary();

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['decision'], 'only the case whose estimate is spent needs a decision');
        $this->assertSame(1, $summary['estimates']);
        $this->assertSame(1, $summary['unread_cycle']);
    }

    public function test_the_oldest_case_is_listed_first(): void
    {
        $second = Subscriber::create([
            'route_id' => $this->route->id, 'name' => 'أحدث بلاغ', 'meter_no' => '1126',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);

        // The older report carries an earlier date on purpose: age, not insertion order, ranks.
        $this->subscriber->reportMeterBroken('من زمان', $this->collector->id);
        $this->subscriber->forceFill(['meter_broken_reported_at' => now()->subMonths(4)])->save();
        $second->reportMeterBroken('هالشهر', $this->collector->id);

        $report = Report::make();
        $rows = $report->rows();

        $this->assertSame(['مخبز الشام', 'أحدث بلاغ'], $rows->pluck('name')->all());
        $this->assertSame(4, $rows->first()['age']);
        $this->assertSame(4, $report->summary()['oldest']);
    }

    public function test_the_route_filter_narrows_the_room(): void
    {
        $otherRoute = Route::create(['name' => 'Tariq El Jdideh']);
        $other = Subscriber::create([
            'route_id' => $otherRoute->id, 'name' => 'حسن عواد', 'meter_no' => '3301',
            'type' => Subscriber::TYPE_METERED, 'status' => Subscriber::STATUS_ACTIVE,
            'maintenance_mode' => Subscriber::MAINT_EXEMPT,
        ]);

        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);
        $other->reportMeterBroken('معطّل كمان', $this->collector->id);

        $this->assertSame(2, Report::make()->rows()->count());
        $this->assertSame(['حسن عواد'], Report::make($otherRoute->id)->rows()->pluck('name')->all());
    }

    // ---- the ways out ---------------------------------------------------------

    public function test_the_room_offers_the_reading_the_replacement_and_the_flat_rate(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);

        $this->actingAs($this->admin);

        // Every case carries the actions that can end it, named as the operator reads them…
        Livewire::test(MeterCases::class)
            ->assertSee(__('a.record_reading'))      // carries the estimate and the replacement
            ->assertSee(__('a.convert_flat'))
            // …and NOT the extension: with the allowance unspent it would quietly become the policy.
            ->assertDontSeeHtml('data-meter-case-extension');
    }

    public function test_the_extension_is_offered_once_the_allowance_is_spent(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);
        BillingService::enterEstimatedReading(
            $this->subscriber->fresh(), now()->format('Y-m'), 55, 'العدّاد معطّل', $this->admin->id,
        );

        $this->actingAs($this->admin);

        Livewire::test(MeterCases::class)
            ->assertSee('بدها قرار')
            ->assertSeeHtml('data-meter-case-extension');
    }

    public function test_reporting_the_meter_working_again_closes_the_case_from_the_room(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);
        BillingService::enterEstimatedReading(
            $this->subscriber->fresh(), now()->format('Y-m'), 55, 'العدّاد معطّل', $this->admin->id,
        );

        $this->actingAs($this->admin);

        Livewire::test(MeterCases::class)
            ->call('clearReport', $this->subscriber->id)
            ->assertHasNoErrors();

        $fresh = $this->subscriber->fresh();
        $this->assertFalse($fresh->hasBrokenMeterReport());
        $this->assertSame(0, $fresh->brokenEstimatesUsed(), 'closing the case clears its allowance');
        $this->assertSame(0, Report::make()->rows()->count());
    }

    public function test_closing_a_case_that_is_not_open_is_refused(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(MeterCases::class)
            ->call('clearReport', $this->subscriber->id)
            ->assertHasErrors('clear');
    }

    public function test_the_csv_carries_the_case_and_its_state(): void
    {
        $this->subscriber->reportMeterBroken('محروق', $this->collector->id);

        $csv = Report::make()->csv();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('مخبز الشام', $csv);
        $this->assertStringContainsString('Jad Haddad', $csv);
        $this->assertStringContainsString('جديد — بلا قرار', $csv);
        $this->assertStringContainsString(now()->format('Y-m'), $csv);
    }

    // ---- the field's side of the same rule ------------------------------------

    public function test_a_reported_dead_meter_cannot_be_read_from_the_house_screen_either(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);

        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber->fresh()])
            ->set('view', 'reading')
            ->set('readingInput', '1400')
            // No preview: there is no bill to show for a value that cannot be billed…
            ->assertDontSee(__('portal.save_reading'))
            ->call('saveReading')
            ->assertHasErrors('reading');

        $this->assertSame(0, Reading::where('period', now()->format('Y-m'))->count());
    }

    public function test_the_field_can_still_say_the_meter_works_again(): void
    {
        $this->subscriber->reportMeterBroken('معطّل', $this->collector->id);

        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber->fresh()])
            ->set('view', 'reading')
            ->call('cancelBrokenReport')
            ->assertHasNoErrors();

        $this->assertFalse($this->subscriber->fresh()->hasBrokenMeterReport());

        // …and the reading can then be taken normally.
        Livewire::actingAs($this->collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber->fresh()])
            ->set('view', 'reading')
            ->set('readingInput', '1200')
            ->call('saveReading')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('readings', [
            'subscriber_id' => $this->subscriber->id,
            'period' => now()->format('Y-m'),
            'current_reading' => 1200,
        ]);
    }

    // ---- wording --------------------------------------------------------------

    public function test_every_case_string_exists_in_both_languages(): void
    {
        $report = Report::make();

        foreach ([Report::STATE_NEW, Report::STATE_DECISION] as $state) {
            $label = $report->nextStepLabel([
                'state' => $state,
                'cycle_read' => false,
                'case_period' => now()->format('Y-m'),
            ]);

            $this->assertNotSame('', $label);
            $this->assertStringNotContainsString('mc.', $label, 'a case string fell back to its raw key');
        }

        $this->assertSame(
            'بلاغ '.period_label_ar(now()->format('Y-m')).' — تقديريات مستعملة: 0',
            $report->caseLabel(['case_period' => now()->format('Y-m'), 'estimates_used' => 0]),
        );

        foreach (array_keys(Report::stateOptions()) as $state) {
            $this->assertNotSame('', __(Report::stateOptions()[$state]));
        }
    }
}
