<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\User;

/**
 * The channel itself: one error shape for every failure, money as strings on the wire, and no
 * session-shaped behaviour in an app that has no browser.
 */
class ApiContractTest extends ApiTestCase
{
    public function test_every_failure_has_the_same_one_object_shape(): void
    {
        $noToken = $this->getJson('/api/v1/me');
        $missing = $this->api($this->collector, 'GET', '/api/v1/houses/999999');
        $invalid = $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/v1/login', ['identity' => '']);

        foreach ([$noToken, $missing, $invalid] as $response) {
            $response->assertJsonStructure(['error' => ['code', 'message']]);
            $this->assertIsString($response->json('error.code'));
            $this->assertNotSame('', $response->json('error.message'));
            $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        }

        $this->assertSame(401, $noToken->getStatusCode());
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame(422, $invalid->getStatusCode());
    }

    public function test_a_closed_door_answers_403_json_and_never_redirects_the_app(): void
    {
        $this->collector->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $response = $this->api($this->collector, 'GET', '/api/v1/areas');

        $response->assertStatus(403)->assertJsonPath('error.code', 'account_disabled');
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_the_money_on_the_wire_is_a_decimal_string_in_every_endpoint(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $this->pay($house, 25);

        $owed = $this->api($this->collector, 'GET', '/api/v1/areas')->json('areas.0.owed');
        $balance = $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses')->json('houses.0.balance');
        $total = $this->api($this->collector, 'GET', '/api/v1/houses/'.$house->id)->json('house.bills.0.total');

        foreach ([$owed, $balance, $total] as $value) {
            $this->assertIsString($value);
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $value);
        }

        $this->assertSame('35.00', $owed);
        $this->assertSame('35.00', $balance);
        $this->assertSame('60.00', $total);
    }

    public function test_a_collection_cannot_carry_who_collected_it_or_what_it_applied(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id,
            'amount' => '60',
            'collector_id' => $this->admin->id,
            'applied' => '999',
            'balance' => '0',
        ], ['Idempotency-Key' => 'key-server-owned-1'])->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        $this->assertSame($this->collector->id, $payment->collector_id);
        $this->assertSame(60.0, (float) $payment->applied);
    }
}
