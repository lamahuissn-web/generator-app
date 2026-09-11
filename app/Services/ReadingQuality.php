<?php

namespace App\Services;

use App\Models\Subscriber;

/**
 * Is this reading believable?
 *
 * Two screens ask the same question from opposite ends: the collector's worklist warns while
 * the value is still in the box, and the office's board flags readings that have already been
 * billed. The rule lives here so the two can never drift into disagreeing about what "a jump"
 * is — a drift nobody would notice until the field and the office were reading different
 * numbers off the same subscriber.
 *
 * Neither verdict refuses anything. A house that bought an air conditioner reads far above
 * its own average and is telling the truth; the signal is for a human to look at, and only
 * the two impossible cases (a value below the last one, a reading already recorded) are
 * refused — those are refused in BillingService, where money is at stake.
 */
final class ReadingQuality
{
    /** No consumption at all. */
    public const ZERO = 'zero';

    /** Far above this house's own average. */
    public const JUMP = 'jump';

    /**
     * How many times its own average a month can run before it is worth a second look.
     *
     * Three is chosen against the data this app holds: a household that adds one heavy
     * appliance roughly doubles, so a genuine month sits well under 3x; a stuck meter, a
     * misread digit or a shared line lands far above it.
     */
    public const JUMP_FACTOR = 3.0;

    /** This house's own recent average (last few real readings), or null without usable history. */
    public static function average(Subscriber $subscriber, string $period, int $sample = 3): ?float
    {
        $suggested = BillingService::suggestedConsumption($subscriber, $period, $sample);

        return $suggested === null ? null : (float) $suggested['kwh'];
    }

    /**
     * The verdict on a measured consumption, or null when it looks ordinary.
     *
     * @return self::ZERO|self::JUMP|null
     */
    public static function verdict(Subscriber $subscriber, string $period, float $consumption): ?string
    {
        if ($consumption <= 0) {
            return self::ZERO;
        }

        $average = self::average($subscriber, $period);

        if ($average !== null && $average > 0 && $consumption > $average * self::JUMP_FACTOR) {
            return self::JUMP;
        }

        return null;
    }

    /** The chip wording for a verdict — one phrasing, both screens. */
    public static function label(string $verdict, ?float $average = null): string
    {
        return match ($verdict) {
            self::ZERO => __('rdg.quality_zero'),
            self::JUMP => __('rdg.quality_jump', ['avg' => number_format((float) $average, 0)]),
            default => '',
        };
    }
}
