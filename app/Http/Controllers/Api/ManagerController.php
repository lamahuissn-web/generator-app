<?php

namespace App\Http\Controllers\Api;

use App\Models\Payment;
use App\Services\ApiPayload;
use App\Services\CollectionService;
use App\Services\CollectorStats;
use App\Services\OfficeBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The manager's board (spec 009, phase A).
 *
 * The operator away from his desk asks two questions: how did the month go, and how much is on each
 * collector. Both are answered from the office's own services — the same expressions the web dashboard
 * reads — so a figure he saw at the desk and the same figure on his phone cannot differ.
 *
 * **This endpoint is read-only, on purpose.** The owner's decision of 2026-10-05: the manager's phone is
 * for knowing, not for posting. Nothing here may grow a write without a new decision, because the
 * writes a manager might want from a pocket (reversing a receipt, changing a tariff) are exactly the
 * ones that must not be one tap away.
 *
 * It is behind `api.role:admin`: a collector must never see the cash his colleagues are carrying.
 */
class ManagerController extends ApiController
{
    public function board(Request $request): JsonResponse
    {
        $period = $this->cycle($request->query('period'));
        $numbers = OfficeBoard::numbers($period);

        $collectors = CollectionService::byCollector(
            now()->copy()->startOfMonth(),
            now()->copy()->endOfMonth(),
        );

        $recent = Payment::query()
            ->with(['subscriber:id,name', 'collector:id,name'])
            ->latest('created_at')
            ->limit(5)
            ->get();

        return response()->json(ApiPayload::managerBoard(
            $period,
            $numbers,
            $collectors,
            CollectorStats::todayByCollector(),
            $recent,
        ));
    }
}
