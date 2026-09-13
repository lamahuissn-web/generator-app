<?php

namespace App\Livewire\Admin;

use Livewire\Component;

/**
 * The month calendar — the control every cycle is chosen with, on every screen that has one.
 *
 * It is a Livewire component rather than a plain Blade one because it is the only control in the panel
 * with STATE OF ITS OWN: which year is on screen, and whether the grid is open. A Blade component has
 * nowhere to keep either, so the first version kept them in Alpine and wrote the month through
 * `wire:click="$set(...)"` with the year spliced in client-side — and Livewire does not evaluate a
 * compound expression like that, so the click did nothing at all while looking perfectly wired. State
 * that decides what an operator's click MEANS belongs on the server, where it can be tested.
 *
 * The chosen month travels to whatever the host binds: `wire:model="period"`, `wire:model="month"`,
 * `wire:model="filters.period"` — the same 'YYYY-MM' the rest of the program stores, so a screen that
 * already reads a period needs no knowledge of calendars.
 */
class MonthPicker extends Component
{
    /** The chosen month ('YYYY-MM'), handed in by the host and announced back through the event. */
    public ?string $value = null;

    /** The year the grid is showing — not necessarily the chosen month's. */
    public int $year;

    public bool $open = false;

    /** Shown on the closed trigger when nothing is chosen yet (a filter that is not applied). */
    public ?string $placeholder = null;

    /** Years the arrows may reach. */
    public int $fromYear = 2020;

    public int $toYear = 0;

    /** The cycles that hold a reading or a bill; the grid marks them. */
    public array $monthsWithData = [];

    public function mount(
        ?string $placeholder = null,
        int $fromYear = 2020,
        ?int $toYear = null,
        ?array $monthsWithData = null,
    ): void {
        $this->placeholder = $placeholder ?? __('t.pick_month');
        $this->fromYear = $fromYear;
        // An office pre-enters the reading it already holds on paper, so the window reaches a little
        // forward as well as back — but not into the next decade.
        $this->toYear = $toYear ?? (int) now()->format('Y') + 2;
        $this->monthsWithData = $monthsWithData ?? periods_with_data();

        // Open on the chosen month's year (looking at August and seeing 2026 is not confusing;
        // looking at August and being shown 2020 is).
        $this->year = $this->value !== null && preg_match('/^\d{4}-\d{2}$/', $this->value)
            ? (int) substr($this->value, 0, 4)
            : (int) now()->format('Y');
    }

    /** The year arrows. Older is to the right: this UI reads right-to-left. */
    public function step(int $direction): void
    {
        $this->year = max($this->fromYear, min($this->toYear, $this->year + $direction));
    }

    /**
     * Pick a month of the year on screen, and tell whoever is listening.
     *
     * The event is the load-bearing part. `wire:model` on a child component looks like it carries the
     * value back to the host, and it does not in this Livewire version: measured in a real browser, the
     * child's value changed while the host's filter stayed empty. An explicit event — the host's own
     * `$wire.set(...)` on the element that hosts this picker — has no such gap, and it is visible in
     * the host's markup instead of hidden inside a binding.
     */
    public function choose(int $month): void
    {
        if ($month < 1 || $month > 12) {
            return;
        }

        $this->value = sprintf('%04d-%02d', $this->year, $month);
        $this->open = false;

        $this->dispatch('month-picked', month: $this->value);
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render()
    {
        return view('livewire.admin.month-picker', [
            'names' => month_names(),
            'now' => now()->format('Y-m'),
            'chosen' => $this->value !== null && preg_match('/^\d{4}-\d{2}$/', $this->value) ? $this->value : null,
        ]);
    }
}
