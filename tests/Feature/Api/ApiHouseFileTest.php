<?php

namespace Tests\Feature\Api;

use App\Models\Payment;

/**
 * A household's file, as the phone reads it (spec 009, phase D).
 *
 * The manager asked the right question in the field: the place to take a receipt back is the place where the
 * whole story is visible — his invoices and the receipts against them — not a row in a list of the last few
 * payments. So the file carries both logs, and the reversal happens here.
 *
 * Two rules this file exists to pin:
 *  - the LEDGER is asked for one house at a time. The area sheet lists a whole street and must not drag a
 *    ledger per house behind it;
 *  - the collector sees it too. «أنت دفعت الشهر الماضي» is a sentence he needs at the door, and the panel's
 *    own collector page has shown him the receipts all along.
 */
class ApiHouseFileTest extends ApiTestCase
{
    private function file(int $houseId, ?\App\Models\User $as = null, string $period = '2026-10'): \Illuminate\Testing\TestResponse
    {
        return $this->api($as ?? $this->collector, 'GET', "/api/v1/houses/{$houseId}", [], [
            'Accept-Language' => 'ar',
        ]);
    }

    private function houseWithTwoReceipts(): int
    {
        $house = $this->flatHouse('بيت أبو علي', 60, [$this->period]);

        $this->pay($house, 20, $this->collector, now()->toDateString());
        $this->pay($house, 15, $this->collector, now()->subDay()->toDateString());

        return $house->id;
    }

    public function test_the_file_carries_the_receipts_newest_first_with_what_each_settled(): void
    {
        $id = $this->houseWithTwoReceipts();

        $payments = $this->file($id)->assertStatus(200)->json('house.payments');

        $this->assertCount(2, $payments);

        // Newest first, by the DAY the receipt was taken — not the hour it was typed in.
        $this->assertSame('20.00', $payments[0]['amount']);
        $this->assertSame(now()->toDateString(), $payments[0]['collected_on']);
        $this->assertSame('15.00', $payments[1]['amount']);
        $this->assertSame(now()->subDay()->toDateString(), $payments[1]['collected_on']);

        // A receipt says what it cleared: that is what a household disputes, and what a manager reads
        // before taking one back.
        $this->assertSame('20.00', $payments[0]['settled'][0]['amount']);
        $this->assertSame((string) $this->period, $payments[0]['settled'][0]['period']);
        $this->assertNotNull($payments[0]['settled'][0]['label']);
        $this->assertSame($this->collector->name, $payments[0]['collector']);
        $this->assertNotNull($payments[0]['method_label']);
    }

    public function test_the_invoices_are_in_the_file_too(): void
    {
        $id = $this->houseWithTwoReceipts();

        $bills = $this->file($id)->assertStatus(200)->json('house.bills');

        $this->assertCount(1, $bills);
        $this->assertSame('60.00', $bills[0]['total']);
        $this->assertSame('35.00', $bills[0]['paid']);
        $this->assertSame('25.00', $bills[0]['remaining']);
    }

    public function test_a_household_that_never_paid_carries_an_empty_log_not_a_missing_one(): void
    {
        $house = $this->flatHouse('بيت بلا دفعات', 60, [$this->period]);

        $body = $this->file($house->id)->assertStatus(200)->json('house');

        // The key is present and empty: a phone screen must be able to say «لا دفعات بعد» without
        // wondering whether the server forgot to send the log.
        $this->assertArrayHasKey('payments', $body);
        $this->assertSame([], $body['payments']);
    }

    public function test_the_area_sheet_carries_the_card_alone(): void
    {
        $this->houseWithTwoReceipts();

        $sheet = $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses', [], [
            'Accept-Language' => 'ar',
        ])->assertStatus(200)->json('houses.0');

        $this->assertArrayNotHasKey('payments', $sheet);
        $this->assertArrayNotHasKey('bills', $sheet);
    }

    public function test_the_collector_reads_his_households_file(): void
    {
        $id = $this->houseWithTwoReceipts();

        $this->file($id, $this->collector)->assertStatus(200)->assertJsonCount(2, 'house.payments');
    }

    public function test_a_reversed_receipt_leaves_the_file(): void
    {
        $id = $this->houseWithTwoReceipts();

        $receipt = Payment::query()->orderByDesc('id')->firstOrFail();

        $this->api($this->admin, 'POST', '/api/v1/manager/reversals', [
            'payment_id' => $receipt->id,
            'reason' => 'قبض مكتوب على البيت الخطأ',
        ], ['Idempotency-Key' => 'moto-house-file-reversal-1'])->assertStatus(201);

        // Reversing deletes the row, so the log no longer shows it — the same as the panel's. What happened
        // to it, and why, is in the audit trail, not in a receipt that no longer exists.
        $this->file($id)->assertStatus(200)->assertJsonCount(1, 'house.payments');
    }
}
