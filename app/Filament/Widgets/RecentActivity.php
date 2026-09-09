<?php

namespace App\Filament\Widgets;

use App\Models\Payment;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class RecentActivity extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.widgets.recent-activity';

    public function payments(): Collection
    {
        return Payment::query()
            ->with(['subscriber:id,name', 'collector:id,name'])
            ->latest('created_at')
            ->limit(8)
            ->get();
    }

    public function topDebtors(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('bills')
            ->join('subscribers', 'subscribers.id', '=', 'bills.subscriber_id')
            ->leftJoin('routes', 'routes.id', '=', 'subscribers.route_id')
            ->selectRaw('subscribers.id as subscriber_id, subscribers.name as subscriber_name, routes.name as route_name, COALESCE(SUM(bills.total - bills.paid_amount), 0) as owed, COUNT(*) as bills_open')
            ->whereRaw('bills.paid_amount < bills.total')
            ->groupBy('subscribers.id', 'subscribers.name', 'routes.name')
            ->orderByDesc('owed')
            ->limit(8)
            ->get();
    }
}
