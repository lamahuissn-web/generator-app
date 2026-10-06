<?php

namespace Tests\Feature;

use App\Services\ReadingService;
use App\Livewire\Collector\SubscriberShow;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Broken meters: an estimated cycle, a replacement mid-cycle, and the two conversion
 * paths (flat rate ↔ meter). The program must never dead-end because a meter died.
 */
class BrokenMeterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** The field side of every case: who reported the meter. */
    private User $collector;

    private Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeAtTheSeptemberCycle();

        $this->admin = User::create([
            'name' => 'Admin', 'email' => 'admin@x.com', 'password' => 'x',
            'role' => 'admin', 'locale' => 'en',
        ]);
        $this->collector = User::create([
            'name' => 'Jad', 'email' => 'field@x.com', 'password' => 'x',
            'role' => 'collector', 'phone' => '03 111222', 'locale' => 'ar',
        ]);
        $route = Route::create(['name' => 'Corniche']);

        $this->subscriber = Subscriber::create([
            'route_id' => $route->id,
            'name' => 'Ali Hassan',
            'phone' => '03 111222',
            'meter_no' => '1123',
            'type' => Subscriber::TYPE_METERED,
            'amperage' => 10,
            'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE,
            'created_by' => $this->admin->id,
        ]);
    }

    /** Three real cycles: 40, 50 and 60 kWh at $0.25 — the average is 50. */
    private function billThreeCycles(): void
    {
        Reading::create([
            'subscriber_id' => $this->subscriber->id, 'period' => '2026-06',
            'current_reading' => 1000, 'previous_reading' => 0, 'consumption' => 0,
        ]);
        foreach (['2026-07' => 1040, '2026-08' => 1090, '2026-09' => 1150] as $period => $value) {
            BillingService::enterMeteredReading($this->subscriber, $period, $value, $this->admin->id);
        }
    }

    // ---- estimation -----------------------------------------------------------

    public function test_suggested_consumption_averages_the_last_three_real_readings(): void
    {
        $this->billThreeCycles();

        $suggestion = BillingService::suggestedConsumption($this->subscriber, '2026-10');

        $this->assertSame(50.0, $suggestion['kwh']);           // (40+50+60)/3
        $this->assertSame(3, $suggestion['count']);
        $this->assertSame(['2026-09', '2026-08', '2026-07'], $suggestion['periods']);
    }

    public function test_the_suggestion_ignores_baseline_and_earlier_estimates(): void
    {
        $this->billThreeCycles();

        // An estimated cycle at 100 kWh must not drag the average up…
        BillingService::enterEstimatedReading($this->subscriber, '2026-10', 100, 'Meter dead', $this->admin->id);
        $suggestion = BillingService::suggestedConsumption($this->subscriber, '2026-11');
        $this->assertSame(50.0, $suggestion['kwh']);
        $this->assertSame(3, $suggestion['count']);

        // …and a brand-new subscriber (only the baseline row) has no history at all.
        $fresh = Subscriber::create([
            'route_id' => $this->subscriber->route_id, 'name' => 'Nadia', 'meter_no' => 'K9',
            'type' => Subscriber::TYPE_METERED, 'maintenance_fee' => 15,
            'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        BillingService::recordBaselineReading($fresh, '2026-09', 700, $this->admin->id);
        $this->assertNull(BillingService::suggestedConsumption($fresh, '2026-10'));
    }

    public function test_estimated_cycle_bills_the_kwh_and_becomes_the_new_baseline(): void
    {
        $this->billThreeCycles();   // meter sits at 1150

        $result = BillingService::enterEstimatedReading($this->subscriber, '2026-10', 55, 'Meter dead', $this->admin->id);
        $reading = $result['reading'];
        $bill = $result['bill'];

        $this->assertTrue($reading->is_estimated);
        $this->assertSame('Meter dead', $reading->estimate_reason);
        $this->assertSame(55.0, (float) $reading->consumption);
        $this->assertSame(1205.0, (float) $reading->current_reading);   // 1150 + 55, stored as the meter value
        $this->assertSame(13.75, (float) $bill->usage_amount);          // 55 × $0.25
        $this->assertSame(28.75, (float) $bill->total);                 // + $15 maintenance
        $this->assertSame('Estimated reading', $reading->sourceLabel());

        // No catch-up: the next REAL reading continues from the estimate.
        $next = BillingService::enterMeteredReading($this->subscriber, '2026-11', 1245, $this->admin->id);
        $this->assertSame(40.0, (float) $next['reading']->consumption);  // 1245 - 1205
        $this->assertFalse($next['reading']->is_estimated);
    }

    public function test_estimated_cycle_can_charge_maintenance_only(): void
    {
        $this->billThreeCycles();

        $bill = BillingService::enterEstimatedReading($this->subscriber, '2026-10', 0, 'Meter dead', $this->admin->id)['bill'];

        $this->assertSame(0.0, (float) $bill->consumption);
        $this->assertSame(0.0, (float) $bill->usage_amount);
        $this->assertSame(15.0, (float) $bill->total);   // maintenance only
    }

    public function test_the_estimate_keeps_the_collection_gate_open(): void
    {
        $this->billThreeCycles();

        // Before: October is outstanding for this subscriber (the gate that blocks the run).
        $before = BillingService::readingProgress('2026-10');
        $this->assertSame(1, $before['pending']);
        $this->assertSame(0, $before['recorded']);

        BillingService::enterEstimatedReading($this->subscriber, '2026-10', 50, 'Meter dead', $this->admin->id);

        // After: the cycle counts as covered, so a dead meter never blocks the monthly run.
        $after = BillingService::readingProgress('2026-10');
        $this->assertSame(0, $after['pending']);
        $this->assertSame(1, $after['recorded']);
    }

    // ---- meter replacement ----------------------------------------------------

    public function test_meter_replacement_splits_the_cycle_across_both_meters(): void
    {
        $this->billThreeCycles();   // meter 1123 sits at 1150

        $result = BillingService::enterMeteredReading(
            $this->subscriber,
            '2026-10',
            12,                     // the new meter has run 12 kWh since it was fitted at 0
            $this->admin->id,
            null,
            ['swap' => ['old_meter_no' => '1123', 'old_reading' => 1170, 'new_meter_no' => '4478', 'new_start' => 0]],
        );

        $reading = $result['reading'];

        $this->assertSame(32.0, (float) $reading->consumption);   // (1170-1150) + (12-0)
        $this->assertTrue($reading->hasMeterSwap());
        $this->assertSame(8.0, (float) $result['bill']->usage_amount);
        $this->assertSame('1123', $reading->old_meter_no);
        $this->assertSame(1170.0, (float) $reading->old_meter_reading);
        $this->assertSame('4478', $reading->new_meter_no);
        $this->assertSame(0.0, (float) $reading->new_meter_start);
        $this->assertStringContainsString('4478', (string) $reading->meterSwapLabel());

        // The subscriber now carries the new meter, and the next cycle continues from it.
        $this->assertSame('4478', $this->subscriber->fresh()->meter_no);
        $next = BillingService::enterMeteredReading($this->subscriber->fresh(), '2026-11', 40, $this->admin->id);
        $this->assertSame(28.0, (float) $next['reading']->consumption);   // 40 - 12
    }

    public function test_meter_replacement_rejects_readings_below_what_they_continue_from(): void
    {
        $this->billThreeCycles();

        // Old meter's final reading below the previous reading.
        try {
            BillingService::enterMeteredReading($this->subscriber, '2026-10', 5, $this->admin->id, null, [
                'swap' => ['old_meter_no' => '1123', 'old_reading' => 1100, 'new_meter_no' => 'X1', 'new_start' => 0],
            ]);
            $this->fail('Expected a rejection for an old-meter reading below the previous one.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('final reading', $e->getMessage());
        }

        // New meter reading below its own starting value.
        try {
            BillingService::enterMeteredReading($this->subscriber, '2026-10', 3, $this->admin->id, null, [
                'swap' => ['old_meter_no' => '1123', 'old_reading' => 1170, 'new_meter_no' => 'X1', 'new_start' => 10],
            ]);
            $this->fail('Expected a rejection for a new-meter reading below its start.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('starting value', $e->getMessage());
        }

        $this->assertSame(0, Reading::where('period', '2026-10')->count());
    }

    public function test_a_real_reading_closes_the_broken_meter_case(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('العدّاد بيطفي ويشعل', $this->admin->id);
        $this->assertTrue($this->subscriber->fresh()->hasBrokenMeterReport());

        // The meter works after all: a REAL value is evidence about the device, so the case closes.
        BillingService::enterMeteredReading($this->subscriber->fresh(), '2026-10', 1200, $this->admin->id);

        $fresh = $this->subscriber->fresh();
        $this->assertFalse($fresh->hasBrokenMeterReport());
        $this->assertNull($fresh->meter_broken_note);
        $this->assertSame(0, $fresh->brokenEstimatesUsed());
    }

    public function test_a_meter_replacement_closes_the_case_with_the_new_device(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('محروق', $this->admin->id);

        BillingService::enterMeteredReading($this->subscriber->fresh(), '2026-10', 5, $this->admin->id, null, [
            'swap' => ['old_meter_no' => '1123', 'old_reading' => 1170, 'new_meter_no' => '4478', 'new_start' => 0],
        ]);

        $fresh = $this->subscriber->fresh();
        $this->assertFalse($fresh->hasBrokenMeterReport(), 'the case is about the dead device; a new device ends it');
        $this->assertSame('4478', $fresh->meter_no);
    }

    // ---- the one-estimate policy ----------------------------------------------

    public function test_an_estimate_answers_the_cycle_but_leaves_the_case_open(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('العدّاد معطّل', $this->collector->id);

        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-10', 50, 'العدّاد معطّل', $this->admin->id);

        $fresh = $this->subscriber->fresh();

        // The cycle is billed — the monthly run is not blocked by a dead meter…
        $this->assertSame(1, Bill::where('period', '2026-10')->count());
        $this->assertSame(0, BillingService::readingProgress('2026-10')['pending']);

        // …but the meter is still dead, and that is now visible everywhere.
        $this->assertTrue($fresh->hasBrokenMeterReport(), 'an estimate answers the cycle, not the meter');
        $this->assertSame(1, $fresh->brokenEstimatesUsed());
        $this->assertFalse($fresh->mayEstimateAgain());
        $this->assertSame('العدّاد معطّل', $fresh->meter_broken_note);
    }

    public function test_a_second_estimate_for_the_same_dead_meter_is_refused(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('العدّاد معطّل', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-10', 50, 'العدّاد معطّل', $this->admin->id);

        try {
            BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-11', 50, 'العدّاد معطّل', $this->admin->id);
            $this->fail('A second estimated month on one dead meter must be refused.');
        } catch (\RuntimeException $e) {
            // The refusal names the policy and the cycle the case opened in — the two facts the
            // person reading it needs to act.
            $this->assertStringContainsString('One estimate is allowed', $e->getMessage());
            $this->assertStringContainsString(period_label('2026-09'), $e->getMessage());
        }

        // The office reads the same refusal in Arabic.
        app()->setLocale('ar');
        try {
            BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-11', 50, 'العدّاد معطّل', $this->admin->id);
            $this->fail('The policy must hold in every locale.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تقديرية وحدة', $e->getMessage());
        }

        $this->assertSame(0, Bill::where('period', '2026-11')->count());
        $this->assertSame(1, $this->subscriber->fresh()->brokenEstimatesUsed());
    }

    /** The office's deliberate exception: a second estimated month, logged with its reason. */
    public function test_the_extension_bills_the_second_estimate_and_records_it_as_an_exception(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('العدّاد بالورشة', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-10', 50, 'العدّاد معطّل', $this->admin->id);

        BillingService::enterEstimatedReading(
            $this->subscriber->fresh(), '2026-11', 45, 'التصليح متأخّر', $this->admin->id, true,
        );

        $fresh = $this->subscriber->fresh();
        $this->assertSame(2, $fresh->brokenEstimatesUsed());
        $this->assertTrue($fresh->hasBrokenMeterReport(), 'extending does not fix anything, so the case stays open');
        $this->assertSame(11.25, (float) Bill::where('period', '2026-11')->firstOrFail()->usage_amount);   // 45 × $0.25

        $log = AuditLog::where('action', 'reading.created')->latest('id')->first();
        $this->assertTrue($log->payload['estimate_extension']);
        $this->assertSame(2, $log->payload['estimates_used']);
        $this->assertStringContainsString('التصليح متأخّر', (string) $log->payload['estimate_reason']);
    }

    /**
     * The policy is about a dead meter, not about estimates in general: a locked gate or a house
     * nobody answered is an ordinary administrative estimate and must not be rationed.
     */
    public function test_estimates_without_an_open_case_are_not_rationed(): void
    {
        $this->billThreeCycles();

        BillingService::enterEstimatedReading($this->subscriber, '2026-10', 50, 'ما حدا فتح البيت', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-11', 50, 'ما حدا فتح البيت', $this->admin->id);

        $this->assertSame(2, Reading::where('is_estimated', true)->count());
        $this->assertFalse($this->subscriber->fresh()->hasBrokenMeterReport());
    }

    /** A closed case starts clean: the counter belongs to the device that died, not the subscriber. */
    public function test_a_new_report_after_a_closed_case_starts_with_a_fresh_allowance(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('أول عدّاد', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-10', 50, 'x', $this->admin->id);

        // The meter is replaced, so the case closes and the count goes back to zero.
        BillingService::enterMeteredReading($this->subscriber->fresh(), '2026-11', 5, $this->admin->id, null, [
            'swap' => ['old_meter_no' => '1123', 'old_reading' => 1225, 'new_meter_no' => '4478', 'new_start' => 0],
        ]);

        $this->assertSame(0, $this->subscriber->fresh()->brokenEstimatesUsed());

        // The new meter dies too: the office gets its one estimate again.
        $this->subscriber->fresh()->reportMeterBroken('العدّاد الجديد معطّل', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-12', 40, 'معطّل', $this->admin->id);

        $fresh = $this->subscriber->fresh();
        $this->assertSame(1, $fresh->brokenEstimatesUsed());
        $this->assertTrue($fresh->hasBrokenMeterReport());
    }

    /** Re-reporting a live case refreshes the note without making it look brand new. */
    public function test_reporting_the_same_meter_twice_keeps_the_original_date_and_count(): void
    {
        $this->billThreeCycles();
        $this->subscriber->reportMeterBroken('أول بلاغ', $this->admin->id);
        BillingService::enterEstimatedReading($this->subscriber->fresh(), '2026-10', 50, 'x', $this->admin->id);

        $before = $this->subscriber->fresh();
        $reportedAt = $before->meter_broken_reported_at;

        $before->reportMeterBroken('لسا معطّل', $this->collector->id);

        $after = $this->subscriber->fresh();
        $this->assertSame(1, $after->brokenEstimatesUsed());
        $this->assertTrue($reportedAt->equalTo($after->meter_broken_reported_at));
        $this->assertSame('لسا معطّل', $after->meter_broken_note);
    }

    // ---- conversions ----------------------------------------------------------

    public function test_converting_to_flat_drops_the_meter_and_audits_the_reason(): void
    {
        $this->billThreeCycles();

        $this->subscriber->convertToFlat(60, 'العدّاد معطّل ولم يُستبدل', $this->admin->id);
        $fresh = $this->subscriber->fresh();

        $this->assertTrue($fresh->isFlat());
        $this->assertSame(60.0, (float) $fresh->flat_fee);
        $this->assertNull($fresh->meter_no);
        $this->assertNull($fresh->amperage);

        // The next cycle bills the flat fee, no readings needed.
        $run = BillingService::periodRun('2026-10', null, true, $this->admin->id);
        $this->assertSame(1, $run['created']);
        $this->assertSame(60.0, (float) $fresh->bills()->where('period', '2026-10')->firstOrFail()->total);

        $log = AuditLog::where('action', 'subscriber.converted_to_flat')->latest('id')->first();
        $this->assertSame('العدّاد معطّل ولم يُستبدل', $log->payload['reason']);
        $this->assertSame('1123', $log->payload['released_meter']);
    }

    public function test_converting_the_same_way_twice_is_refused(): void
    {
        $this->subscriber->convertToFlat(60, 'x', $this->admin->id);

        $this->expectException(\RuntimeException::class);
        $this->subscriber->fresh()->convertToFlat(70, 'again', $this->admin->id);
    }

    public function test_converting_back_to_metered_records_a_baseline_so_the_first_cycle_is_not_billed_whole(): void
    {
        $this->subscriber->convertToFlat(60, 'meter gone', $this->admin->id);

        $reading = $this->subscriber->fresh()->convertToMetered(
            '4478', 250, 10, 25, 'عدّاد جديد', '2026-10', $this->admin->id,
        );

        $fresh = $this->subscriber->fresh();
        $this->assertTrue($fresh->isMetered());
        $this->assertSame('4478', $fresh->meter_no);
        $this->assertSame(10, $fresh->amperage);
        $this->assertSame(25.0, (float) $fresh->maintenance_fee);
        $this->assertNull($fresh->flat_fee);

        // The baseline charges nothing…
        $this->assertSame(0.0, (float) $reading->consumption);
        $this->assertSame(250.0, (float) $reading->current_reading);
        $this->assertNull($reading->bill, 'A baseline reading must not produce a bill.');

        // …so the first real cycle bills only the difference from 250.
        $next = BillingService::enterMeteredReading($fresh, '2026-11', 290, $this->admin->id);
        $this->assertSame(40.0, (float) $next['reading']->consumption);
        $this->assertSame(10.0, (float) $next['bill']->usage_amount);
        $this->assertSame(35.0, (float) $next['bill']->total);   // + $25 maintenance
    }

    public function test_converting_back_is_refused_while_the_meter_is_still_there(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->subscriber->convertToMetered('X', 0, 10, 15, 'why', '2026-10', $this->admin->id);
    }

    // ---- admin surfaces -------------------------------------------------------

    public function test_the_meter_actions_render_on_the_list_and_the_profile(): void
    {
        $this->actingAs($this->admin);
        $this->billThreeCycles();

        $this->get('/admin/subscribers')->assertOk();
        $this->get('/admin/subscribers/'.$this->subscriber->id)->assertOk();

        $flat = Subscriber::create([
            'route_id' => $this->subscriber->route_id, 'name' => 'Flat Guy', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 60, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);
        $this->get('/admin/subscribers/'.$flat->id)->assertOk();
    }

    public function test_the_readings_page_offers_the_three_modes(): void
    {
        $this->actingAs($this->admin);
        $this->billThreeCycles();

        $this->get('/admin/readings')->assertOk();
        $this->get('/admin/readings/create')->assertOk()
            ->assertSee(__('t.mode_normal'))
            ->assertSee(__('t.mode_estimated'))
            ->assertSee(__('t.mode_swap'));
    }

    public function test_the_reading_form_records_each_mode(): void
    {
        $this->actingAs($this->admin);
        $this->billThreeCycles();

        // Estimated
        $data = [
            'subscriber_id' => $this->subscriber->id,
            'period' => '2026-10',
            'reading_mode' => ReadingService::MODE_ESTIMATED,
            'estimated_kwh' => '48',
            'estimate_reason' => 'العدّاد معطّل',
        ];
        $result = ReadingService::record($this->subscriber, $data, $this->admin->id);
        $this->assertTrue($result['reading']->is_estimated);
        $this->assertSame(48.0, (float) $result['reading']->consumption);

        // Replacement
        $data = [
            'subscriber_id' => $this->subscriber->id,
            'period' => '2026-11',
            'reading_mode' => ReadingService::MODE_SWAP,
            'current_reading' => '9',
            'old_meter_no' => '1123',
            'old_meter_reading' => '1198',
            'new_meter_no' => '4478',
            'new_meter_start' => '0',
        ];
        $result = ReadingService::record($this->subscriber->fresh(), $data, $this->admin->id);
        $this->assertTrue($result['reading']->hasMeterSwap());
        $this->assertSame(9.0, (float) $result['reading']->consumption);   // 1198 - 1198 (estimate bump) + 9
        $this->assertSame('4478', $this->subscriber->fresh()->meter_no);

        // Normal
        $data = [
            'subscriber_id' => $this->subscriber->id,
            'period' => '2026-12',
            'reading_mode' => ReadingService::MODE_NORMAL,
            'current_reading' => '30',
        ];
        $result = ReadingService::record($this->subscriber->fresh(), $data, $this->admin->id);
        $this->assertFalse($result['reading']->is_estimated);
        $this->assertFalse($result['reading']->hasMeterSwap());
        $this->assertSame(21.0, (float) $result['reading']->consumption);   // 30 - 9
    }

    // ---- print / portal -------------------------------------------------------

    public function test_the_printed_bill_shows_the_estimate_and_the_meter_change(): void
    {
        $this->billThreeCycles();

        $estimated = BillingService::enterEstimatedReading($this->subscriber, '2026-10', 55, 'العدّاد معطّل', $this->admin->id)['bill'];
        $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $estimated->id, 'size' => 'a5']))
            ->assertOk()
            ->assertSee(__('prt.estimated_badge'))
            ->assertSee('العدّاد معطّل');

        $swap = BillingService::enterMeteredReading($this->subscriber->fresh(), '2026-11', 4, $this->admin->id, null, [
            'swap' => ['old_meter_no' => '1123', 'old_reading' => 1225, 'new_meter_no' => '4478', 'new_start' => 0],
        ])['bill'];

        $this->actingAs($this->admin)
            ->get(route('print.bill', ['bill' => $swap->id, 'size' => 'a5']))
            ->assertOk()
            ->assertSee('4478')
            ->assertSee(__('prt.meter_old_final'))
            ->assertSee(__('prt.meter_new_start'));
    }

    public function test_the_collector_can_report_a_dead_meter_from_the_field(): void
    {
        $collector = User::create([
            'name' => 'Jad', 'email' => 'j@x.com', 'password' => 'x',
            'role' => 'collector', 'phone' => '03 111222', 'locale' => 'ar',
        ]);

        Livewire::actingAs($collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber])
            ->set('view', 'reading')
            ->call('toggleBrokenReport')
            ->set('brokenNote', 'العدّاد بيطفي ويشعل')
            ->call('reportBrokenMeter')
            ->assertHasNoErrors();

        $fresh = $this->subscriber->fresh();
        $this->assertTrue($fresh->hasBrokenMeterReport());
        $this->assertSame('العدّاد بيطفي ويشعل', $fresh->meter_broken_note);

        // The field device reloads onto the reading view and shows the report banner.
        \Livewire\Livewire::actingAs($collector)
            ->test(\App\Livewire\Collector\SubscriberShow::class, ['subscriber' => $fresh])
            ->set('view', 'reading')
            ->assertSee(__('portal.broken_banner_title'))
            ->assertSee('العدّاد بيطفي ويشعل')
            ->assertDontSee(__('portal.broken_btn'));   // no second report while one is open

        // The office sees it on the list, and can clear it when the meter is sorted.
        $this->actingAs($this->admin)->get('/admin/subscribers')->assertOk()->assertSee(__('t.meter_broken_badge'));

        Livewire::actingAs($collector)
            ->test(SubscriberShow::class, ['subscriber' => $fresh])
            ->call('cancelBrokenReport')
            ->assertHasNoErrors();
        $this->assertFalse($this->subscriber->fresh()->hasBrokenMeterReport());
    }

    public function test_a_collector_cannot_report_a_meter_that_already_has_a_reading(): void
    {
        $collector = User::create([
            'name' => 'Jad', 'email' => 'j2@x.com', 'password' => 'x',
            'role' => 'collector', 'locale' => 'ar',
        ]);
        $this->billThreeCycles();
        BillingService::enterEstimatedReading($this->subscriber, '2026-10', 10, 'x', $this->admin->id);

        Livewire::actingAs($collector)
            ->test(SubscriberShow::class, ['subscriber' => $this->subscriber->fresh()])
            ->set('period', '2026-10')
            ->call('reportBrokenMeter')
            ->assertHasErrors('broken');

        $this->assertFalse($this->subscriber->fresh()->hasBrokenMeterReport());
    }

    public function test_the_estimate_suggestion_matches_what_the_admin_screen_prefills(): void
    {
        // The reading resource's own hint must agree with the service, or the operator is
        // shown a number that is not what gets billed.
        $this->billThreeCycles();
        Carbon::setTestNow(Carbon::parse('2026-10-05 09:00'));

        $suggestion = BillingService::suggestedConsumption($this->subscriber, '2026-10');
        $this->assertSame(50.0, $suggestion['kwh']);
        $this->assertSame(1150.0, BillingService::previousValue($this->subscriber, '2026-10'));

        $bill = BillingService::enterEstimatedReading($this->subscriber, '2026-10', $suggestion['kwh'], 'x', $this->admin->id)['bill'];
        $this->assertSame(12.5, (float) $bill->usage_amount);   // 50 × $0.25

        Carbon::setTestNow();
        $this->assertSame(1, Bill::where('period', '2026-10')->count());
    }
}
