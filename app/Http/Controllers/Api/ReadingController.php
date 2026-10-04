<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Services\ApiIdempotency;
use App\Services\ApiPayload;
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
                ));

            return ApiPayload::reading($result['reading'], $result['bill'], $subscriber);
        });

        return response()->json($attempt['payload'] + ['replay' => $attempt['replay']], 201);
    }
}
