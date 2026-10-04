<?php

namespace Tests\Feature\Api;

use App\Models\ApiOperation;
use App\Models\Payment;
use App\Models\Reading;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * A phone on a bad street retries. The key is what keeps one attempt one receipt.
 *
 * The three things pinned here are the office's money: the SAME key never charges twice, a key is
 * bound to the payload it signed (so a different body under the same key is a conflict, not a
 * receipt), and a refusal by the engine does NOT burn the key — the collector fixes the field and
 * sends the same attempt again, which is exactly what an offline queue does.
 */
class ApiIdempotencyTest extends ApiTestCase
{
    public function test_the_same_key_with_the_same_body_charges_once_and_answers_with_the_first_receipt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $body = ['subscriber_id' => $house->id, 'amount' => '60'];

        $first = $this->api($this->collector, 'POST', '/api/v1/collections', $body, ['Idempotency-Key' => 'the-same-key-000001'])->assertCreated();
        $again = $this->api($this->collector, 'POST', '/api/v1/collections', $body, ['Idempotency-Key' => 'the-same-key-000001'])->assertCreated();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame($first->json('payment.number'), $again->json('payment.number'));
        $this->assertFalse($first->json('replay'));
        $this->assertTrue($again->json('replay'));
        $this->assertSame('0.00', $again->json('house.balance'));
    }

    public function test_the_same_key_with_a_different_body_is_a_conflict_and_never_a_second_receipt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'conflicting-key-00001'])->assertCreated();

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '45',
        ], ['Idempotency-Key' => 'conflicting-key-00001'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'mobile.idempotency_conflict');

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_key_is_bound_to_one_kind_of_act(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1100',
        ], ['Idempotency-Key' => 'one-shape-only-key1'])->assertCreated();

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '20',
        ], ['Idempotency-Key' => 'one-shape-only-key1'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'mobile.idempotency_conflict');
    }

    public function test_a_money_write_with_no_key_is_refused(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.idempotency_key_missing');

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $this->meteredHouse('أحمد سعد', 1000)->id,
            'period' => $this->period, 'current_reading' => '1100',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.idempotency_key_missing');

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Reading::query()->count());
    }

    public function test_a_malformed_key_is_refused_before_anything_is_written(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        foreach (['abc', str_repeat('x', 200), 'has a space in it', 'slash/and/dots.'] as $bad) {
            $this->api($this->collector, 'POST', '/api/v1/collections', [
                'subscriber_id' => $house->id, 'amount' => '60',
            ], ['Idempotency-Key' => $bad])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'mobile.idempotency_key_malformed');
        }

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_key_is_a_header_only_contract_and_a_body_copy_is_refused(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        // Header and body can disagree, and the server would have to guess which one signed the
        // attempt. Refusing the body copy is the only answer that cannot be gamed.
        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60', 'idempotency_key' => 'body-copy-key-00001',
        ], ['Idempotency-Key' => 'header-copy-key-0001'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.idempotency_key_in_body');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_business_refusal_does_not_burn_the_key(): void
    {
        $house = $this->flatHouse('بيت بلا فاتورة', 60);
        $key = 'kept-across-refusal-1';

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => $key])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.period_not_invoiced');

        // The refusal took its own row back with it: a key that a refusal burned would make the
        // collector's queued attempt unpayable forever.
        $this->assertSame(0, ApiOperation::query()->count(), 'a refused attempt leaves no row behind');

        // The office raises the month's invoice (a run, or an explicit action) — then the SAME queued
        // attempt goes through, and now the row is the committed one.
        $this->bill($house, $this->period);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, ApiOperation::query()->where('key', $key)->count());
    }

    public function test_the_same_key_from_another_account_is_his_own_attempt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);
        $other = $this->person('سليم', 'salim@x.com', User::ROLE_COLLECTOR);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'shared-looking-key-1'])->assertCreated();

        $this->api($other, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'shared-looking-key-1'])->assertCreated();

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame(
            [$this->collector->id, $other->id],
            Payment::query()->orderBy('id')->pluck('collector_id')->all(),
        );
    }

    public function test_a_different_key_with_the_same_body_is_a_second_receipt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);
        $body = ['subscriber_id' => $house->id, 'amount' => '60'];

        $this->api($this->collector, 'POST', '/api/v1/collections', $body, ['Idempotency-Key' => 'first-real-key-00001'])->assertCreated();
        $this->api($this->collector, 'POST', '/api/v1/collections', $body, ['Idempotency-Key' => 'second-real-key-0001'])->assertCreated();

        // The guard is against a REPLAY, not against a second real payment: two months are owed and
        // both are now settled.
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame('0.00', $this->dec($house->fresh()->outstanding()));
    }

    public function test_the_replay_door_is_a_unique_index_in_the_database_not_a_politeness(): void
    {
        $row = [
            'user_id' => $this->collector->id,
            'action' => 'collection',
            'key' => 'index-door-key-000001',
            'request_hash' => str_repeat('a', 64),
        ];

        ApiOperation::create($row);

        try {
            ApiOperation::create($row);
            $this->fail('A duplicate idempotency key was accepted by the database.');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->errorInfo[0] ?? null, 'the door must be an integrity violation');
        }

        $this->assertSame(1, ApiOperation::query()->count());
    }
}
