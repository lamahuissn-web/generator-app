<?php

namespace Tests\Feature\Api;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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

    public function test_a_phone_that_asks_for_arabic_gets_arabic_months_and_sentences(): void
    {
        // Found on the live app, not in a test: the office opens the collector's sheet and reads
        // «Oct 2026» over a house. The month label has two renderers and the panel already chooses
        // between them by locale — the phone must get the same choice.
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $unbilled = $this->flatHouse('بيت بلا فاتورة', 60);
        $arabic = ['Accept-Language' => 'ar'];

        $cards = $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses', [], $arabic)
            ->assertOk()
            ->json('houses');

        $mine = collect($cards)->firstWhere('id', $house->id);
        $this->assertNotNull($mine, 'the house is on the sheet');
        $this->assertSame(period_label_ar('2026-09'), $mine['unpaid_months'][0]['label']);

        $message = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $unbilled->id, 'amount' => '60',
        ], $arabic + ['Idempotency-Key' => 'arabic-refusal-key1'])
            ->assertStatus(422)
            ->json('error.message');

        $this->assertSame(__('refuse.period_not_invoiced', ['period' => $this->period], 'ar'), $message);
    }

    public function test_a_business_refusal_is_an_incident_only_when_it_is_a_bug(): void
    {
        // A refusal is the office's own sentence about a door that stayed shut — an answer, not a
        // crash. Logging it as ERROR fills the log the office reads when something is really wrong,
        // and a phone retrying in a dead spot would do it all afternoon.
        Log::spy();

        $unbilled = $this->flatHouse('بيت بلا فاتورة', 60);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $unbilled->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'quiet-refusal-key-01'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.period_not_invoiced');

        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('critical');
    }
}
