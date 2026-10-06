<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CashHandover;
use App\Models\User;
use App\Services\CollectionService;

/**
 * Cash a collector hands to the office, recorded from the manager's phone (spec 009, phase B).
 *
 * This is the first WRITE the manager's phone makes, and it moves the figure the office reconciles its
 * drawer against — so the tests are about three things and nothing else: the ceiling (a collector cannot
 * hand over cash he is not holding), the key (a repeated attempt records one handover, not two), and who
 * may do it at all (not the collector himself: the office signs for the cash it received).
 */
class ApiManagerHandoverTest extends ApiTestCase
{
    private const KEY = 'moto-handover-test-000001';

    /** @param array<string,mixed> $body */
    private function handover(User $as, array $body, string $key = self::KEY)
    {
        return $this->api($as, 'POST', '/api/v1/manager/handovers', $body, [
            'Idempotency-Key' => $key,
            'Accept-Language' => 'ar',
        ]);
    }

    private function houseOwning(float $collected): void
    {
        $house = $this->flatHouse('بيت أبو علي', $collected, [$this->period]);
        $this->pay($house, $collected, $this->collector, now()->toDateString());
    }

    public function test_a_manager_records_what_a_collector_handed_over(): void
    {
        $this->houseOwning(60);

        $response = $this->handover($this->admin, [
            'collector_id' => $this->collector->id,
            'amount' => 25,
            'note' => 'نقد الأسبوع',
        ])->assertStatus(201);

        $this->assertSame('25.00', $response->json('handover.amount'));
        $this->assertSame($this->collector->name, $response->json('handover.collector'));
        $this->assertSame(now()->toDateString(), $response->json('handover.handover_date'));
        $this->assertSame('نقد الأسبوع', $response->json('handover.note'));

        // What the office is still waiting for from this collector — the drawer figure, after.
        $this->assertSame('35.00', $response->json('collector_in_hand'));

        $row = CashHandover::query()->sole();
        $this->assertSame($this->collector->id, (int) $row->collector_id);
        $this->assertSame($this->admin->id, (int) $row->recorded_by);

        // The same trail the desk writes, so the office reads one story whichever surface recorded it.
        $this->assertSame(1, AuditLog::query()->where('action', 'handover.created')->count());
        $this->assertSame(35.0, CollectionService::inHand($this->collector->id));
    }

    public function test_the_same_key_twice_records_one_handover(): void
    {
        $this->houseOwning(60);

        $body = ['collector_id' => $this->collector->id, 'amount' => 20];

        $first = $this->handover($this->admin, $body)->assertStatus(201);
        $second = $this->handover($this->admin, $body)->assertStatus(201);

        $this->assertFalse((bool) $first->json('replay'));
        $this->assertTrue((bool) $second->json('replay'));

        $this->assertSame(1, CashHandover::query()->count());
        $this->assertSame(40.0, CollectionService::inHand($this->collector->id));
    }

    public function test_the_same_key_with_a_different_amount_is_a_conflict(): void
    {
        $this->houseOwning(60);

        $this->handover($this->admin, ['collector_id' => $this->collector->id, 'amount' => 20])->assertStatus(201);

        $this->handover($this->admin, ['collector_id' => $this->collector->id, 'amount' => 30])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'mobile.idempotency_conflict');

        $this->assertSame(1, CashHandover::query()->count());
    }

    public function test_handing_over_more_than_the_collector_holds_is_refused(): void
    {
        $this->houseOwning(20);

        $response = $this->handover($this->admin, ['collector_id' => $this->collector->id, 'amount' => 50])
            ->assertStatus(422);

        // The engine's own sentence, with the figure he actually holds: the office's rule, not a copy.
        $this->assertStringContainsString('20.00', (string) $response->json('error.message'));
        $this->assertSame(0, CashHandover::query()->count());
    }

    public function test_a_collector_cannot_record_a_handover(): void
    {
        $this->handover($this->collector, ['collector_id' => $this->collector->id, 'amount' => 5])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_a_handover_cannot_be_dated_in_the_future(): void
    {
        $this->houseOwning(60);

        $this->handover($this->admin, [
            'collector_id' => $this->collector->id,
            'amount' => 10,
            'handover_date' => now()->addDay()->toDateString(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'mobile.future_handover_date');

        $this->assertSame(0, CashHandover::query()->count());
    }

    public function test_an_unknown_collector_is_refused(): void
    {
        $this->handover($this->admin, ['collector_id' => 999_999, 'amount' => 5])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.unknown_collector');
    }

    public function test_a_handover_is_not_recorded_without_a_key(): void
    {
        $this->houseOwning(60);

        $this->api($this->admin, 'POST', '/api/v1/manager/handovers', [
            'collector_id' => $this->collector->id,
            'amount' => 10,
        ])->assertStatus(422)->assertJsonPath('error.code', 'mobile.idempotency_key_missing');

        $this->assertSame(0, CashHandover::query()->count());
    }
}
