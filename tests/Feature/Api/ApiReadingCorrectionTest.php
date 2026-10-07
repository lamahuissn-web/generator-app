<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Reading;

/**
 * Correcting a reading from a phone (spec 012).
 *
 * A collector who mistypes a number used to have one road: telephone the office. The rule that decides
 * whether it may be undone is NOT re-written here — `BillingService::deleteReadingAndBill` is the engine, and
 * it keeps its own refusals (a paid bill, a closed cycle, a reading with no invoice) and its own audit entry
 * carrying the ORIGINAL value. What the API adds is the two guards a phone needs: whose reading it is, and
 * whether it is still the day.
 */
class ApiReadingCorrectionTest extends ApiTestCase
{
    private const KEY = 'moto-correction-test-0001';

    private function correct(\App\Models\User $as, Reading $reading, string $reason, string $key = self::KEY)
    {
        return $this->api($as, 'POST', "/api/v1/readings/{$reading->id}/correction", ['reason' => $reason], [
            'Idempotency-Key' => $key,
            'Accept-Language' => 'ar',
        ]);
    }

    /** A metered household read by $collector for $period. */
    private function read(\App\Models\User $collector, float $value = 1150, string $period = '2026-10'): Reading
    {
        $house = $this->meteredHouse('بيت العدّاد', 1000);

        $this->api($collector, 'POST', '/api/v1/readings', [
            'subscriber_id' => $house->id,
            'period' => $period,
            'current_reading' => (string) $value,
        ], ['Idempotency-Key' => 'moto-correction-reading-'.uniqid()])->assertCreated();

        return Reading::query()->latest('id')->firstOrFail();
    }

    public function test_a_collector_corrects_the_reading_he_took_today(): void
    {
        $reading = $this->read($this->collector);
        $bill = Bill::query()->where('reading_id', $reading->id)->sole();

        $body = $this->correct($this->collector, $reading, 'قرأت عدّاد البيت المجاور')->assertStatus(201)->json();

        // What went, named: the month, the pair, and the invoice it had produced.
        $this->assertSame('2026-10', $body['corrected']['period']);
        $this->assertSame('1150.00', $body['corrected']['current']);
        $this->assertSame($bill->number, $body['corrected']['bill_number']);
        $this->assertSame($reading->subscriber_id, (int) $body['house']['id']);

        // The reading and its invoice are gone, and the household owes again.
        $this->assertSame(0, Reading::query()->where('id', $reading->id)->count());
        $this->assertSame(0, Bill::query()->where('id', $bill->id)->count());

        // The original is not lost: it is in the trail, with the reason and the man who did it.
        $trail = AuditLog::query()->where('action', 'reading.deleted')->sole();
        $this->assertSame($this->collector->id, (int) $trail->actor_id);
        $this->assertSame(1150.0, (float) $trail->payload['current_reading']);
        $this->assertSame('قرأت عدّاد البيت المجاور', $trail->payload['reason']);
    }

    public function test_a_collectors_window_is_today(): void
    {
        $reading = $this->read($this->collector);
        $reading->forceFill(['created_at' => now()->subDay()])->save();

        $this->correct($this->collector, $reading, 'قراءة أمس وأريد تصحيحها')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.correction_window_closed');

        $this->assertSame(1, Reading::query()->count());
    }

    public function test_a_collector_cannot_correct_a_colleagues_reading(): void
    {
        $other = $this->person('جابٍ آخر', 'correction-other@generator.test', \App\Models\User::ROLE_COLLECTOR);
        $reading = $this->read($other);

        $this->correct($this->collector, $reading, 'قراءة ليست لي وأريد تصحيحها')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.not_your_reading');

        $this->assertSame(1, Reading::query()->count());
    }

    public function test_the_office_corrects_any_reading_from_its_phone(): void
    {
        $other = $this->person('جابٍ آخر', 'correction-other2@generator.test', \App\Models\User::ROLE_COLLECTOR);
        $reading = $this->read($other);
        $reading->forceFill(['created_at' => now()->subWeek()])->save();

        // The manager's decision: any reading, any day — with the reason the engine demands for a closed
        // cycle, which the phone always sends.
        $this->correct($this->admin, $reading, 'تصحيح إداري لقراءة قديمة', 'moto-correction-admin-0001')
            ->assertStatus(201);

        $this->assertSame(0, Reading::query()->count());
    }

    public function test_the_engine_refuses_when_the_invoice_was_paid(): void
    {
        $reading = $this->read($this->collector);
        $house = $reading->subscriber;

        $this->pay($house, 20, $this->collector, now()->toDateString());

        // Not a new rule: the engine refuses by name and names the amount that was collected against it.
        $response = $this->correct($this->collector, $reading, 'أريد تصحيح القراءة المدفوعة')
            ->assertStatus(422);

        $this->assertSame('refuse.bill_paid_delete_reading', $response->json('error.code'));
        $this->assertStringContainsString('20.00', $response->json('error.message'));

        $this->assertSame(1, Reading::query()->count());
        $this->assertSame(1, Bill::query()->count());
    }

    public function test_a_reading_with_no_invoice_is_refused_by_the_engine(): void
    {
        // A meter's baseline bills nothing: there is nothing to remove, and the engine says so in its words.
        $reading = $this->read($this->collector);
        Bill::query()->where('reading_id', $reading->id)->delete();

        $this->correct($this->collector, $reading, 'قراءة بلا فاتورة أريد تصحيحها')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.no_bill_for_reading');
    }

    public function test_the_same_key_corrects_once(): void
    {
        $reading = $this->read($this->collector);

        $body = ['reason' => 'مفتاح واحد وتصحيح واحد'];

        $first = $this->api($this->collector, 'POST', "/api/v1/readings/{$reading->id}/correction", $body, [
            'Idempotency-Key' => 'moto-correction-replay-1',
        ])->assertStatus(201);

        $second = $this->api($this->collector, 'POST', "/api/v1/readings/{$reading->id}/correction", $body, [
            'Idempotency-Key' => 'moto-correction-replay-1',
        ])->assertStatus(201);

        $this->assertFalse((bool) $first->json('replay'));
        $this->assertTrue((bool) $second->json('replay'));
        $this->assertSame(0, Reading::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'reading.deleted')->count());
    }

    public function test_a_second_correction_of_the_same_reading_is_refused_by_name(): void
    {
        $reading = $this->read($this->collector);

        $this->correct($this->collector, $reading, 'تصحيح أول لقراءة خاطئة', 'moto-correction-first-0001')
            ->assertStatus(201);

        // The reading is gone, so a new attempt has nothing to take back — refused by name rather than
        // reporting a success about a row that no longer exists.
        $this->correct($this->collector, $reading, 'تصحيح ثانٍ لنفس القراءة', 'moto-correction-second-0001')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.unknown_reading');
    }

    public function test_the_reason_is_required_and_long_enough(): void
    {
        $reading = $this->read($this->collector);

        $this->api($this->collector, 'POST', "/api/v1/readings/{$reading->id}/correction", [], [
            'Idempotency-Key' => 'moto-correction-noreason-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');

        $this->api($this->collector, 'POST', "/api/v1/readings/{$reading->id}/correction", ['reason' => 'خطأ'], [
            'Idempotency-Key' => 'moto-correction-short-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');

        $this->assertSame(1, Reading::query()->count());
    }

    public function test_the_correction_needs_an_attempt_key(): void
    {
        $reading = $this->read($this->collector);

        // A fresh phone request: the harness keeps the previous call's headers, and a key left over from
        // another attempt is exactly the state a real phone is never in.
        $this->flushHeaders();

        $this->api($this->collector, 'POST', "/api/v1/readings/{$reading->id}/correction", [
            'reason' => 'بلا مفتاح محاولة',
        ])->assertStatus(422)->assertJsonPath('error.code', 'mobile.idempotency_key_missing');

        $this->assertSame(1, Reading::query()->count());
    }

    public function test_the_file_says_which_readings_may_be_corrected(): void
    {
        $reading = $this->read($this->collector);
        $mine = $reading->subscriber_id;

        // His own, today: the door is drawn.
        $rows = $this->api($this->collector, 'GET', "/api/v1/houses/{$mine}", [], ['Accept-Language' => 'ar'])
            ->assertStatus(200)->json('house.readings');

        $this->assertTrue($rows[0]['correctable']);
        $this->assertSame($this->collector->id, $rows[0]['entered_by']);

        // Yesterday's reading of his: the door is not drawn — the office's, not his.
        $reading->forceFill(['created_at' => now()->subDay()])->save();

        $rows = $this->api($this->collector, 'GET', "/api/v1/houses/{$mine}", [], ['Accept-Language' => 'ar'])
            ->assertStatus(200)->json('house.readings');

        $this->assertFalse($rows[0]['correctable']);

        // The office may correct anything, so for it the door is always drawn.
        $rows = $this->api($this->admin, 'GET', "/api/v1/houses/{$mine}", [], ['Accept-Language' => 'ar'])
            ->assertStatus(200)->json('house.readings');

        $this->assertTrue($rows[0]['correctable']);
    }
}
