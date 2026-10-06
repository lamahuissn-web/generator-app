<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;

/**
 * Taking one receipt back, from the manager's phone (spec 009, phase C).
 *
 * This is the most dangerous thing this app does: it removes money from the books and leaves a household
 * owing again. So the tests are about the three guards the owner asked for — a WRITTEN reason, a
 * confirmation the phone draws twice, and a trail naming who did it — plus the engine's own refusal when
 * the money a receipt credited has already been spent on a discounted month.
 *
 * The desk has done this for years through BillingService::reversePayment; nothing about the rule is
 * re-decided here, and the receipt that goes and the audit entry that stays are the same ones.
 */
class ApiManagerReversalTest extends ApiTestCase
{
    private const KEY = 'moto-reversal-test-0000001';

    /** @param array<string,mixed> $body */
    private function reverse(User $as, array $body, string $key = self::KEY)
    {
        return $this->api($as, 'POST', '/api/v1/manager/reversals', $body, [
            'Idempotency-Key' => $key,
            'Accept-Language' => 'ar',
        ]);
    }

    /** A household with a bill of 60, of which 60 was collected — and the receipt that did it. */
    private function collected(): array
    {
        $house = $this->flatHouse('بيت أبو علي', 60, [$this->period]);
        $payment = $this->pay($house, 60, $this->collector, now()->toDateString());

        return [$house, $payment];
    }

    public function test_a_manager_reverses_a_receipt_with_a_written_reason(): void
    {
        [$house, $payment] = $this->collected();

        $response = $this->reverse($this->admin, [
            'payment_id' => $payment->id,
            'reason' => 'الجباة قبضوا من البيت الخطأ',
        ])->assertStatus(201);

        $this->assertSame($payment->number, $response->json('reversed.number'));
        $this->assertSame('60.00', $response->json('reversed.amount'));
        $this->assertSame($this->collector->name, $response->json('reversed.collector'));

        // The household owes again: that is what taking the receipt back MEANS, and the phone says it.
        $this->assertSame('60.00', $response->json('subscriber_balance'));

        // The row is gone from the ledger, and the trail that stays names the person and the reason.
        $this->assertSame(0, Payment::query()->where('id', $payment->id)->count());

        $trail = AuditLog::query()->where('action', 'payment.reversed')->sole();
        $this->assertSame($this->admin->id, (int) $trail->actor_id);
        $this->assertSame('الجباة قبضوا من البيت الخطأ', $trail->payload['reason']);
    }

    public function test_the_reason_is_required(): void
    {
        [, $payment] = $this->collected();

        $this->reverse($this->admin, ['payment_id' => $payment->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_reason_that_says_nothing_is_refused(): void
    {
        [, $payment] = $this->collected();

        $this->reverse($this->admin, ['payment_id' => $payment->id, 'reason' => 'خطأ'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_a_collector_cannot_reverse_a_receipt(): void
    {
        [, $payment] = $this->collected();

        // A collector who could take his own receipt back could keep the cash and clean the books.
        $this->reverse($this->collector, [
            'payment_id' => $payment->id,
            'reason' => 'أخذته بالغلط وأريد إرجاعه',
        ])->assertStatus(403)->assertJsonPath('error.code', 'forbidden');

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_an_unknown_receipt_is_refused(): void
    {
        $this->reverse($this->admin, ['payment_id' => 999_999, 'reason' => 'إيصال لا أعرفه'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.unknown_payment');
    }

    public function test_the_same_key_twice_reverses_once(): void
    {
        [, $payment] = $this->collected();

        $body = ['payment_id' => $payment->id, 'reason' => 'مبلغ مكتوب بالغلط'];

        $first = $this->reverse($this->admin, $body)->assertStatus(201);
        $second = $this->reverse($this->admin, $body)->assertStatus(201);

        $this->assertFalse((bool) $first->json('replay'));
        $this->assertTrue((bool) $second->json('replay'));

        $this->assertSame(1, AuditLog::query()->where('action', 'payment.reversed')->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_second_reversal_of_the_same_receipt_with_a_new_key_is_refused(): void
    {
        [, $payment] = $this->collected();

        $this->reverse($this->admin, ['payment_id' => $payment->id, 'reason' => 'عكس أول — إيصال مكتوب على البيت الخطأ'], 'moto-reversal-first-00001')
            ->assertStatus(201);

        // The receipt is gone, so the second attempt has nothing to take back — refused by name rather
        // than quietly reporting a success for a receipt that no longer exists.
        $this->reverse($this->admin, ['payment_id' => $payment->id, 'reason' => 'عكس ثانٍ لنفس الإيصال'], 'moto-reversal-second-0001')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.unknown_payment');
    }

    public function test_a_reversal_is_not_recorded_without_a_key(): void
    {
        [, $payment] = $this->collected();

        $this->api($this->admin, 'POST', '/api/v1/manager/reversals', [
            'payment_id' => $payment->id,
            'reason' => 'بلا مفتاح محاولة',
        ])->assertStatus(422)->assertJsonPath('error.code', 'mobile.idempotency_key_missing');

        $this->assertSame(1, Payment::query()->count());
    }
}
