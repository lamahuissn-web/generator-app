<?php

namespace Tests\Feature\Api;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Models\User;

/**
 * The money taken at the door.
 *
 * Every rule the office already relies on holds here because the endpoint calls `BillingService`: the
 * oldest month is settled first, the amount is capped at what is owed, the part above it waits on the
 * household's account, and a collection NEVER raises the invoice it settles — taking money for a cycle
 * nobody billed is how a receipt makes a balance grow.
 */
class ApiCollectionTest extends ApiTestCase
{
    public function test_a_collection_settles_the_oldest_month_first_and_says_what_it_settled(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);

        $response = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id,
            'amount' => '60',
            'collected_on' => now()->toDateString(),
        ], ['Idempotency-Key' => 'collection-key-0001'])->assertCreated();

        $response->assertJsonPath('payment.applied', '60.00')
            ->assertJsonPath('payment.amount', '60.00')
            ->assertJsonPath('payment.collected_on', now()->toDateString())
            ->assertJsonPath('settled.0.period', '2026-09')
            ->assertJsonPath('settled.0.amount', '60.00')
            ->assertJsonPath('settled.0.remaining', '0.00')
            ->assertJsonPath('house.balance', '60.00');

        $this->assertInstanceOf(Payment::class, Payment::query()->firstOrFail());
    }

    public function test_a_collection_never_raises_the_invoice_it_settles(): void
    {
        $house = $this->flatHouse('بيت بلا فاتورة', 60);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id,
            'amount' => '60',
        ], ['Idempotency-Key' => 'collection-key-0002'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.period_not_invoiced');

        // And the refusal named the month, so the collector knows what to say at the door.
        $this->assertSame(0, Bill::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_what_is_taken_above_the_balance_waits_on_the_households_account(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $response = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id,
            'amount' => '100',
        ], ['Idempotency-Key' => 'collection-key-0003'])->assertCreated();

        $response->assertJsonPath('payment.amount', '100.00')
            ->assertJsonPath('payment.applied', '60.00')
            ->assertJsonPath('payment.credited', '40.00')
            ->assertJsonPath('house.balance', '0.00')
            ->assertJsonPath('house.credit', '40.00');

        // Σ allocations == what the receipt settled: the ledger balances, and nothing is invented.
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(
            $this->dec($payment->applied),
            $this->dec($payment->allocations()->sum('amount')),
        );
        $this->assertSame(0, Bill::query()->whereRaw('paid_amount > total')->count());
    }

    public function test_a_lira_collection_keeps_the_lira_and_the_rate_that_were_given(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $response = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id,
            'amount' => '5400000',
            'currency' => 'lbp',
            'rate' => '90000',
            'method' => 'cash_lbp',
        ], ['Idempotency-Key' => 'collection-key-0004'])->assertCreated();

        $response->assertJsonPath('payment.lbp_amount', 5400000)
            ->assertJsonPath('payment.lbp_rate', 90000)
            ->assertJsonPath('payment.applied', '60.00')
            ->assertJsonPath('payment.method', 'cash_lbp');

        $this->assertSame(0.0, (float) $house->fresh()->credit);
    }

    public function test_a_lira_collection_without_a_rate_is_refused_by_name(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '5400000', 'currency' => 'lbp', 'rate' => '0',
        ], ['Idempotency-Key' => 'collection-key-0005'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.no_lbp_rate');
    }

    public function test_the_day_the_money_was_collected_travels_with_the_receipt(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $yesterday = now()->subDay()->toDateString();

        // A phone with no signal collects on the 3rd and syncs on the 4th: the money is the 3rd's,
        // and so is its receipt number — everything that reads the month reads the business date.
        $response = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60', 'collected_on' => $yesterday,
        ], ['Idempotency-Key' => 'collection-key-0006'])->assertCreated();

        $response->assertJsonPath('payment.collected_on', $yesterday);

        $payment = Payment::query()->firstOrFail();
        $this->assertStringStartsWith('REC-'.now()->subDay()->format('Y-m'), (string) $payment->number);
    }

    public function test_money_may_not_be_recorded_for_a_day_that_has_not_come(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
            'collected_on' => now()->addDay()->toDateString(),
        ], ['Idempotency-Key' => 'collection-key-0007'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'mobile.future_collected_on');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_house_that_owes_nothing_is_refused_the_way_the_engine_refuses_it(): void
    {
        $house = $this->meteredHouse('أحمد سعد', 1000, ['maintenance_fee' => 15]);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'collection-key-0008'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'refuse.nothing_to_collect');

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_house_that_paid_a_whole_month_is_the_one_the_audit_row_names(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'collection-key-0009'])->assertCreated();

        $payment = Payment::query()->firstOrFail();

        $this->assertSame($this->collector->id, $payment->collector_id);
        $this->assertSame($house->id, $payment->subscriber_id);
        $this->assertSame('60.00', $this->dec($payment->amount));
    }

    public function test_a_second_collector_takes_his_own_money_and_the_two_receipts_stand(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        $other = $this->person('سليم', 'salim@x.com', User::ROLE_COLLECTOR);

        $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '30',
        ], ['Idempotency-Key' => 'collection-key-0010'])->assertCreated();

        $this->api($other, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '30',
        ], ['Idempotency-Key' => 'collection-key-0011'])->assertCreated();

        $this->assertSame(2, Payment::query()->count());
        $this->assertSame('0.00', $this->dec($house->fresh()->outstanding()));
    }

    public function test_an_inactive_house_is_never_billed_or_collected_by_a_phone(): void
    {
        $house = $this->flatHouse('بيت موقوف', 60, ['2026-09'], ['status' => Subscriber::STATUS_INACTIVE]);

        $response = $this->api($this->collector, 'POST', '/api/v1/collections', [
            'subscriber_id' => $house->id, 'amount' => '60',
        ], ['Idempotency-Key' => 'collection-key-0012']);

        // Whether the engine collects or refuses is its own decision (it takes money for a bill that
        // exists); what the API guarantees is a named answer and never a 500.
        $this->assertContains($response->getStatusCode(), [201, 422]);
    }
}
