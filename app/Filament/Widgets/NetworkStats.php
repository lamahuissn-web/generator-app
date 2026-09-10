<?php

namespace App\Filament\Widgets;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
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

        // Previous cycle, for the month-over-month comparison (like PG Unite's "vs Last Cycle").
        $previous = now()->subMonthNoOverflow();
        $prevPeriod = $previous->format('Y-m');
        $prevInvoiced = (float) Bill::query()->where('period', $prevPeriod)->sum('total');
        $prevCollected = (float) Payment::query()
            ->whereBetween('created_at', [$previous->copy()->startOfMonth(), $previous->copy()->endOfMonth()])
            ->sum('amount');
        $prevRate = $prevInvoiced > 0 ? round($prevCollected / $prevInvoiced * 100, 1) : 0.0;
        $invoicedDelta = $prevInvoiced > 0 ? round(($invoiced - $prevInvoiced) / $prevInvoiced * 100, 1) : null;
        $rateDelta = round($rate - $prevRate, 1);

        $tariff = BillingService::kwhPrice($period);
        $tariffSource = BillingService::priceSource($period);

        return [
            Stat::make('Invoiced '.period_label($period), money($invoiced))
                ->description($invoicedDelta === null
                    ? 'Total billed this period'
                    : 'vs previous cycle: '.($invoicedDelta >= 0 ? '+' : '').$invoicedDelta.'%')
                ->descriptionIcon($invoicedDelta !== null && $invoicedDelta < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-arrow-trending-up')
                ->icon('heroicon-o-document-text')
                ->color('info'),
            Stat::make('Collected this month', money($collected))
                ->description('Cash recorded in '.now()->format('M Y'))
                ->icon('heroicon-o-banknotes')
                ->color('success'),
            Stat::make('Collection rate', $rate.'%')
                ->description('Previous cycle: '.$prevRate.'% ('.($rateDelta >= 0 ? '+' : '').$rateDelta.')')
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
            Stat::make('kWh price '.period_label($period), money($tariff))
                ->description($tariffSource === 'tariff'
                    ? 'Ministry tariff for this cycle'
                    : 'Global price (no tariff set for this cycle)')
                ->icon('heroicon-o-currency-dollar')
                ->color($tariffSource === 'tariff' ? 'warning' : 'gray'),
        ];
    }
}
