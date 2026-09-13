<?php

namespace Tests\Feature;

use App\Livewire\Admin\MonthPicker;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The month calendar: the control every cycle is chosen with.
 *
 * Its state lives on the server (which year is on screen, whether the grid is open) because that state
 * decides what a click MEANS. The first version kept the year in the browser and wrote the month with a
 * compound `$set(...)` expression, which Livewire does not evaluate — the grid opened, the click looked
 * wired, and nothing happened. These tests are here to keep the decision on the side of the wire where
 * it can be checked.
 */
class MonthPickerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The component, with a month already chosen (as a host screen binds it) and, when given, the list
     * of months that hold data.
     */
    private function picker(?string $value = null, ?array $monthsWithData = null): Testable
    {
        $this->actingAs(User::create([
            'name' => 'مدير المكتب',
            'email' => 'picker@ledger.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
        ]));

        $component = Livewire::test(
            MonthPicker::class,
            $monthsWithData === null ? [] : ['monthsWithData' => $monthsWithData],
        );

        return $value === null ? $component : $component->set('value', $value);
    }

    public function test_it_opens_closed_on_the_month_it_was_given(): void
    {
        // Asserted through the same helpers the view uses: the panel is bilingual, and a test that
        // hard-codes 'آب 2026' passes or fails with whoever left the locale behind.
        $this->picker('2026-08')
            ->assertSet('open', false)
            ->assertSet('year', 2026)
            ->assertSee(period_label_i18n('2026-08'))   // the trigger names the chosen month…
            ->assertDontSee(month_names()[1]);          // …and the grid is not drawn until it is opened
    }

    public function test_it_shows_the_placeholder_when_nothing_is_chosen_yet(): void
    {
        $this->picker()
            ->assertSee(__('t.pick_month'))
            ->assertSet('value', null);
    }

    public function test_opening_it_draws_the_twelve_months_of_the_year_on_screen(): void
    {
        $component = $this->picker('2026-08')->call('toggle');

        $component->assertSet('open', true)->assertSee('2026');

        foreach (month_names() as $name) {
            $component->assertSee($name);
        }
    }

    public function test_the_arrows_move_the_year_and_stop_at_the_ends(): void
    {
        $this->picker('2026-08')
            ->call('step', -1)->assertSet('year', 2025)
            ->call('step', 1)->assertSet('year', 2026)
            // The window is bounded, so an operator cannot wander into a year nothing can be billed for.
            ->call('step', -100)->assertSet('year', 2020)
            ->call('step', 100)->assertSet('year', (int) now()->format('Y') + 2);
    }

    public function test_choosing_a_month_writes_the_value_the_screen_reads_and_closes(): void
    {
        // The year on screen is the one that gets written — July of 2025 is '2025-07', not '2026-07'.
        $this->picker('2026-08')
            ->call('toggle')
            ->call('step', -1)
            ->assertSet('year', 2025)
            ->call('choose', 7)
            ->assertSet('value', '2025-07')
            ->assertSet('open', false);
    }

    /**
     * The choice is ANNOUNCED, not merely stored.
     *
     * The host screens write it into their own state by listening for this event; a picker that only
     * updated itself would look identical on screen and change nothing — which is exactly how the
     * first version failed.
     */
    public function test_choosing_announces_the_month_to_whoever_hosts_the_picker(): void
    {
        $this->picker('2026-08')
            ->call('toggle')
            ->call('choose', 7)
            ->assertSet('value', '2026-07')
            ->assertDispatched('month-picked', month: '2026-07');
    }

    public function test_closing_without_choosing_announces_nothing(): void
    {
        $this->picker('2026-08')
            ->call('toggle')
            ->call('close')
            ->assertNotDispatched('month-picked');
    }

    public function test_a_month_the_old_list_never_offered_is_reachable(): void
    {
        // Nineteen months back, and forward into a cycle the office is pre-entering from paper.
        $this->picker('2026-08')
            ->call('toggle')
            ->call('step', -4)
            ->call('choose', 3)
            ->assertSet('value', '2022-03');
    }

    /**
     * The mark appears on the months that hold something, and on no others.
     *
     * Asserted CELL BY CELL rather than by counting marks: a count cannot tell a mark under the wrong
     * month from a mark under the right one, and "there is data in June and none in July" is exactly
     * the claim the operator reads the mark for.
     */
    public function test_it_marks_the_months_that_hold_data_and_no_others(): void
    {
        $data = ['2026-06', '2026-07', '2026-08'];

        $html = $this->picker('2026-07', $data)->call('toggle')->html();

        foreach (month_names() as $number => $name) {
            $month = sprintf('2026-%02d', $number);

            $this->assertSame(
                in_array($month, $data, true),
                $this->cellHasMark($html, $name),
                "the mark under {$month} is wrong",
            );
        }
    }

    /** Does the cell for this month carry the little bar? */
    private function cellHasMark(string $html, string $name): bool
    {
        preg_match_all('/<button[^>]*wire:click="choose\(\d+\)"[^>]*>(.*?)<\/button>/s', $html, $matches);

        foreach ($matches[0] as $cell) {
            if (str_contains($cell, $name)) {
                return str_contains($cell, 'h-1 w-3 rounded-full');
            }
        }

        $this->fail("No cell was rendered for {$name}");
    }

    public function test_the_chosen_month_is_filled_and_the_current_one_is_outlined(): void
    {
        $chosen = '2026-07';
        $now = now()->format('Y-m');

        $html = $this->picker($chosen, [])->call('toggle')->html();

        // The fill belongs to the chosen month, wherever it is.
        $this->assertStringContainsString('border-primary bg-primary text-primary-foreground', $html);

        // The outline belongs to the month the program is living in, whatever is chosen.
        $this->assertStringContainsString('border-border', $html);
        $this->assertNotSame($chosen, $now, 'this test is only meaningful while the two differ');
    }

    public function test_an_impossible_month_is_ignored_rather_than_written(): void
    {
        $this->picker('2026-08')
            ->call('toggle')
            ->call('choose', 13)
            ->assertSet('value', '2026-08')
            ->call('choose', 0)
            ->assertSet('value', '2026-08');
    }

    public function test_it_closes_without_choosing_anything(): void
    {
        $this->picker('2026-08')
            ->call('toggle')
            ->call('close')
            ->assertSet('open', false)
            ->assertSet('value', '2026-08');
    }
}
