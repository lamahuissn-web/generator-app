<?php

namespace Tests\Feature\Api;

/**
 * Finding one household, from anywhere in the network.
 *
 * The collector's dashboard has had this on the web since the beginning — a street is not the only way
 * to a door — and the phone needs it more, not less: he is standing in the street with a question
 * («بيت أبو علي؟») rather than a map.
 *
 * The search is the panel's own: name, meter number or phone, two characters minimum, eight answers.
 * The balance it carries is the balance the household's own screen shows, because a search result that
 * disagrees with the screen it opens is worse than no search at all.
 */
class ApiSearchTest extends ApiTestCase
{
    public function test_a_household_is_found_by_name_meter_or_phone(): void
    {
        $house = $this->flatHouse('بيت أبو علي', 60, ['2026-09'], [
            'meter_no' => 'M-4478',
            'phone' => '76123456',
        ]);

        foreach (['أبو علي', 'M-4478', '76123456'] as $query) {
            $results = $this->api($this->collector, 'GET', '/api/v1/search?q='.urlencode($query))
                ->assertOk()
                ->json('results');

            $this->assertCount(1, $results, "«{$query}» should find بيت أبو علي");
            $this->assertSame($house->id, $results[0]['id']);
            $this->assertSame('بيت أبو علي', $results[0]['name']);
            $this->assertSame($this->area->name, $results[0]['route']);
        }
    }

    public function test_the_balance_a_result_carries_is_the_balance_its_own_screen_shows(): void
    {
        $house = $this->flatHouse('بيت أبو ندى', 60, ['2026-09']);
        $this->pay($house, 20);

        $found = $this->api($this->collector, 'GET', '/api/v1/search?q='.urlencode('أبو ندى'))
            ->assertOk()
            ->json('results.0');

        $shown = $this->api($this->collector, 'GET', '/api/v1/houses/'.$house->id)
            ->assertOk()
            ->json('house');

        $this->assertSame($shown['balance'], $found['balance']);
        $this->assertSame('40.00', $found['balance']);
        $this->assertTrue($found['owes']);
    }

    public function test_one_letter_is_not_a_search(): void
    {
        $this->flatHouse('بيت أبو علي', 60, ['2026-09']);

        // Not an error and not the whole network: a collector typing one letter has not asked anything
        // yet, and answering with eight houses of the office teaches him the search is noise.
        $this->assertSame([], $this->api($this->collector, 'GET', '/api/v1/search?q=ب')->assertOk()->json('results'));
        $this->assertSame([], $this->api($this->collector, 'GET', '/api/v1/search')->assertOk()->json('results'));
    }

    public function test_the_search_is_never_wider_than_eight_answers(): void
    {
        for ($i = 1; $i <= 11; $i++) {
            $this->flatHouse('بيت أبو علي '.$i, 60, ['2026-09']);
        }

        $results = $this->api($this->collector, 'GET', '/api/v1/search?q='.urlencode('أبو علي'))
            ->assertOk()
            ->json('results');

        $this->assertCount(8, $results);
    }

    public function test_an_inactive_house_is_still_findable_and_says_so(): void
    {
        $this->flatHouse('بيت موقوف', 60, ['2026-09'], ['status' => 'inactive']);

        $result = $this->api($this->collector, 'GET', '/api/v1/search?q='.urlencode('موقوف'))
            ->assertOk()
            ->json('results.0');

        // A stopped connection still has a door, a person and often a debt; hiding it from the search
        // would hide the reason the collector is standing there.
        $this->assertSame('inactive', $result['status']);
    }
}
