<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    /** A charge that is not part of the monthly subscription (part, meter, labour). */
    public const TYPE_SPECIAL = 'special';

    protected $fillable = [
        'number', 'subscriber_id', 'period', 'type', 'title', 'note', 'reading_id', 'consumption', 'kwh_price',
        'usage_amount', 'maintenance_fee', 'flat_amount', 'items_amount', 'prev_debt', 'credit_applied', 'credit_created', 'subsidy_amount',
        'discount_amount', 'discount_note', 'discount_category', 'discount_by', 'discount_at', 'total', 'paid_amount',
    ];

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function reading(): BelongsTo
    {
        return $this->belongsTo(Reading::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class)->orderBy('sort')->orderBy('id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function isSpecial(): bool
    {
        return $this->type === self::TYPE_SPECIAL;
    }

    /**
     * The invoice's own addition (it lines up: usage + fees − the credit he carried − what the office
     * waived = the total below it).
     *
     * The charge line carries the office's rounding; the credit the subscriber brought with him, the
     * standing subsidy and the one-cycle discount are lines of their own (see Bill::credit_applied,
     * Bill::subsidy_amount and Bill::discount_amount), and all three come OFF here so the printed invoice
     * adds up on its own terms and `charges()` never disagrees with `total`.
     */
    /**
     * An invoice that states its own breakdown cannot contradict it.
     *
     * Every legitimate writer satisfies this — the service, the run, a screen filling the charge lines — so
     * the guard refuses nothing in normal use. What it stops is the other kind of write: a bare total typed
     * onto an invoice that already says what it is made of, which is how money the office never charged
     * would appear on the books (an invoice written as a total alone carries no breakdown to check, and is
     * left alone — the tests' fixtures and any legacy row).
     */
    protected static function booted(): void
    {
        static::saving(function (Bill $bill): void {
            $charge = $bill->charges();

            if ($charge <= 0 || (float) $bill->total <= 0) {
                return;
            }

            if (abs($charge - (float) $bill->total) > 0.0001) {
                throw new \RuntimeException(__('refuse.bill_out_of_balance', [
                    'charge' => money_ltr($charge),
                    'total' => money_ltr((float) $bill->total),
                ]));
            }
        });
    }

    public function charges(): float
    {
        return round((float) $this->usage_amount + (float) $this->maintenance_fee
            + (float) $this->flat_amount + (float) $this->items_amount
            - (float) $this->credit_applied - (float) $this->subsidy_amount - (float) $this->discount_amount, 2);
    }

    public function unpaidAmount(): float
    {
        return max(0, (float) $this->total - (float) $this->paid_amount);
    }

    /** paid | partial | unpaid */
    public function paymentStatus(): string
    {
        $unpaid = $this->unpaidAmount();

        return $unpaid <= 0 ? 'paid' : ((float) $this->paid_amount > 0 ? 'partial' : 'unpaid');
    }
}
