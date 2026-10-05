<?php

namespace Tests\Feature\Api;

use App\Models\CashHandover;
use App\Models\User;

/**
 * The manager's board, as his phone shows it (spec 009, phase A).
 *
 * The whole surface of this feature is READ: a manager away from the desk answers «how did today go?»
 * and «how much is on each collector?». So the tests are about two things and nothing else — that every
 * figure is the office's own (the same expression the web dashboard reads), and that a collector can
 * never see another collector's cash.
 */
class ApiManagerBoardTest extends ApiTestCase
{
    public function test_the_board_carries_the_days_and_the_months_money(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09', '2026-10']);
        $this->pay($house, 60, $this->collector, now()->toDateString());

        $board = $this->api($this->admin, 'GET', '/api/v1/manager/board', [], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->json();

        $this->assertSame($this->period, $board['period']);

        // What was collected this month, and the rate against what was invoiced — the panel's own two
        // figures, not a second opinion computed here.
        $this->assertSame('60.00', $board['money']['collected']);
        $this->assertSame('60.00', $board['money']['outstanding']);

        // One active house, metered or flat, and the cycle's consumption.
        $this->assertSame(1, $board['houses']['active']);
        $this->assertSame(0, $board['houses']['metered']);
        $this->assertSame(1, $board['houses']['flat']);

        // The cycle's own invoice (60) against what came in against the month (60) — the panel's own two
        // figures, not a second opinion computed here.
        $this->assertSame('60.00', $board['money']['invoiced']);
        $this->assertSame(100.0, (float) $board['money']['rate']);
    }

    public function test_each_collector_carries_what_he_collected_and_what_he_still_holds(): void
    {
        $house = $this->flatHouse('بيت أبو ندى', 60, ['2026-09', '2026-10']);
        $other = $this->person('سليم', 'salim@x.com', User::ROLE_COLLECTOR);

        $this->pay($house, 20, $this->collector, now()->toDateString());
        $this->pay($house, 30, $other, now()->toDateString());

        CashHandover::create([
            'collector_id' => $other->id,
            'amount' => 10,
            'handover_date' => now()->toDateString(),
        ]);

        $collectors = collect(
            $this->api($this->admin, 'GET', '/api/v1/manager/board')->assertOk()->json('collectors'),
        );

        $jaber = $collectors->firstWhere('name', $this->collector->name);
        $salim = $collectors->firstWhere('name', $other->name);

        // Today, and the whole cycle so far — the manager reads both because a collector's day and his
        // month are different questions.
        $this->assertSame('20.00', $jaber['today_total']);
        $this->assertSame(1, $jaber['today_count']);
        $this->assertSame('20.00', $jaber['in_hand']);
        $this->assertSame('0.00', $jaber['handed']);

        $this->assertSame('30.00', $salim['today_total']);
        $this->assertSame('30.00', $salim['collected']);
        $this->assertSame('10.00', $salim['handed']);
        // Cash the office is waiting for: everything he collected, less everything he handed over.
        $this->assertSame('20.00', $salim['in_hand']);
    }

    public function test_a_collector_never_sees_the_other_collectors_cash(): void
    {
        $this->flatHouse('بيت أبو علي', 60, ['2026-09']);

        $response = $this->api($this->collector, 'GET', '/api/v1/manager/board', [], ['Accept-Language' => 'ar']);

        $response->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
    }

    public function test_the_board_names_the_household_of_each_recent_payment(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09']);
        $this->pay($house, 60, $this->collector, now()->toDateString());

        $payments = $this->api($this->admin, 'GET', '/api/v1/manager/board')->assertOk()->json('recent_payments');

        $this->assertCount(1, $payments);
        $this->assertSame('بيت أبو علي', $payments[0]['subscriber']);
        $this->assertSame($this->collector->name, $payments[0]['collector']);
        $this->assertSame('60.00', $payments[0]['amount']);
        $this->assertSame(now()->toDateString(), $payments[0]['collected_on']);
    }

    public function test_a_collector_with_no_activity_is_still_on_the_board_with_zeros(): void
    {
        // A manager asking «who do I chase?» must see the collector who collected nothing, not only the
        // ones who appear in a payments table.
        $board = $this->api($this->admin, 'GET', '/api/v1/manager/board')->assertOk()->json();

        $this->assertCount(1, $board['collectors']);
        $this->assertSame($this->collector->name, $board['collectors'][0]['name']);
        $this->assertSame('0.00', $board['collectors'][0]['today_total']);
        $this->assertSame('0.00', $board['collectors'][0]['in_hand']);
    }
}
