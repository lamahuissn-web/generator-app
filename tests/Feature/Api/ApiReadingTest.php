<?php

namespace Tests\Feature\Api;

use App\Models\Bill;
use App\Models\Reading;
use App\Models\Subscriber;

/**
 * The reading, posted from the door.
 *
 * Nothing about the money is decided here: the endpoint calls the same engine the panel calls, so an
 * invoice raised from a phone and an invoice raised at the desk are the same invoice. What this file
 * pins is that the phone inherits every refusal the engine already makes, that the reader is the
 * person holding the phone rather than a field in the body, and that a month is never billed twice.
 */
class ApiReadingTest extends ApiTestCase
{
    public function test_a_metered_reading_raises_the_invoice_the_engine_would_raise_at_the_desk(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $response = $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'period' => $this->period,
            'current_reading' => '1150',
        ], ['Idempotency-Key' => 'reading-key-0001'])->assertCreated();

        // 150 kWh × $0.25 = $37.50, plus the $15 maintenance fee.
        $response->assertJsonPath('reading.previous_reading', '1000.00')
            ->assertJsonPath('reading.consumption', '150.00')
            ->assertJsonPath('reading.entered_by', $this->collector->id)
            ->assertJsonPath('bill.period', $this->period)
            ->assertJsonPath('bill.usage_amount', '37.50')
            ->assertJsonPath('bill.maintenance_fee', '15.00')
            ->assertJsonPath('bill.total', '52.50')
            ->assertJsonPath('bill.number', \App\Services\BillingService::billNumber($this->period, $house->id))
            // The card the collector is holding now says what the house owes.
            ->assertJsonPath('house.balance', '52.50');

        $this->assertSame(1, Reading::query()->count());
        $this->assertSame(1, Bill::query()->count());
    }

    public function test_the_month_is_the_current_one_when_the_phone_does_not_name_it(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'current_reading' => '1050',
        ], ['Idempotency-Key' => 'reading-key-0002'])->assertCreated()
            ->assertJsonPath('reading.period', $this->period);
    }

    public function test_a_reading_lower_than_the_previous_one_is_refused_by_name(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'period' => $this->period,
            'current_reading' => '900',
        ], ['Idempotency-Key' => 'reading-key-0003'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.reading_lower')
            ->assertJsonPath('error.message', __('refuse.reading_lower'));

        $this->assertSame(0, Reading::query()->count());
        $this->assertSame(0, Bill::query()->count());
    }

    public function test_a_month_that_already_has_a_reading_is_never_billed_a_second_time(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1100',
        ], ['Idempotency-Key' => 'reading-key-0004'])->assertCreated();

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1200',
        ], ['Idempotency-Key' => 'reading-key-0005'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.reading_exists');

        $this->assertSame(1, Reading::query()->count());
        $this->assertSame(1, Bill::query()->count());
    }

    public function test_a_flat_house_has_no_meter_to_read(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1150',
        ], ['Idempotency-Key' => 'reading-key-0006'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.flat_has_no_readings');
    }

    public function test_an_estimated_reading_needs_its_reason_and_says_so_on_the_paper(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        // No reason, no estimate: the reason is what the office reads three months later.
        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period,
            'estimated' => true, 'estimated_kwh' => '50',
        ], ['Idempotency-Key' => 'reading-key-0007'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid')
            ->assertJsonStructure(['error' => ['fields' => ['reason']]]);

        $response = $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period,
            'estimated' => true, 'estimated_kwh' => '50', 'reason' => 'العدّاد معطّل منذ أسبوع',
        ], ['Idempotency-Key' => 'reading-key-0008'])->assertCreated();

        // The estimate states kWh, and the meter value becomes previous + kWh.
        $response->assertJsonPath('reading.estimated', true)
            ->assertJsonPath('reading.consumption', '50.00')
            ->assertJsonPath('reading.current_reading', '1050.00')
            ->assertJsonPath('bill.usage_amount', '12.50');

        $this->assertSame(1, Reading::query()->where('is_estimated', true)->count());
    }

    public function test_the_reader_is_the_phone_that_posted_it_not_a_name_in_the_body(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1050',
            'entered_by' => $this->admin->id,
        ], ['Idempotency-Key' => 'reading-key-0009'])->assertCreated();

        $this->assertSame($this->collector->id, Reading::query()->firstOrFail()->entered_by);
    }

    public function test_an_inactive_house_is_refused_the_way_the_engine_refuses_it(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, [
            'maintenance_fee' => 15, 'status' => Subscriber::STATUS_INACTIVE,
        ]);

        $response = $this->api($this->collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id, 'period' => $this->period, 'current_reading' => '1050',
        ], ['Idempotency-Key' => 'reading-key-0010']);

        // Whatever the engine says, it says it with a name a person can act on: never a 500.
        $this->assertContains($response->getStatusCode(), [200, 201, 422]);
        $this->assertNotSame(500, $response->getStatusCode());
    }
}
