<?php

namespace App\Services;

/**
 * When a cycle stops being the current work and becomes history.
 *
 * A generator network runs on a month: the readings arrive, the invoices go out, the collectors
 * bring the cash in — and then the month is done and nobody looks at it again. Until now nothing in
 * the app said so: a reading from any past cycle could be deleted exactly as easily as one entered
 * this morning, and the only trace was an audit line nobody reads. Twelve months of a subscriber's
 * history were one stray click deep, on a list where the delete button sits beside the row.
 *
 * The rule is deliberately the simplest one an operator can hold in their head: anything older than
 * the cycle you are working in is CLOSED. Closing is not a wall — a closed cycle can still be
 * corrected, because mistakes exist and paper slips arrive late — it is a gate that requires a
 * written reason, records who gave it, and keeps what was there in the audit trail.
 *
 * What it protects is what EXISTS. Filling a house that was never read in a past month is not a
 * correction of anything (there is no figure to undo), so it stays an ordinary action.
 */
final class CycleLock
{
    /** Is $period a cycle that has already been worked and closed? */
    public static function isClosed(?string $period): bool
    {
        if ($period === null || ! preg_match(BillingService::PERIOD_REGEX, $period)) {
            return false;
        }

        // String comparison is safe on the 'Y-m' shape: it orders exactly as the calendar does.
        return $period < BillingService::currentPeriod();
    }

    /** Everyday word for it in the UI: 'دورة مقفلة' / 'closed cycle'. */
    public static function label(bool $closed): string
    {
        return $closed ? __('t.cycle_closed') : __('t.cycle_open');
    }

    /**
     * May this user correct a closed cycle, given this reason?
     *
     * Returns the refusal message, or null when the correction may proceed. One place decides, so
     * the table, the modal and any future screen refuse in the same words.
     */
    public static function refusal(?string $period, ?string $reason, ?\App\Models\User $user): ?string
    {
        if (! self::isClosed($period)) {
            return null;
        }

        if (! ($user?->isAdmin() ?? false)) {
            return __('refuse.closed_cycle_admin', ['period' => period_label_i18n((string) $period)]);
        }

        if (trim((string) $reason) === '') {
            return __('refuse.closed_cycle_reason', ['period' => period_label_i18n((string) $period)]);
        }

        return null;
    }
}
