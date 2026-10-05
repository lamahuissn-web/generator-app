<?php

namespace App\Http\Controllers\Api;

use App\Models\Subscriber;
use App\Services\ApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finding one household anywhere in the network.
 *
 * The collector's dashboard has had this on the web since the beginning — a street is not the only way
 * to a door — and a phone needs it more, not less: he is standing in the street with a question
 * («بيت أبو علي؟») rather than a map.
 *
 * Three rules, all of them the panel's own:
 *
 *  - **two characters minimum.** One letter is not a question yet, and answering it with eight houses
 *    teaches the collector that the search is noise. An empty result, not an error.
 *  - **name, meter number or phone** — the three things a collector can know about a door he has not
 *    opened yet.
 *  - **eight answers at most**, ordered by name, so the answer fits one glance.
 *
 * A stopped connection is still findable: it has a door, a person and often a debt, and hiding it would
 * hide the reason he is standing there.
 */
class SearchController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['query' => $query, 'results' => []]);
        }

        $like = '%'.$query.'%';

        $subscribers = Subscriber::query()
            ->where(function ($builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('meter_no', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->with('route:id,name')
            ->orderBy('name')
            ->limit(8)
            ->get();

        return response()->json([
            'query' => $query,
            'results' => $subscribers->map(fn (Subscriber $subscriber): array => ApiPayload::searchRow($subscriber))->all(),
        ]);
    }
}
