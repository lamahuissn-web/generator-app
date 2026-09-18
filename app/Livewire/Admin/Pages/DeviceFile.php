<?php

namespace App\Livewire\Admin\Pages;

use App\Models\Equipment;
use App\Reports\OperationsReport;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * One device's own page — identity, next service, fuel anomaly, and one timeline.
 *
 * Two buttons, each a modal of two or three fields: «تعبئة مازوت» and «صيانة». The log is a
 * single chronological line mixing refuellings and services so the owner reads it as a story.
 */
class DeviceFile extends Component
{
    public int $equipmentId;

    private ?Equipment $equipmentCache = null;

    public static function subheading(): string
    {
        return __('eq.device_sub');
    }

    public function mount(Equipment|int $equipmentId): void
    {
        $this->equipmentId = $equipmentId instanceof Equipment ? $equipmentId->id : (int) $equipmentId;
    }

    private function equipment(): Equipment
    {
        return $this->equipmentCache ??= Equipment::query()->with(['refuellings', 'services'])->findOrFail($this->equipmentId);
    }

    /** @return array<string, mixed> */
    private function nextService(): array
    {
        return OperationsReport::forMonth(now()->format('Y-m'))->nextService($this->equipment());
    }

    /** @return array{litres_per_hour: float, history: float, deviation: float, direction: string}|null */
    private function anomaly(): ?array
    {
        return OperationsReport::forMonth(now()->format('Y-m'))->fuelAnomaly($this->equipment());
    }

    /** @return Collection<int, array{date: string, kind: string, label: string, detail: string}> */
    private function timeline(): Collection
    {
        $equipment = $this->equipment();
        $refuellings = $equipment->refuellings()
            ->orderBy('date')
            ->get()
            ->map(fn ($r): array => [
                'date' => $r->date->toDateString(),
                'kind' => 'refuel',
                'label' => __('ops.timeline_refuel', ['litres' => number_format((float) $r->litres, 0), 'price' => '$'.number_format((float) $r->price_per_litre, 3)]),
                'detail' => trim(collect([
                    $r->engine_hours !== null ? __('ops.device_hours', ['hours' => number_format((float) $r->engine_hours, 0)]) : null,
                    $r->kwh_produced !== null ? number_format((float) $r->kwh_produced, 1).' '.__('t.kwh').' '.($r->kwh_source === 'measured' ? __('ops.basis_measured') : __('ops.basis_estimated')) : null,
                    $r->notes,
                ])->filter()->implode(' · ')),
            ]);

        $services = $equipment->services()
            ->orderBy('date')
            ->get()
            ->map(fn ($s): array => [
                'date' => $s->date->toDateString(),
                'kind' => 'service',
                'label' => __('ops.timeline_service', ['kind' => $s->kindLabel()]),
                'detail' => trim(collect([
                    $s->engine_hours !== null ? __('ops.device_hours', ['hours' => number_format((float) $s->engine_hours, 0)]) : null,
                    $s->cost > 0 ? money($s->cost) : null,
                    $s->notes,
                ])->filter()->implode(' · ')),
            ]);

        return $refuellings->concat($services)->sortBy('date')->values();
    }

    public function render()
    {
        $report = OperationsReport::forMonth(now()->format('Y-m'));

        return view('livewire.admin.pages.device-file', [
            'equipment' => $this->equipment(),
            'nextService' => $this->nextService(),
            'anomaly' => $this->anomaly(),
            'timeline' => $this->timeline(),
            'returnTo' => request()->fullUrl(),
        ]);
    }
}
