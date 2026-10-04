<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscriber;

/**
 * The receipt as words, for the phone a household already has.
 *
 * The printed receipt (`print.partials.receipt-body`) is the document of record; this is the same
 * document in the form a collector can hand on from his pocket — a message. It reads the same pieces:
 * the office's own labels (`prt.*`), the office's money renderer (`money()`), the cycle's own month
 * name, and the amount above the balance that waits on the household's account. Nothing is recomputed
 * and nothing is re-worded here, so a corrected label or a corrected month name is corrected in both
 * places at once.
 *
 * Two details are deliberate:
 *
 *  - **a lira receipt names the lira.** The ledger stores dollars; the household handed over lira. A
 *    receipt that mentions only the dollars it settled is one they cannot check against what left
 *    their hand, so the lira and the rate that were given travel with it.
 *  - **Latin and numeric runs are isolated** (`ltr_isolate`) exactly as the paper does it, because
 *    this text is going into an Arabic message where `$60.00` would otherwise be re-ordered against
 *    the sentence around it.
 */
class ReceiptText
{
    public static function for(Payment $payment, Subscriber $subscriber): string
    {
        $payment->loadMissing(['collector', 'allocations.bill']);

        $company = PdfService::company();
        $remaining = (float) $subscriber->outstanding();
        $settled = $remaining <= 0;
        $isRtl = app()->isLocale('ar');

        $month = fn (string $period): string => $isRtl ? period_label_ar($period) : period_label($period);

        $lines = [$company['name']];

        if ($company['phone'] !== '') {
            $lines[] = __('prt.phone').': '.ltr_isolate($company['phone']);
        }

        $lines[] = __('prt.receipt_title').' · '.__('prt.receipt_no').' '.ltr_isolate((string) ($payment->number ?: '#'.$payment->id));
        $lines[] = '';
        $lines[] = __('prt.date').': '.ltr_isolate($payment->collectedOn()->format('Y-m-d'));
        $lines[] = __('prt.subscriber').': '.$subscriber->name;
        $lines[] = __('prt.route').': '.($subscriber->route?->name ?? '—');
        $lines[] = __('prt.collector').': '.($payment->collector?->name ?? '—');
        $lines[] = '';
        $lines[] = __('prt.amount_received').': '.money_ltr($payment->amount).' · '.$payment->methodLabel();

        if ($payment->lbp_amount !== null) {
            $lines[] = __('prt.lbp_received', [
                'lbp' => ltr_isolate(number_format((float) $payment->lbp_amount, 0, '.', ',').' ل.ل.'),
            ]);

            if ($payment->lbp_rate !== null && (float) $payment->lbp_rate > 0) {
                $lines[] = __('prt.lbp_rate_line', [
                    'rate' => ltr_isolate(number_format((float) $payment->lbp_rate, 0, '.', ',')),
                ]);
            }
        }

        if ($payment->allocations->isNotEmpty()) {
            $lines[] = '';
            $lines[] = __('prt.allocation_title').':';

            foreach ($payment->allocations as $allocation) {
                $period = $allocation->bill?->period;
                $lines[] = '• '.($period === null ? '—' : $month((string) $period))
                    .' · '.money_ltr($allocation->amount)
                    .' ('.ltr_isolate((string) ($allocation->bill?->number ?: '—')).')';
            }
        }

        $lines[] = '';
        $lines[] = ($settled ? __('prt.settled') : __('prt.remaining_balance')).': '.money_ltr($remaining);

        if (($note = $payment->remainingNote()) !== null) {
            [$key, $params] = Payment::remainingNoteText($note, $month);
            $lines[] = __($key, $params);
        }

        if ($payment->note) {
            $lines[] = $payment->note;
        }

        $lines[] = '';
        $lines[] = __('prt.thanks');

        return implode("\n", $lines);
    }
}
