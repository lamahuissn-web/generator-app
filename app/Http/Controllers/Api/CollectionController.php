<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Models\Payment;
use App\Services\ApiIdempotency;
use App\Services\ApiPayload;
use App\Services\BillingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The money taken at the door.
 *
 * Nothing about the money is decided here. `BillingService::collect` settles the oldest month first,
 * caps the amount at what is owed, and never raises the invoice it settles — so a receipt from a
 * phone cannot make a balance grow. The endpoint's own contribution is the two things a street
 * needs and a panel does not:
 *
 *  - **the business date travels with the receipt.** A phone with no signal takes the money on the
 *    3rd and syncs on the 4th; the office's month is the 3rd's, because the receipt number, the
 *    reports and the handover all read the day the money was collected, not the day it was typed.
 *  - **the key.** A queue that is flushed twice must not charge twice.
 */
class CollectionController extends ApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscriber_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'in:usd,lbp'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'method' => ['nullable', Rule::in([
                Payment::METHOD_CASH_USD, Payment::METHOD_CASH_LBP,
                Payment::METHOD_WHISH, Payment::METHOD_OMT, Payment::METHOD_OTHER,
            ])],
            'period' => ['nullable', 'string', 'max:7'],
            'note' => ['nullable', 'string', 'max:255'],
            'collected_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $subscriber = $this->subscriber((int) $data['subscriber_id']);
        $period = $this->cycle($data['period'] ?? null);
        $currency = (string) ($data['currency'] ?? 'usd');
        $method = (string) ($data['method'] ?? ($currency === 'lbp' ? Payment::METHOD_CASH_LBP : Payment::METHOD_CASH_USD));

        $collectedOn = isset($data['collected_on'])
            ? Carbon::createFromFormat('Y-m-d', $data['collected_on'])->startOfDay()
            : now()->startOfDay();

        if ($collectedOn->greaterThan(now()->startOfDay())) {
            // Money cannot be collected tomorrow — an offline queue's clock is not the office's, and
            // a future receipt would land in a month that has not happened and never reconcile.
            throw new ApiRefusal('mobile.future_collected_on', __('mobile.future_collected_on'));
        }

        $amount = (float) $data['amount'];
        $rate = isset($data['rate']) ? (float) $data['rate'] : null;

        $payload = [
            'subscriber_id' => $subscriber->id,
            'period' => $period,
            'currency' => $currency,
            'amount' => $currency === 'lbp' ? (string) round($amount) : ApiPayload::dec($amount),
            'rate' => $rate,
            'method' => $method,
            'note' => $data['note'] ?? null,
            'collected_on' => $collectedOn->toDateString(),
        ];

        $attempt = ApiIdempotency::run(
            $request,
            $user,
            'collection',
            $payload,
            function () use ($subscriber, $currency, $amount, $rate, $user, $data, $period, $method, $collectedOn): array {
                $result = ApiRefusal::business(fn (): array => $currency === 'lbp'
                    ? BillingService::collectInLira(
                        $subscriber,
                        $amount,
                        $rate,
                        $user->id,
                        $data['note'] ?? null,
                        $period,
                        $method,
                        $collectedOn,
                    )
                    : BillingService::collect(
                        $subscriber,
                        $amount,
                        $user->id,
                        $data['note'] ?? null,
                        $period,
                        $method,
                        $collectedOn,
                    ));

                return ApiPayload::payment($result['payment'], $subscriber, $result);
            },
        );

        return response()->json($attempt['payload'] + ['replay' => $attempt['replay']], 201);
    }
}
