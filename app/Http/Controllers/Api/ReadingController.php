<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Services\ApiIdempotency;
use App\Services\ApiPayload;
use App\Models\Bill;
use App\Models\Reading;
use App\Services\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reading, entered at the meter.
 *
 * The endpoint adds nothing to the engine: it hands `enterMeteredReading` the same arguments the
 * panel hands it, so the invoice, the refusal and the audit row are the ones this program already
 * makes. What it does add is the reader's identity — taken from the TOKEN, never from the body — and
 * the idempotency key, so a phone that retries in a dead spot raises one invoice rather than two.
 */
class ReadingController extends ApiController
{
    /**
     * Correction: remove a mistaken reading and the invoice it produced (spec 012).
     *
     * The rule is the ENGINE's, not a new one: `BillingService::deleteReadingAndBill` keeps its own refusals
     * (a paid bill, naming the amount collected against it; a closed cycle; a reading with no invoice) and
     * its own audit entry carrying the ORIGINAL value and the reason. What this adds is only what a phone
     * needs: whose reading it is, whether it is still the day, and a written reason — which the engine wants
     * anyway for a month it has already closed.
     */
    public function correct(Request $request, int $readingId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        $user = $request->user();
        $reason = trim((string) $data['reason']);

        // Built from the REQUEST, never from the ledger: this write deletes the row it names, so a retry
        // after a timeout must get the FIRST attempt's answer — the lesson the reversal taught. That is why
        // the id arrives as a number and the reading is looked up inside the attempt: binding it on the way
        // in would turn a retry into «no such reading», which is the one answer a retry must never get.
        $payload = ['reading_id' => $readingId, 'reason' => $reason];

        $attempt = ApiIdempotency::run(
            $request,
            $user,
            'reading-correction',
            $payload,
            function () use ($readingId, $reason, $user): array {
                $reading = Reading::query()->find($readingId)
                    ?? throw new ApiRefusal('mobile.unknown_reading', __('mobile.unknown_reading'));

                // A collector corrects his own reading, on the day he is still standing in; the office
                // corrects any reading, any day (the owner's decision) — and the engine still has the last
                // word for both.
                if (! $user->isAdmin()) {
                    if ((int) $reading->entered_by !== (int) $user->id) {
                        throw new ApiRefusal('mobile.not_your_reading', __('mobile.not_your_reading'));
                    }

                    if (! $reading->created_at?->isToday()) {
                        throw new ApiRefusal(
                            'mobile.correction_window_closed',
                            __('mobile.correction_window_closed'),
                        );
                    }
                }

                $subscriber = $reading->subscriber;
                $bill = Bill::query()->where('reading_id', $reading->id)->first();

                // Read before it goes: after the correction there is no row left to describe.
                $gone = [
                    'id' => $reading->id,
                    'period' => (string) $reading->period,
                    'label' => ApiPayload::label((string) $reading->period),
                    'previous' => ApiPayload::dec($reading->previous_reading),
                    'current' => ApiPayload::dec($reading->current_reading),
                    'consumption' => $reading->consumption === null ? null : ApiPayload::dec($reading->consumption),
                    'bill_number' => $bill?->number,
                    'bill_total' => $bill === null ? null : ApiPayload::dec($bill->total),
                ];

                ApiRefusal::business(fn () => BillingService::deleteReadingAndBill($reading, $reason));

                return ApiPayload::correction($gone, $subscriber);
            },
        );

        return response()->json($attempt['payload'] + ['replay' => $attempt['replay']], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $estimated = filter_var($request->input('estimated', false), FILTER_VALIDATE_BOOLEAN);

        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'min:1'],
            'period' => ['nullable', 'string', 'max:7'],
            'current_reading' => [Rule::requiredIf(! $estimated), 'nullable', 'numeric', 'min:0'],
            'estimated_kwh' => [Rule::requiredIf($estimated), 'nullable', 'numeric', 'min:0'],
            'reason' => [Rule::requiredIf($estimated), 'nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            // The collector looked at a number the engine found strange and said it was right (spec 013).
            // Without it the engine refuses to bill — and says why, in a sentence that carries the average.
            'confirmed_anomaly' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $subscriber = $this->subscriber((int) $data['subscriber_id']);
        $period = $this->cycle($data['period'] ?? null);

        $payload = [
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'estimated' => $estimated,
            'current_reading' => $estimated ? null : (string) $data['current_reading'],
            'estimated_kwh' => $estimated ? (string) $data['estimated_kwh'] : null,
            'reason' => $estimated ? (string) $data['reason'] : null,
            'note' => $data['note'] ?? null,
            'confirmed_anomaly' => (bool) ($data['confirmed_anomaly'] ?? false),
        ];

        $attempt = ApiIdempotency::run($request, $user, 'reading', $payload, function () use ($subscriber, $period, $user, $data, $estimated): array {
            $result = ApiRefusal::business(fn (): array => $estimated
                ? BillingService::enterEstimatedReading(
                    $subscriber,
                    $period,
                    (float) $data['estimated_kwh'],
                    (string) $data['reason'],
                    $user->id,
                )
                : BillingService::enterMeteredReading(
                    $subscriber,
                    $period,
                    (float) $data['current_reading'],
                    $user->id,
                    $data['note'] ?? null,
                    ['confirmed_anomaly' => (bool) ($data['confirmed_anomaly'] ?? false)],
                ));

            return ApiPayload::reading($result['reading'], $result['bill'], $subscriber);
        });

        return response()->json($attempt['payload'] + ['replay' => $attempt['replay']], 201);
    }
}
