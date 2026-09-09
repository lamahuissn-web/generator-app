<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\Route;
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

        return view('collector.route', compact('route', 'subscribers', 'owed'));
    }
}
