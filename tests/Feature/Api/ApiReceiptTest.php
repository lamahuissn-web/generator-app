<?php

namespace Tests\Feature\Api;

use App\Models\User;

/**
 * The paper the household is handed. The phone never draws its own receipt: it asks the server for
 * the one the office's own printer would produce, so a receipt held in a street and a receipt held at
 * the counter are the same document.
 */
class ApiReceiptTest extends ApiTestCase
{
    public function test_the_collector_gets_his_receipt_as_a_pdf(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $posted = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'receipt-key-000001'])->assertCreated();

        $response = $this->api($this->collector, 'GET', $posted->json('receipt_url'))->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString(
            'receipt-'.$posted->json('payment.id'),
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_a_receipt_taken_by_another_collector_is_not_his_to_print(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $other = $this->person('سليم', 'salim@x.com', User::ROLE_COLLECTOR);

        $mine = $this->api($other, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'receipt-key-000002'])->assertCreated();

        $this->api($this->collector, 'GET', $mine->json('receipt_url'))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_the_office_can_print_any_receipt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $posted = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'receipt-key-000003'])->assertCreated();

        $this->api($this->admin, 'GET', $posted->json('receipt_url'))->assertOk();
    }

    public function test_a_receipt_that_does_not_exist_is_a_json_404(): void
    {
        $this->api($this->collector, 'GET', '/api/v1/receipts/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_the_receipt_follows_the_server_number_the_office_prints_with(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $posted = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'receipt-key-000004'])->assertCreated();

        $number = $posted->json('payment.number');
        $this->assertMatchesRegularExpression('/^REC-\d{4}-\d{2}-\d{4}$/', $number);

        // The stored row carries it too: a receipt the app shows is never numbered by the phone.
        $this->assertSame($number, \App\Models\Payment::query()->firstOrFail()->number);
    }
}
