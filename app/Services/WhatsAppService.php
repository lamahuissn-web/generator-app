<?php

namespace App\Services;

use App\Models\Subscriber;

class WhatsAppService
{
    /**
     * Normalize a Lebanese phone number to international format for wa.me.
     * Accepts 03/70/71/76/78/79/81-style local numbers with any formatting.
     */
    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '961')) {
            return strlen($digits) >= 10 && strlen($digits) <= 13 ? $digits : null;
        }
        if (str_starts_with($digits, '0')) {
            $digits = '961'.substr($digits, 1); // 03 XXXXXX → 9613XXXXXX
        } elseif (strlen($digits) === 8) {
            $digits = '961'.$digits; // 70 555123 → 96170555123
        } else {
            return null;
        }

        return strlen($digits) >= 10 && strlen($digits) <= 13 ? $digits : null;
    }

    public static function waLink(?string $phone, string $text): ?string
    {
        $normalized = self::normalizePhone($phone);
        if (! $normalized) {
            return null;
        }

        return "https://wa.me/{$normalized}?text=".rawurlencode($text);
    }

    /**
     * Receipt message for a bill + optional payment outcome.
     *
     * @param  float|null  $paid  amount paid in this transaction (if any)
     * @param  float|null  $remaining  outstanding balance right after this transaction
     * @param  float|null  $previousDebt  rolled-over debt shown on the bill
     */
    public static function receiptText(
        Subscriber $subscriber,
        ?object $bill,
        ?float $paid,
        ?float $remaining,
        string $locale = 'ar',
        ?float $previousDebt = null,
    ): string {
        $amountBefore = ($paid ?? 0) + ($remaining ?? 0);

        if ($locale === 'en') {
            $lines = [];
            $lines[] = 'ELECTRICITY GENERATOR BILL';
            $lines[] = '';
            $lines[] = "Subscriber: {$subscriber->name}";
            if ($subscriber->meter_no) {
                $lines[] = "Meter: {$subscriber->meter_no}";
            }
            if ($bill) {
                $lines[] = 'Period: '.period_label($bill->period);
                if ($bill->type === Subscriber::TYPE_METERED) {
                    $lines[] = "Previous reading: ".number_format((float) ($bill->reading?->previous_reading ?? $bill->consumption ?? 0), 0);
                    $lines[] = "Current reading: ".number_format((float) ($bill->reading?->current_reading ?? $bill->consumption ?? 0), 0);
                    $lines[] = 'Consumption: '.number_format((float) $bill->consumption, 1).' kWh';
                    $lines[] = 'Usage: '.money($bill->usage_amount);
                    if ((float) $bill->maintenance_fee > 0) {
                        $lines[] = 'Maintenance: '.money($bill->maintenance_fee);
                    }
                } else {
                    $lines[] = 'Flat fee: '.money($bill->flat_amount);
                }
            }
            $lines[] = '';
            $lines[] = 'Due now: '.money($amountBefore);
            if ($paid !== null) {
                $lines[] = 'Paid: '.money($paid);
                $lines[] = 'Balance after payment: '.money($remaining ?? 0);
            }
            $lines[] = '';
            $lines[] = 'Thank you!';

            return implode("\n", $lines);
        }

        // Arabic default
        $lines = [];
        $lines[] = 'فاتورة اشتراك مولد كهرباء';
        $lines[] = '';
        $lines[] = 'المشترك: '.$subscriber->name;
        if ($subscriber->meter_no) {
            $lines[] = 'العداد: '.$subscriber->meter_no;
        }
        if ($bill) {
            $lines[] = 'الفترة: '.period_label_ar($bill->period);
            if ($bill->type === Subscriber::TYPE_METERED) {
                $reading = $bill->reading;
                $prev = number_format((float) ($reading?->previous_reading ?? 0), 0);
                $cur = number_format((float) ($reading?->current_reading ?? 0), 0);
                $lines[] = 'القراءة السابقة: '.$prev;
                $lines[] = 'القراءة الحالية: '.$cur;
                $lines[] = 'الاستهلاك: '.number_format((float) $bill->consumption, 1).' ك.و.س';
                $lines[] = 'قيمة الاستهلاك: '.money($bill->usage_amount);
                if ((float) $bill->maintenance_fee > 0) {
                    $ampere = $subscriber->amperage ? " ({$subscriber->amperage} أمبير)" : '';
                    $lines[] = 'رسوم الصيانة'.$ampere.': '.money($bill->maintenance_fee);
                }
            } else {
                $lines[] = 'الاشتراك الثابت: '.money($bill->flat_amount);
            }
            if ((float) ($previousDebt ?? $bill->prev_debt) > 0) {
                $lines[] = 'ديون سابقة: '.money($previousDebt ?? $bill->prev_debt);
            }
        }
        $lines[] = '';
        $lines[] = 'المطلوب حالياً: '.money($amountBefore);
        if ($paid !== null) {
            $lines[] = 'المدفوع اليوم: '.money($paid);
            $lines[] = 'الباقي بعد الدفع: '.money($remaining ?? 0);
        }
        $lines[] = '';
        $lines[] = 'شكراً لثقتكم';

        return implode("\n", $lines);
    }
}
