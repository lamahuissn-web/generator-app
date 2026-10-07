<?php

namespace App\Http\Controllers\Api;

use App\Services\ApiPayload;
use App\Services\CollectorStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's own purse (spec 011).
 *
 * It answers about the TOKEN'S OWNER and nobody else — no id in the request, so there is no way to ask
 * about another man's cash. A manager may ask too and gets his own zeroes (nobody collects in his name):
 * refusing him would hide a mistake rather than protect anything.
 *
 * Nothing here computes money. `CollectorStats` is the panel's own definition of the day, the month and the
 * pocket, and `CollectionService::inHand` is the engine's rule — the same figures the office reconciles
 * against at closing time.
 */
class MeController extends ApiController
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $period = $this->cycle($request->query('period'));

        return response()->json(ApiPayload::wallet(
            $user,
            CollectorStats::for($user),
            CollectorStats::readings($user, $period),
            CollectorStats::handovers($user, 5),
            $period,
        ));
    }
}
