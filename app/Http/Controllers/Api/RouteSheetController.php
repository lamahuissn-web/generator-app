<?php

namespace App\Http\Controllers\Api;

use App\Models\Reading;
use App\Models\Route;
use App\Models\Subscriber;
use App\Services\ApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The sheet the collector walks with.
 *
 * Two of the office's rules live in the shape of this answer rather than in a sentence on a screen:
 * what a house owes is shown as the MONTHS it is made of (a number he can say out loud at the door),
 * and a flat house whose cycle has not been billed yet is NOT a debt — it is a house with nothing to
 * collect until the office raises the month, which is why `period_invoiced` travels beside the zero.
 *
 * A house whose area was removed is carried under one named bucket instead of disappearing from the
 * totals: the areas must add up to the network, or the sheet a collector holds disagrees with the
 * board in the office.
 */
class RouteSheetController extends ApiController
{
    public function areas(Request $request): JsonResponse
    {
        $period = $this->cycle($request->query('period'));

        $houses = $this->counted(Subscriber::query()->selectRaw('route_id, COUNT(*) as total')->groupBy('route_id')->get());
        $active = $this->counted(Subscriber::query()->where('status', Subscriber::STATUS_ACTIVE)->selectRaw('route_id, COUNT(*) as total')->groupBy('route_id')->get());
        $metered = $this->counted(Subscriber::query()
            ->where('status', Subscriber::STATUS_ACTIVE)
            ->where('type', Subscriber::TYPE_METERED)
            ->selectRaw('route_id, COUNT(*) as total')
            ->groupBy('route_id')
            ->get());
        $read = $this->counted(Reading::query()
            ->where('period', $period)
            ->join('subscribers', 'subscribers.id', '=', 'readings.subscriber_id')
            ->selectRaw('subscribers.route_id as route_id, COUNT(*) as total')
            ->groupBy('subscribers.route_id')
            ->get());
        $owed = $this->summed(DB::table('bills as b')
            ->join('subscribers as s', 's.id', '=', 'b.subscriber_id')
            ->whereRaw('b.paid_amount < b.total')
            ->selectRaw('s.route_id as route_id, COALESCE(SUM(b.total - b.paid_amount), 0) as total')
            ->groupBy('s.route_id')
            ->get());

        $areas = [];

        foreach (Route::query()->orderBy('name')->get() as $route) {
            $areas[] = ApiPayload::area(
                (int) $route->id,
                (string) $route->name,
                $houses[(string) $route->id] ?? 0,
                $active[(string) $route->id] ?? 0,
                ApiPayload::dec($owed[(string) $route->id] ?? 0),
                $read[(string) $route->id] ?? 0,
                $metered[(string) $route->id] ?? 0,
            );
        }

        if (($houses['none'] ?? 0) > 0) {
            $areas[] = ApiPayload::area(
                null,
                __('mobile.no_area'),
                $houses['none'],
                $active['none'] ?? 0,
                ApiPayload::dec($owed['none'] ?? 0),
                $read['none'] ?? 0,
                $metered['none'] ?? 0,
            );
        }

        return response()->json(['period' => $period, 'areas' => $areas]);
    }

    public function houses(Request $request, Route $route): JsonResponse
    {
        $period = $this->cycle($request->query('period'));

        $houses = $route->subscribers()
            ->orderBy('name')
            ->get()
            ->map(fn (Subscriber $subscriber): array => ApiPayload::house($subscriber, $period))
            ->values()
            ->all();

        return response()->json([
            'period' => $period,
            'area' => ['id' => $route->id, 'name' => $route->name],
            'houses' => $houses,
        ]);
    }

    public function house(Request $request, Subscriber $subscriber): JsonResponse
    {
        $period = $this->cycle($request->query('period'));

        return response()->json([
            'period' => $period,
            'house' => ApiPayload::house($subscriber, $period, true),
        ]);
    }

    /** @return array<string, int> */
    private function counted(Collection $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) ($row->route_id ?? 'none')] = (int) $row->total;
        }

        return $out;
    }

    /** @return array<string, float> */
    private function summed(Collection $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) ($row->route_id ?? 'none')] = (float) $row->total;
        }

        return $out;
    }
}
