<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Models\Payment;
use App\Services\ApiIdempotency;
use App\Services\ApiPayload;
use App\Services\CashHandoverService;
use App\Services\CollectionService;
use App\Services\CollectorStats;
use App\Services\OfficeBoard;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The manager's phone (spec 009).
 *
 * Phase A is READ: the operator away from his desk asks two questions — how did the month go, and how much
 * is on each collector — and both are answered from the office's own services, so a figure he saw at the
 * desk and the same figure on his phone cannot differ.
 *
 * Phase B is ONE write, and only one: a collector handing cash to the office. It is deliberately not the
 * first of a family of admin writes on a phone — the writes that could hurt (reversing a receipt, changing
 * a tariff) stay on the desk, while this one moves the figure the office reconciles its drawer against and
 * the person holding the money is the one who signs for it.
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

    /**
     * A collector hands cash to the office, recorded by the manager holding it (phase B).
     *
     * Three things are reused rather than re-decided here: the ceiling (`CashHandoverService` refuses cash
     * a collector is not holding, by name, with the figure he does hold), the trail (the service writes the
     * same audit entry the desk writes), and the key (a manager who taps twice — or a request answered by a
     * timeout and sent again — records ONE handover).
     *
     * The date is the day the cash changed hands, never a future one: an offline phone's clock is not the
     * office's, and a handover dated tomorrow would sit in a drawer-balance that has not happened yet.
     */
    public function handover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'collector_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'handover_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);

        $manager = $request->user();
        $collector = $this->person((int) $data['collector_id']);

        $date = isset($data['handover_date'])
            ? Carbon::createFromFormat('Y-m-d', $data['handover_date'])->startOfDay()
            : now()->startOfDay();

        if ($date->greaterThan(now()->startOfDay())) {
            throw new ApiRefusal('mobile.future_handover_date', __('mobile.future_handover_date'));
        }

        $amount = round((float) $data['amount'], 2);
        $note = $data['note'] ?? null;

        $payload = [
            'collector_id' => $collector->id,
            'amount' => ApiPayload::dec($amount),
            'handover_date' => $date->toDateString(),
            'note' => $note,
        ];

        $attempt = ApiIdempotency::run(
            $request,
            $manager,
            'handover',
            $payload,
            function () use ($collector, $amount, $date, $note, $manager): array {
                $handover = ApiRefusal::business(fn () => CashHandoverService::create([
                    'collector_id' => $collector->id,
                    'amount' => $amount,
                    'handover_date' => $date->toDateString(),
                    'note' => $note,
                ], $manager->id));

                return ApiPayload::handover($handover, $collector);
            },
        );

        return response()->json($attempt['payload'] + ['replay' => $attempt['replay']], 201);
    }
}
