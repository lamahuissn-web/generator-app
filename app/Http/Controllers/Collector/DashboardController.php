<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\CashHandover;
use App\Models\Payment;
use App\Models\Route;
use App\Services\CollectionService;
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
        $today = Payment::query()->where('collector_id', $user->id)->whereDate('created_at', today());
        $month = Payment::query()
            ->where('collector_id', $user->id)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);

        $stats = [
            'today_total' => (float) (clone $today)->sum('amount'),
            'today_count' => (clone $today)->count(),
            'month_total' => (float) (clone $month)->sum('amount'),
            'month_count' => (clone $month)->count(),
            // Cash still in the collector's pocket: everything collected ever,
            // minus everything already handed to the operator.
            'in_hand' => CollectionService::inHand($user->id),
            'handed_total' => CollectionService::handedOverAllTime($user->id),
        ];

        $handovers = CashHandover::query()
            ->where('collector_id', $user->id)
            ->latest('handover_date')
            ->latest('id')
            ->limit(3)
            ->get();

        return view('collector.dashboard', compact('routes', 'owedByRoute', 'stats', 'handovers'));
    }
}
