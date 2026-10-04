<?php

namespace Tests\Feature\Api;

use App\Models\Bill;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Services\BillingService;

/**
 * The sheet the collector walks with: the areas, the houses, what each house owes, and which months
 * the money belongs to.
 *
 * The rules this file pins come from the office, not from the payload's convenience: the months are
 * numbers the household can be told, a flat house whose cycle was never billed is NOT a debt, an
 * exempt house is nobody's chase, and money crosses the wire as a decimal string — a float is how an
 * invoice ends up a cent short of itself.
 */
class ApiRouteSheetTest extends ApiTestCase
{
    public function test_the_area_list_carries_the_counts_and_what_is_owed(): void
    {
        $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);
        $this->meteredHouse('أحمد سعد');

        $response = $this->api($this->collector, 'GET', '/api/v1/areas')->assertOk();

        $response->assertJsonPath('period', $this->period)
            ->assertJsonPath('areas.0.name', 'حي البرج')
            ->assertJsonPath('areas.0.houses', 2)
            ->assertJsonPath('areas.0.active', 2)
            ->assertJsonPath('areas.0.owed', '120.00')
            // One metered house to read this month, and it has not been read.
            ->assertJsonPath('areas.0.reading_total', 1)
            ->assertJsonPath('areas.0.reading_done', 0);
    }

    public function test_a_house_card_carries_the_balance_and_the_months_it_is_made_of(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);

        $card = $this->houseCard($house->id);

        $this->assertSame('120.00', $card['balance']);
        $this->assertSame(2, $card['unpaid_months_count']);
        $this->assertSame(['2026-09', '2026-10'], array_column($card['unpaid_months'], 'period'));
        $this->assertSame(period_label('2026-09'), $card['unpaid_months'][0]['label']);
        $this->assertSame('120.00', $this->sumOf($card['unpaid_months'], 'remaining'));
        // «المبلغ المتبقي» is every unpaid month, and it is the same figure the card shows.
        $this->assertSame($card['balance'], $this->sumOf($card['unpaid_months'], 'remaining'));
    }

    public function test_the_house_card_carries_no_invoice_number(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $card = $this->houseCard($house->id);

        // The collector walks a street, not a ledger: a number on the card is a paper he does not
        // hold. The months are what he is told, and the invoice number lives in the detail.
        $this->assertArrayNotHasKey('number', $card);
        $this->assertArrayNotHasKey('bills', $card);
    }

    public function test_a_flat_house_whose_cycle_was_never_billed_is_not_a_debt(): void
    {
        $house = $this->flatHouse('بيت بلا فاتورة', 60);

        $card = $this->houseCard($house->id);

        $this->assertSame('0.00', $card['balance']);
        $this->assertSame([], $card['unpaid_months']);
        $this->assertFalse($card['period_invoiced']);
    }

    public function test_an_exempt_house_is_marked_and_no_month_follows_it(): void
    {
        $house = $this->flatHouse('مسجد البلدة', 60, [], [
            'exempt_at' => now(),
            'exempt_reason' => 'مسجد — إعفاء دائم',
            'exempt_category' => 'مسجد',
        ]);

        $card = $this->houseCard($house->id);

        $this->assertTrue($card['exempt']);
        $this->assertSame('0.00', $card['balance']);
        $this->assertSame([], $card['unpaid_months']);
    }

    public function test_the_card_says_which_of_the_houses_was_read_and_what_the_meter_stood_at(): void
    {
        $read = $this->meteredHouse('أحمد سعد', 1000);
        $unread = $this->meteredHouse('سامي دندشي', 500, ['maintenance_fee' => 15]);

        BillingService::enterMeteredReading($read, $this->period, 1150, $this->collector->id);

        $cards = $this->cards();

        $this->assertSame('1000.00', $cards[0]['previous_value']);
        $this->assertTrue($cards[0]['reading_done']);
        $this->assertSame('1150.00', $cards[0]['reading_value']);
        $this->assertFalse($cards[0]['reading_estimated']);

        $this->assertFalse($cards[1]['reading_done']);
        $this->assertNull($cards[1]['reading_value']);
        $this->assertSame('500.00', $cards[1]['previous_value']);
    }

    public function test_the_house_detail_hands_back_the_months_with_what_is_left_on_each(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09', '2026-10']);

        // Part of September, taken at the door last month.
        BillingService::collect($house, 25, $this->collector->id);

        $response = $this->api($this->collector, 'GET', '/api/v1/houses/'.$house->id)->assertOk();

        $response->assertJsonPath('house.name', 'محمد عيتاني')
            ->assertJsonPath('house.balance', '95.00')
            ->assertJsonPath('house.bills.0.period', '2026-09')
            ->assertJsonPath('house.bills.0.remaining', '35.00')
            ->assertJsonPath('house.bills.0.paid', '25.00')
            ->assertJsonPath('house.bills.1.period', '2026-10')
            ->assertJsonPath('house.bills.1.remaining', '60.00');

        // The number is here, in the detail, where a person can read it back to the office.
        $this->assertSame(
            BillingService::billNumber('2026-09', $house->id),
            $response->json('house.bills.0.number'),
        );
    }

    public function test_a_house_from_another_area_is_reachable_by_id_and_says_which_area(): void
    {
        $elsewhere = \App\Models\Route::create(['name' => 'حي الميدان']);
        $house = Subscriber::create([
            'route_id' => $elsewhere->id, 'name' => 'نادر', 'type' => Subscriber::TYPE_FLAT,
            'flat_fee' => 30, 'status' => Subscriber::STATUS_ACTIVE, 'created_by' => $this->admin->id,
        ]);

        $this->api($this->collector, 'GET', '/api/v1/houses/'.$house->id)
            ->assertOk()
            ->assertJsonPath('house.area_name', 'حي الميدان');
    }

    public function test_a_house_that_does_not_exist_is_a_json_404_not_a_page(): void
    {
        $this->api($this->collector, 'GET', '/api/v1/houses/999999')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_the_house_carries_the_credit_the_office_holds_for_him(): void
    {
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);
        BillingService::collect($house, 100, $this->collector->id);   // 60 owed, 40 waits on his account

        $card = $this->houseCard($house->id);

        $this->assertSame('0.00', $card['balance']);
        $this->assertSame('40.00', $card['credit']);
    }

    public function test_the_area_list_shows_a_house_whose_area_is_gone_rather_than_losing_it(): void
    {
        // Deleting an area is refused once it holds houses (the panel's own guard), but a house can
        // be left without one by an import or an older write — the sheet still carries it.
        $house = $this->flatHouse('بلا منطقة', 60, ['2026-09']);
        $house->forceFill(['route_id' => null])->save();

        $areas = $this->api($this->collector, 'GET', '/api/v1/areas')->assertOk()->json('areas');
        $names = array_column($areas, 'name');

        $this->assertContains(__('mobile.no_area'), $names);
        $this->assertSame('60.00', $areas[array_search(__('mobile.no_area'), $names, true)]['owed']);
    }

    public function test_the_sheet_carries_the_offices_own_lira_rate(): void
    {
        // The street pays in lira and the collector must not invent the rate: the office sets one
        // rate, and the phone asks for it rather than a man converting in his head at a door.
        Setting::set('lbp_rate', '90000');
        $house = $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->assertSame(
            90000.0,
            (float) $this->api($this->collector, 'GET', '/api/v1/areas')->assertOk()->json('lbp_rate'),
        );

        $this->assertSame(
            90000.0,
            (float) $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses')
                ->assertOk()
                ->assertJsonPath('houses.0.id', $house->id)
                ->json('lbp_rate'),
        );
    }

    public function test_with_no_rate_set_the_sheet_says_so_rather_than_zero(): void
    {
        // «الليرة بلا سعر» is not «سعر صفر»: a phone that showed 0 would be inviting a collector to
        // convert at his own guess, and a phone that showed a stale rate would be worse.
        $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'GET', '/api/v1/areas')
            ->assertOk()
            ->assertJsonPath('lbp_rate', null);

        $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses')
            ->assertOk()
            ->assertJsonPath('lbp_rate', null);
    }

    public function test_the_sheet_names_the_month_in_arabic_for_an_arabic_phone(): void
    {
        $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'GET', '/api/v1/areas', [], ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonPath('label', period_label_ar($this->period));
    }

    /**
     * The English case lives in its OWN test on purpose.
     *
     * A test process keeps the application it booted, so the locale an earlier request switched to
     * survives into the next request of the same test — a second assertion in the same method would
     * measure the test harness, not the API. A real phone gets a fresh request each time.
     */
    public function test_the_sheet_names_the_month_in_english_for_an_english_phone(): void
    {
        $this->flatHouse('محمد عيتاني', 60, ['2026-09']);

        $this->api($this->collector, 'GET', '/api/v1/areas', [], ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('label', period_label($this->period));
    }

    /** The card for one house out of the area's list. */
    private function houseCard(int $subscriberId): array
    {
        foreach ($this->cards() as $card) {
            if ($card['id'] === $subscriberId) {
                return $card;
            }
        }

        $this->fail('The house is missing from the area sheet.');
    }

    /** @return array<int, array<string, mixed>> */
    private function cards(): array
    {
        return $this->api($this->collector, 'GET', '/api/v1/areas/'.$this->area->id.'/houses')
            ->assertOk()
            ->json('houses');
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sumOf(array $rows, string $key): string
    {
        return $this->dec(array_sum(array_map(fn (array $row): float => (float) $row[$key], $rows)));
    }
}
