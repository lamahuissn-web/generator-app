<?php

namespace Tests\Feature\Api;

use App\Models\Payment;

/**
 * The receipt, as words the collector can hand on.
 *
 * The paper at the door is not always what a household wants: what it wants is something it can keep
 * in the phone it already has, and the phone it has is WhatsApp. So the collection's answer carries a
 * `receipt_text` — built from the SAME pieces the printed receipt is built from (`prt.*` labels,
 * `money()`, the cycle's own month name), because a receipt held in a street and a receipt held at the
 * counter are the same document; only one of them is paper.
 *
 * It is text, not HTML: it leaves the office as a message. Every assertion below is about what the
 * household will read, not about the shape of a payload.
 */
class ApiReceiptTextTest extends ApiTestCase
{
    /**
     * Every call here is the phone's: Arabic, as the app always sends it. The office's words are the
     * point of this test, so the words must be the office's.
     *
     * @param  array<string,mixed>  $body
     * @return \Illuminate\Testing\TestResponse
     */
    private function collect(array $body, string $key)
    {
        return $this->api($this->collector, 'POST', '/api/v1/collections', $body, [
            'Idempotency-Key' => $key,
            'Accept-Language' => 'ar',
        ]);
    }

    public function test_the_answer_to_a_collection_carries_the_receipt_as_a_message(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09']);

        $response = $this->collect([
            'subscriber_id' => $house->id,
            'amount' => '60',
        ], 'receipt-text-key-0001')->assertCreated();

        $text = $response->json('receipt_text');
        $payment = Payment::query()->firstOrFail();

        $this->assertIsString($text);

        // The office's own words, and this receipt's own number.
        $this->assertStringContainsString(__('prt.receipt_title'), $text);
        $this->assertStringContainsString((string) $payment->number, $text);
        $this->assertStringContainsString(__('prt.receipt_no'), $text);

        // Who, where, and whose hand it was.
        $this->assertStringContainsString('بيت أبو علي', $text);
        $this->assertStringContainsString($this->area->name, $text);
        $this->assertStringContainsString($this->collector->name, $text);

        // What changed hands, and the month it closed — named as the office names months.
        $this->assertStringContainsString(money($payment->amount), $text);
        $this->assertStringContainsString(period_label_ar('2026-09'), $text);
        $this->assertStringContainsString(__('prt.thanks'), $text);

        // A whole month is settled, so the text says so rather than leaving a zero to interpret.
        $this->assertStringContainsString(__('prt.settled'), $text);
        $this->assertStringNotContainsString(__('prt.remaining_balance'), $text);
    }

    public function test_a_household_that_still_owes_reads_what_it_still_owes(): void
    {
        $house = $this->flatHouse('بيت أبو ندى', 60, ['2026-09', '2026-10']);

        $text = $this->collect([
            'subscriber_id' => $house->id,
            'amount' => '60',
        ], 'receipt-text-key-0002')->assertCreated()->json('receipt_text');

        // The month it closed is on the receipt (the allocation), and so is what is still owed — one
        // line or the other, exactly as the paper prints it, never both.
        $this->assertStringContainsString(period_label_ar('2026-09'), $text);
        $this->assertStringContainsString(__('prt.allocation_title'), $text);
        $this->assertStringContainsString(__('prt.remaining_balance'), $text);
        $this->assertStringContainsString(money(60), $text);
        $this->assertStringNotContainsString(__('prt.settled'), $text);
    }

    public function test_a_lira_receipt_says_the_lira_that_was_actually_handed_over(): void
    {
        $house = $this->flatHouse('بيت أبو فادي', 60, ['2026-09']);

        $text = $this->collect([
            'subscriber_id' => $house->id,
            'amount' => '5400000',
            'currency' => 'lbp',
            'rate' => '90000',
            'method' => 'cash_lbp',
        ], 'receipt-text-key-0003')->assertCreated()->json('receipt_text');

        // The household handed over lira; a receipt that mentions only the dollars it settled is a
        // receipt they cannot check against what left their hand.
        $this->assertStringContainsString('5,400,000', $text);
        $this->assertStringContainsString('90,000', $text);
        $this->assertStringContainsString(money(60), $text);
    }

    public function test_the_text_is_a_message_and_not_a_page(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09']);

        $text = $this->collect([
            'subscriber_id' => $house->id,
            'amount' => '60',
            'note' => 'دفع عند الباب',
        ], 'receipt-text-key-0004')->assertCreated()->json('receipt_text');

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('&nbsp;', $text);
        $this->assertStringContainsString('دفع عند الباب', $text);      // his own note travels too
    }

    public function test_a_replayed_collection_carries_the_same_receipt_words(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09']);

        $body = ['subscriber_id' => $house->id, 'amount' => '60'];

        $first = $this->collect($body, 'receipt-text-key-0005')->assertCreated();
        $again = $this->collect($body, 'receipt-text-key-0005')->assertCreated();

        $this->assertTrue($again->json('replay'));
        $this->assertSame($first->json('receipt_text'), $again->json('receipt_text'));
        $this->assertSame(1, Payment::query()->count());
    }
}
