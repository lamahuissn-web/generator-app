<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Services\BillingService;

/**
 * What every mobile endpoint shares: which cycle the phone is asking about.
 *
 * The phone states the month it is working on (`2026-10`) and the server cleans it the same way the
 * panel does — `2026/10` and `2026-9` are how a person types a month, and a month that cannot be
 * read is refused by name instead of being compared against rows it can never match.
 */
abstract class ApiController extends Controller
{
    protected function cycle(?string $period): string
    {
        if (blank($period)) {
            return BillingService::currentPeriod();
        }

        return BillingService::cleanPeriod($period)
            ?? throw new ApiRefusal('refuse.period_format', __('refuse.period_format', ['period' => $period]));
    }

    protected function subscriber(int $id): Subscriber
    {
        return Subscriber::query()->findOrFail($id);
    }
}
