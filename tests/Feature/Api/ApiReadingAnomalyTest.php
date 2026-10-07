<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Reading;

/**
 * The suspicious-reading gate (spec 013).
 *
 * The owner's design, which is simpler AND better than holding the invoice for the office: the collector is
 * warned at the door — the one place where a mistyped number is still free to fix — confirms that the number
 * is right, and the invoice is issued at once. No queue, no office ceremony.
 *
 * What is tested here is that the warning is a GATE and not a message: the engine refuses to bill a jump or a
 * zero until somebody has looked, so no screen and no old build of the app can slip one through.
 */
class ApiReadingAnomalyTest extends ApiTestCase
{
    /**
     * A household whose habit is ~100 kWh a month, read three months running.
     *
     * The average is the house's own — `ReadingQuality` samples its last real readings — so these three
     * months are what makes a fourth month of 400 a jump rather than business as usual.
     */
    private function householdWithHabit(): \App\Models\Subscriber
    {
        $house = $this->meteredHouse('بيت العدّاد', 1000);

        foreach ([['2026-07', '1100'], ['2026-08', '1200'], ['2026-09', '1300']] as $i => [$period, $value]) {
            $this->api($this->collector, 'POST', '/api/v1/readings', [
                'subscriber_id' => $house->id,
                'period' => $period,
                'current_reading' => $value,
            ], ['Idempotency-Key' => 'moto-anomaly-history-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])
                ->assertCreated();
        }

        return $house;
    }

    private function read(\App\Models\Subscriber $house, string $value, bool $confirmed, string $key)
    {
        return $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'period' => '2026-10',
            'current_reading' => $value,
            'confirmed_anomaly' => $confirmed,
        ], [
            'Idempotency-Key' => $key,
            'Accept-Language' => 'ar',
        ]);
    }

    public function test_a_jump_is_a_question_before_it_is_an_invoice(): void
    {
        $house = $this->householdWithHabit();

        // 1700 against a previous 1300 is 400 kWh, four times this house's own habit of ~100.
        $refusal = $this->read($house, '1700', false, 'moto-anomaly-jump-0001')->assertStatus(422);

        $this->assertSame('refuse.anomaly_jump', $refusal->json('error.code'));

        // The sentence names the household's OWN habit: that is the fact that tells a man at the door whether
        // he mistyped or the house really changed.
        $this->assertStringContainsString('400', $refusal->json('error.message'));
        $this->assertStringContainsString('100', $refusal->json('error.message'));

        // Nothing was written: no reading for the month, and no invoice.
        $this->assertSame(0, Reading::query()->where('period', '2026-10')->count());
        $this->assertSame(0, Bill::query()->where('period', '2026-10')->count());
    }

    public function test_confirming_it_bills_it_at_once(): void
    {
        $house = $this->householdWithHabit();

        $this->read($house, '1700', true, 'moto-anomaly-jump-0002')->assertStatus(201);

        $reading = Reading::query()->where('period', '2026-10')->sole();

        // The collector's word, kept on the row so the office's board can point a supervisor at it.
        $this->assertTrue($reading->anomaly_confirmed);
        $this->assertSame(400.0, (float) $reading->consumption);
        $this->assertSame(1, Bill::query()->where('period', '2026-10')->count());

        $trail = AuditLog::query()->where('action', 'reading.anomaly_confirmed')->sole();
        $this->assertSame($this->collector->id, (int) $trail->payload['confirmed_by']);
        $this->assertSame('jump', $trail->payload['verdict']);
        $this->assertSame(100.0, (float) $trail->payload['average']);
    }

    public function test_a_zero_is_a_question_too(): void
    {
        $house = $this->householdWithHabit();

        // The same meter reading as last month: the wheel has not turned.
        $refusal = $this->read($house, '1300', false, 'moto-anomaly-zero-0001')->assertStatus(422);

        $this->assertSame('refuse.anomaly_zero', $refusal->json('error.code'));
        $this->assertSame(0, Reading::query()->where('period', '2026-10')->count());

        // A dead meter is a real answer, not an error: confirmed, it bills and is kept as confirmed.
        $this->read($house, '1300', true, 'moto-anomaly-zero-0002')->assertStatus(201);

        $this->assertTrue(Reading::query()->where('period', '2026-10')->sole()->anomaly_confirmed);
    }

    public function test_an_ordinary_reading_passes_without_ceremony(): void
    {
        $house = $this->householdWithHabit();

        // 120 kWh against a habit of ~100: nothing to ask about, and nothing to confirm.
        $this->read($house, '1420', false, 'moto-anomaly-normal-0001')->assertStatus(201);

        $reading = Reading::query()->where('period', '2026-10')->sole();

        $this->assertFalse($reading->anomaly_confirmed);
        $this->assertSame(0, AuditLog::query()->where('action', 'reading.anomaly_confirmed')->count());
    }

    public function test_a_household_with_no_history_is_not_questioned(): void
    {
        // No habit to compare against: the engine warns about nothing rather than inventing an average.
        $house = $this->meteredHouse('بيت جديد', 1000);

        $this->read($house, '1400', false, 'moto-anomaly-newhouse-0001')->assertStatus(201);
    }

    public function test_the_file_says_a_reading_was_confirmed(): void
    {
        $house = $this->householdWithHabit();
        $this->read($house, '1700', true, 'moto-anomaly-file-0001')->assertStatus(201);

        $rows = $this->api($this->collector, 'GET', "/api/v1/houses/{$house->id}", [], ['Accept-Language' => 'ar'])
            ->assertStatus(200)
            ->json('house.readings');

        $this->assertTrue($rows[0]['anomaly_confirmed']);
    }
}
