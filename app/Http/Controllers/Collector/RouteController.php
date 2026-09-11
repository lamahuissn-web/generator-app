<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RouteController extends Controller
{
    public function show(Route $route): View
    {
        $subscribers = $route->subscribers()
            ->with('route:id,name')
            ->orderBy('name')
            ->get();

        $owed = DB::table('bills as b')
            ->selectRaw('b.subscriber_id, COALESCE(SUM(b.total - b.paid_amount), 0) as o')
            ->whereRaw('b.paid_amount < b.total')
            ->whereIn('b.subscriber_id', $subscribers->pluck('id'))
            ->groupBy('b.subscriber_id')
            ->pluck('o', 'subscriber_id');

        // Which of them already has this cycle's reading. The list is the collector's
        // walked map, so a street that has been read must look different from one that
        // has not — the money owed was the only signal here before.
        $period = BillingService::currentPeriod();

        $readings = Reading::query()
            ->whereIn('subscriber_id', $subscribers->pluck('id'))
            ->where('period', $period)
            ->pluck('current_reading', 'subscriber_id');

        $metered = $subscribers
            ->filter(fn (Subscriber $s): bool => $s->isMetered() && $s->status === Subscriber::STATUS_ACTIVE);

        return view('collector.route', [
            'route' => $route,
            'subscribers' => $subscribers,
            'owed' => $owed,
            'period' => $period,
            'readings' => $readings,
            'meteredTotal' => $metered->count(),
            'meteredDone' => $metered->filter(fn (Subscriber $s): bool => $readings->has($s->id))->count(),
        ]);
    }

    /** The route's readings on one screen: type a value per house, save them together. */
    public function readings(Route $route): View
    {
        return view('collector.route-readings', compact('route'));
    }
}
