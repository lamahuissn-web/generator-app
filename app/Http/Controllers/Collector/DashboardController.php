<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\Route;
use App\Services\CollectorStats;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $routes = Route::query()
            ->withCount('subscribers')
            ->withCount(['subscribers as active_count' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $owedByRoute = DB::table('bills as b')
            ->join('subscribers as s', 's.id', '=', 'b.subscriber_id')
            ->selectRaw('s.route_id, COALESCE(SUM(b.total - b.paid_amount), 0) as o')
            ->whereRaw('b.paid_amount < b.total')
            ->groupBy('s.route_id')
            ->pluck('o', 'route_id');

        $user = auth()->user();

        // One definition of the collector's own money, shared with the phone: CollectorStats.
        $stats = CollectorStats::for($user);
        $handovers = CollectorStats::handovers($user);

        return view('collector.dashboard', compact('routes', 'owedByRoute', 'stats', 'handovers'));
    }
}
