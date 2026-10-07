<?php

namespace Tests\Feature\Api;

use App\Models\CashHandover;

/**
 * A collector's own purse (spec 011).
 *
 * The app told him about houses and never about his own cash — and in a cash business the number that runs
 * his day is the one in his pocket. So the endpoint answers about the TOKEN'S OWNER, from the same services
 * the panel reads (`CollectorStats`, `CollectionService::inHand`): if the phone ever summed for itself, the
 * collector would have two "today" figures — his and the office's.
 */
class ApiWalletTest extends ApiTestCase
{
    private function wallet(\App\Models\User $as)
    {
        return $this->api($as, 'GET', '/api/v1/me/summary', [], ['Accept-Language' => 'ar']);
    }

    public function test_a_collectors_purse_holds_today_this_month_and_what_is_in_his_pocket(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 100, [$this->period]);

        // Two receipts today, one yesterday: the day is the BUSINESS day, and «today» must not swallow the 3rd.
        $this->pay($house, 25, $this->collector, now()->toDateString());
        $this->pay($house, 15, $this->collector, now()->toDateString());
        $this->pay($house, 30, $this->collector, now()->subDay()->toDateString());

        $body = $this->wallet($this->collector)->assertStatus(200)->json();

        $this->assertSame($this->collector->id, $body['user']['id']);
        $this->assertSame($this->collector->name, $body['user']['name']);

        $this->assertSame('40.00', $body['money']['today_total']);
        $this->assertSame(2, $body['money']['today_count']);
        $this->assertSame('70.00', $body['money']['month_total']);
        $this->assertSame(3, $body['money']['month_count']);

        // Cash in his pocket: everything collected, minus everything handed over. The engine's own rule.
        $this->assertSame('70.00', $body['money']['in_hand']);
        $this->assertSame('0.00', $body['money']['handed_total']);
        $this->assertSame($this->period, $body['period']);
        $this->assertNotNull($body['label']);
    }

    public function test_handing_cash_over_moves_the_purse_and_shows_who_received_it(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 100, [$this->period]);
        $this->pay($house, 60, $this->collector, now()->toDateString());

        $this->api($this->admin, 'POST', '/api/v1/manager/handovers', [
            'collector_id' => $this->collector->id,
            'amount' => '25',
            'date' => now()->toDateString(),
        ], ['Idempotency-Key' => 'moto-wallet-handover-1'])->assertStatus(201);

        $body = $this->wallet($this->collector)->assertStatus(200)->json();

        $this->assertSame('35.00', $body['money']['in_hand']);
        $this->assertSame('25.00', $body['money']['handed_total']);

        // Handing cash is an act between two men: the collector sees that the office signed for it.
        $this->assertCount(1, $body['handovers']);
        $this->assertSame('25.00', $body['handovers'][0]['amount']);
        $this->assertSame(now()->toDateString(), $body['handovers'][0]['date']);
        $this->assertSame($this->admin->name, $body['handovers'][0]['received_by']);
    }

    public function test_the_business_date_decides_the_day_not_the_moment_it_was_typed(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 100, [$this->period]);

        // Written now, but it is yesterday's money: a receipt taken at a door with no signal.
        $this->pay($house, 30, $this->collector, now()->subDay()->toDateString());

        $body = $this->wallet($this->collector)->assertStatus(200)->json();

        $this->assertSame('0.00', $body['money']['today_total']);
        $this->assertSame(0, $body['money']['today_count']);
        $this->assertSame(1, $body['money']['month_count']);
        $this->assertSame('30.00', $body['money']['in_hand']);
    }

    public function test_a_collector_who_has_done_nothing_gets_zeroes_not_an_error(): void
    {
        $body = $this->wallet($this->collector)->assertStatus(200)->json();

        $this->assertSame('0.00', $body['money']['in_hand']);
        $this->assertSame('0.00', $body['money']['today_total']);
        $this->assertSame(0, $body['money']['today_count']);
        $this->assertSame([], $body['handovers']);
        $this->assertSame(0, $body['readings']['today']);
    }

    public function test_a_reading_belongs_to_the_collector_who_entered_it(): void
    {
        $house = $this->meteredHouse('بيت العدّاد', 1000);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'period' => $this->period,
            'current_reading' => '1150',
        ], ['Idempotency-Key' => 'moto-wallet-reading-1'])->assertCreated();

        $mine = $this->wallet($this->collector)->assertStatus(200)->json('readings');

        $this->assertSame(1, $mine['today']);
        $this->assertSame(1, $mine['cycle']);

        // Another collector's reading is not his work, and a purse that counted it would be a lie about his day.
        $other = $this->person('جابٍ آخر', 'other@generator.test', \App\Models\User::ROLE_COLLECTOR);
        $theirs = $this->wallet($other)->assertStatus(200)->json('readings');

        $this->assertSame(0, $theirs['today']);
    }

    public function test_his_receipts_are_the_lines_behind_the_cash_he_carries(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 100, [$this->period]);
        $other = $this->flatHouse('بيت الجار', 100, [$this->period]);

        $this->pay($house, 20, $this->collector, now()->subDay()->toDateString());
        $this->pay($house, 25, $this->collector, now()->toDateString());

        // Somebody else's receipt is not his day's work, and a list that mixed them would make him «short».
        $otherCollector = $this->person('جابٍ آخر', 'other2@generator.test', \App\Models\User::ROLE_COLLECTOR);
        $this->pay($other, 99, $otherCollector, now()->toDateString());

        $receipts = $this->wallet($this->collector)->assertStatus(200)->json('receipts');

        $this->assertCount(2, $receipts);

        // Newest first, by the day the money was taken.
        $this->assertSame('25.00', $receipts[0]['amount']);
        $this->assertSame(now()->toDateString(), $receipts[0]['collected_on']);
        $this->assertSame('بيت أبو علي', $receipts[0]['subscriber']);
        $this->assertNotNull($receipts[0]['number']);
        $this->assertSame('20.00', $receipts[1]['amount']);
    }

    public function test_the_invoices_his_own_readings_produced_are_listed_for_this_cycle(): void
    {
        $mine = $this->meteredHouse('بيت قراءتي', 1000);
        $theirs = $this->meteredHouse('بيت جارٍ', 1000);

        $otherCollector = $this->person('جابٍ آخر', 'other3@generator.test', \App\Models\User::ROLE_COLLECTOR);

        // His reading this cycle, another collector's reading this cycle, and his own reading of the cycle
        // BEFORE — which is not this cycle's work and must not appear.
        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $mine->id, 'period' => $this->period, 'current_reading' => '1150',
        ], ['Idempotency-Key' => 'moto-review-reading-1'])->assertCreated();

        $this->api($otherCollector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $theirs->id, 'period' => $this->period, 'current_reading' => '1080',
        ], ['Idempotency-Key' => 'moto-review-reading-2'])->assertCreated();

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $mine->id, 'period' => '2026-09', 'current_reading' => '1050',
        ], ['Idempotency-Key' => 'moto-review-reading-3'])->assertCreated();

        $invoices = $this->wallet($this->collector)->assertStatus(200)->json('invoices');

        $this->assertCount(1, $invoices);
        $this->assertSame('بيت قراءتي', $invoices[0]['subscriber']);
        $this->assertSame($this->period, $invoices[0]['period']);
        $this->assertSame('150.00', $invoices[0]['consumption']);
        $this->assertNotNull($invoices[0]['number']);
        $this->assertNotNull($invoices[0]['total']);
        $this->assertSame($invoices[0]['total'], $invoices[0]['remaining']);
    }

    public function test_a_manager_may_ask_and_gets_his_own_zeroes(): void
    {
        // No role gate on purpose: refusing would hide a mistake, and the honest answer — nobody collects in
        // his name — is more useful than a 403.
        $body = $this->wallet($this->admin)->assertStatus(200)->json();

        $this->assertSame('0.00', $body['money']['in_hand']);
        $this->assertSame('0.00', $body['money']['today_total']);
        $this->assertSame(0, CashHandover::query()->count());
    }

    public function test_the_purse_needs_a_token(): void
    {
        $this->getJson('/api/v1/me/summary')->assertStatus(401);
    }
}
