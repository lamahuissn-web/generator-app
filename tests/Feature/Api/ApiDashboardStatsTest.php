<?php

namespace Tests\Feature\Api;

use App\Models\CashHandover;
use App\Models\User;

/**
 * The collector's own day, as his dashboard shows it.
 *
 * The panel's dashboard and this answer must agree, so both read `CollectorStats` — one definition of
 * "today", "this month" and "still in his pocket". The date that matters is the BUSINESS date: a receipt
 * written at a door on the 3rd and synced on the 4th is the 3rd's money, exactly as its number, its
 * month and the office's handover are the 3rd's.
 */
class ApiDashboardStatsTest extends ApiTestCase
{
    public function test_the_day_month_and_pocket_come_from_the_collectors_own_money(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09', '2026-10']);

        // Two receipts today, one earlier this month.
        $this->pay($house, 10, $this->collector, now()->toDateString());
        $this->pay($house, 15, $this->collector, now()->toDateString());
        $earlier = now()->startOfMonth()->toDateString();
        $this->pay($house, 20, $this->collector, $earlier);

        CashHandover::create([
            'collector_id' => $this->collector->id,
            'amount' => 5,
            'handover_date' => now()->toDateString(),
            'note' => 'تسليم الصباح',
        ]);

        $stats = $this->api($this->collector, 'GET', '/api/v1/areas', [], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->json('stats');

        $this->assertSame('25.00', $stats['today']['total']);
        $this->assertSame(2, $stats['today']['count']);
        $this->assertSame('45.00', $stats['month']['total']);
        $this->assertSame(3, $stats['month']['count']);

        // Cash still in his pocket: everything he ever collected, less what he handed over.
        $this->assertSame('40.00', $stats['in_hand']);
        $this->assertSame('5.00', $stats['handed_total']);

        $this->assertSame(now()->toDateString(), $stats['handovers'][0]['date']);
        $this->assertSame('5.00', $stats['handovers'][0]['amount']);
        $this->assertSame('تسليم الصباح', $stats['handovers'][0]['note']);
    }

    public function test_a_receipt_synced_at_night_belongs_to_the_day_it_was_written(): void
    {
        $house = $this->flatHouse('بيت أبو ندى', 60, ['2026-09', '2026-10']);
        $yesterday = now()->subDay()->toDateString();

        $this->pay($house, 30, $this->collector, $yesterday);

        $stats = $this->api($this->collector, 'GET', '/api/v1/areas')->assertOk()->json('stats');

        // Not today's takings — the collector was not out collecting today, and a dashboard that says
        // otherwise makes him look short by a day's money at the handover.
        $this->assertSame('0.00', $stats['today']['total']);
        $this->assertSame(0, $stats['today']['count']);
        $this->assertSame('30.00', $stats['month']['total']);
    }

    public function test_a_collector_never_sees_another_collectors_cash(): void
    {
        $house = $this->flatHouse('بيت أبو فادي', 60, ['2026-09', '2026-10']);
        $other = $this->person('سليم', 'salim@x.com', User::ROLE_COLLECTOR);

        $this->pay($house, 40, $other, now()->toDateString());
        $this->pay($house, 10, $this->collector, now()->toDateString());

        $stats = $this->api($this->collector, 'GET', '/api/v1/areas')->assertOk()->json('stats');

        $this->assertSame('10.00', $stats['today']['total']);
        $this->assertSame('10.00', $stats['in_hand']);
        $this->assertSame([], $stats['handovers']);
    }
}
