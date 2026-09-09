<?php

namespace App\Filament\Widgets;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NetworkStats extends BaseWidget
{
    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $period = now()->format('Y-m');

        $invoiced = (float) Bill::query()->where('period', $period)->sum('total');
        $collected = (float) Payment::query()->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount');
        $rate = $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0.0;

        $outstanding = (float) Bill::query()
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->whereRaw('paid_amount < total')
            ->value('o');

        $active = Subscriber::query()->active();
        $activeCount = (clone $active)->count();
        $meteredCount = (clone $active)->metered()->count();
        $flatCount = $activeCount - $meteredCount;

        $kwh = (float) Bill::query()->where('period', $period)->sum('consumption');

        return [
            Stat::make('Invoiced '.period_label($period), money($invoiced))
                ->description('Total billed this period')
                ->icon('heroicon-o-document-text')
                ->color('info'),
            Stat::make('Collected this month', money($collected))
                ->description('Cash recorded in '.now()->format('M Y'))
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Collection rate', $rate.'%')
                ->description('Collected ÷ invoiced, this period')
                ->icon('heroicon-o-arrow-trending-up')
                ->color($rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger')),
            Stat::make('Outstanding debt', money($outstanding))
                ->description('Unpaid across all periods')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger'),
            Stat::make('Active subscribers', (string) $activeCount)
                ->description($meteredCount.' metered · '.$flatCount.' flat-rate')
                ->icon('heroicon-o-users')
                ->color('info'),
            Stat::make('kWh consumed', number_format($kwh, 1))
                ->description('Network total for '.period_label($period))
                ->icon('heroicon-o-bolt')
                ->color('gray'),
        ];
    }
}
