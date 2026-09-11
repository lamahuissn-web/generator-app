<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Route;
use App\Services\BillingService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The office entering a street's readings itself.
 *
 * The field does this from the collector portal, but the office does too and for different reasons:
 * a subscriber walks in with the number written on their bill, a collector phones in a value, or a
 * paper slip arrives a month late. Until now the only way in was one subscriber at a time through
 * a full-page form, which is the same work the field worklist already removed.
 *
 * The screen therefore starts with a decision — WHICH street — and then hands the same sheet to the
 * office. The route list carries each area's pending count, so choosing is a look at where the
 * month actually stands rather than a guess; and because the sheet is shared, a reading entered here
 * follows exactly the rules a reading entered in the street follows.
 */
class ReadingEntry extends Component
{
    /** Kept in the URL: an operator sends a colleague the street they were working on. */
    #[Url(as: 'route_id')]
    public ?int $routeId = null;

    /** Any cycle, because the office also enters last month's slips. */
    #[Url(as: 'period')]
    public string $period = '';

    public function mount(): void
    {
        if ($this->period === '' || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->period)) {
            $this->period = BillingService::currentPeriod();
        }

        // A route id from the URL that no longer exists must not render an empty sheet as if the
        // street had no subscribers; the picker comes back instead.
        if ($this->routeId !== null && ! Route::query()->whereKey($this->routeId)->exists()) {
            $this->routeId = null;
        }
    }

    public static function subheading(): string
    {
        return __('rdge.sub');
    }

    public function selectedRoute(): ?Route
    {
        return $this->routeId === null ? null : Route::query()->find($this->routeId);
    }

    /** @return array<string, string> cycle picker options, the same window as every other screen */
    public function periodOptions(): array
    {
        return period_options();
    }

    /**
     * The areas, each with how much of the cycle is still unread.
     *
     * Pending first: the reason to open this screen is a street that still needs walking, and an
     * alphabetical list would hide it under three finished ones.
     *
     * @return Collection<int, array{id: int, name: string, total: int, recorded: int, pending: int}>
     */
    public function routes(): Collection
    {
        $progress = BillingService::readingProgress($this->period);

        $byId = collect($progress['routes'])->keyBy('id');

        return Route::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Route $route) use ($byId): array {
                $row = $byId->get($route->id) ?? ['total' => 0, 'recorded' => 0, 'pending' => 0];

                return [
                    'id' => $route->id,
                    'name' => $route->name,
                    'total' => (int) $row['total'],
                    'recorded' => (int) $row['recorded'],
                    'pending' => (int) $row['pending'],
                ];
            })
            ->sortBy([fn ($a, $b) => $b['pending'] <=> $a['pending'], fn ($a, $b) => strcmp($a['name'], $b['name'])])
            ->values();
    }

    public function selectRoute(int $routeId): void
    {
        $this->routeId = $routeId;
        $this->resetErrorBag();
    }

    public function clearRoute(): void
    {
        $this->routeId = null;
    }

    public function render()
    {
        return view('livewire.admin.pages.reading-entry', [
            'routes' => $this->routes(),
            'route' => $this->selectedRoute(),
            'periodOptions' => $this->periodOptions(),
        ]);
    }
}
