<?php

namespace App\Livewire\Collector;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\Currency;
use App\Services\WhatsAppService;
use Illuminate\Support\Collection;
use Livewire\Component;

class SubscriberShow extends Component
{
    public Subscriber $subscriber;

    public string $period;

    /** @var 'overview'|'reading'|'payment' */
    public string $view = 'overview';

    public ?string $readingInput = null;

    public ?string $paymentAmount = null;

    /** 'usd' | 'lbp' — which currency is in the collector's hand right now. */
    public string $paymentUnit = 'usd';

    /** The amount in lira, typed as handed over (never pre-converted by the collector's head). */
    public ?string $paymentLbp = null;

    /** The rate for a lira collection: the office's setting, overridable in the street. */
    public ?string $paymentRate = null;

    public string $paymentNote = '';

    /** How the money arrived: cash USD/LBP, Whish, OMT… */
    public string $paymentMethod = Payment::METHOD_CASH_USD;

    /** @var array{applied: float, excess: float, remaining: float, method: string, method_label: string, number: ?string, settled: array<int,array<string,mixed>>, bill_id: ?int, bill_period: ?string, lbp_given: ?float, lbp_applied: ?float, lbp_change: ?float, lbp_rate: ?float, wa_ar: ?string, wa_en: ?string}|null */
    public ?array $receipt = null;

    public ?int $lastPaymentId = null;

    /** Inline "the meter is dead" report the office picks up from the field. */
    public bool $showBrokenReport = false;

    public ?string $brokenNote = null;

    public ?string $flash = null;

    public string $flashType = 'success';

    public function mount(Subscriber $subscriber): void
    {
        $this->subscriber = $subscriber;
        $this->period = BillingService::currentPeriod();

        // Prefilled from Settings, and editable: the street rate and the office rate are not
        // always the same number on the same day, and the collector is the one holding the cash.
        $this->paymentRate = Currency::rate() > 0 ? (string) Currency::rate() : null;
    }

    // ---- read helpers --------------------------------------------------------

    /** This period's SUBSCRIPTION bill — a special invoice for the month is a different document. */
    private function subscriptionBillForPeriod(string $period): ?Bill
    {
        return $this->subscriber->bills()
            ->where('period', $period)
            ->where('type', '!=', Bill::TYPE_SPECIAL)
            ->first();
    }

    public function outstanding(): float
    {
        return $this->subscriber->outstanding();
    }

    public function price(): float
    {
        return BillingService::kwhPrice();
    }

    public function previousReadingValue(): float
    {
        $prev = BillingService::latestReadingBefore($this->subscriber, $this->period);

        return (float) ($prev?->current_reading ?? 0);
    }

    public function currentBill(): ?Bill
    {
        return $this->subscriptionBillForPeriod($this->period);
    }

    public function currentReadingDone(): bool
    {
        return $this->subscriber->readingFor($this->period) !== null;
    }

    public function recentBills(): Collection
    {
        return $this->subscriber->bills()
            ->with('reading:id,current_reading,previous_reading,consumption')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    public function recentPayments(): Collection
    {
        return $this->subscriber->payments()
            ->with('collector:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    public function waPlain(): ?string
    {
        return WhatsAppService::waLink($this->subscriber->phone, '');
    }

    /** Live bill preview while the collector types a reading. */
    public function readingPreview(): ?array
    {
        if ($this->currentReadingDone() || ! $this->subscriber->isMetered()) {
            return null;
        }
        // A reported-dead meter has no bill to preview: whatever is in the box cannot be billed,
        // and showing a figure next to a Save button would invite one.
        if ($this->subscriber->hasBrokenMeterReport()) {
            return null;
        }
        $input = trim((string) $this->readingInput);
        if ($input === '' || ! is_numeric($input)) {
            return null;
        }

        $prev = $this->previousReadingValue();
        $consumption = round((float) $input - $prev, 2);
        if ($consumption < 0) {
            return ['error' => __('portal.err_reading_lower')];
        }
        $usage = round($consumption * $this->price(), 2);
        $maintenance = (float) $this->subscriber->maintenance_fee;
        $charges = round($usage + $maintenance, 2);
        $debt = $this->outstanding(); // older unpaid bills (nothing exists for this period yet)

        return [
            'prev' => $prev,
            'consumption' => $consumption,
            'usage' => $usage,
            'maintenance' => $maintenance,
            'charges' => $charges,
            'debt' => $debt,
            'due_now' => round($charges + $debt, 2),
            'error' => null,
        ];
    }

    // ---- actions -------------------------------------------------------------

    public function switchView(string $view): void
    {
        if (in_array($view, ['overview', 'reading', 'payment'], true)) {
            $this->view = $view;
        }
    }

    public function saveReading(): void
    {
        $this->resetErrorBag();
        $this->flash = null;

        if (! $this->subscriber->isMetered()) {
            $this->addError('reading', __('portal.err_reading_flat'));

            return;
        }
        if ($this->subscriber->status !== Subscriber::STATUS_ACTIVE) {
            $this->addError('reading', __('portal.err_reading_inactive'));

            return;
        }
        if ($this->subscriber->hasBrokenMeterReport()) {
            // A reported-dead meter cannot be read: the office decides (estimate, replacement or a
            // flat rate), or the collector says the meter is working after all and closes the
            // report. Measured in the street: an input that accepts a number here is how a dead
            // meter gets read anyway, and the case then never looks open again.
            $this->addError('reading', __('portal.err_reading_broken'));

            return;
        }
        if ($this->currentReadingDone()) {
            $this->addError('reading', __('portal.err_reading_exists'));

            return;
        }

        $input = trim((string) $this->readingInput);
        if ($input === '' || ! is_numeric($input) || (float) $input < 0) {
            $this->addError('readingInput', __('portal.err_reading_input'));

            return;
        }

        try {
            BillingService::enterMeteredReading(
                $this->subscriber,
                $this->period,
                (float) $input,
                auth()->id(),
                null,
            );
            $this->subscriber->refresh();
            $this->readingInput = null;
            $this->flashMessage(__('portal.flash_reading_ok'));
            $this->view = 'reading';
        } catch (\RuntimeException $e) {
            $this->addError('reading', $e->getMessage());
        }
    }

    /** Field report: the meter is dead. The office decides (estimate, replace, or flat rate). */
    public function reportBrokenMeter(): void
    {
        $this->resetErrorBag();
        $this->flash = null;

        if (! $this->subscriber->isMetered()) {
            $this->addError('broken', __('portal.err_reading_flat'));

            return;
        }
        if ($this->subscriber->status !== Subscriber::STATUS_ACTIVE) {
            $this->addError('broken', __('portal.err_reading_inactive'));

            return;
        }
        if ($this->currentReadingDone()) {
            $this->addError('broken', __('portal.err_broken_after_reading'));

            return;
        }

        $this->subscriber->reportMeterBroken($this->brokenNote ?: null, auth()->id());
        $this->subscriber->refresh();
        $this->showBrokenReport = false;
        $this->brokenNote = null;
        $this->flashMessage(__('portal.broken_reported'), 'warning');
    }

    /** The office took care of it / the meter works again. */
    public function cancelBrokenReport(): void
    {
        $this->resetErrorBag();
        $this->subscriber->clearMeterReport();
        $this->subscriber->refresh();
        $this->flashMessage(__('portal.broken_cleared'));
    }

    public function toggleBrokenReport(): void
    {
        $this->showBrokenReport = ! $this->showBrokenReport;
        $this->resetErrorBag();
    }

    public function savePayment(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $this->flash = null;

        if ($this->subscriber->status !== Subscriber::STATUS_ACTIVE) {
            $this->addError('payment', __('portal.err_payment_inactive'));

            return;
        }

        $unit = $this->paymentUnit === 'lbp' ? 'lbp' : 'usd';
        $lbp = null;
        $rate = null;
        $amount = null;

        if ($unit === 'lbp') {
            // The collector types what is in his hand. He is not asked to divide anything, and the
            // rate he used is saved with the receipt so the figure can be checked later.
            $lbp = trim((string) $this->paymentLbp);
            $rate = (float) ($this->paymentRate ?? 0);

            if ($lbp === '' || ! is_numeric($lbp) || (float) $lbp <= 0) {
                $this->addError('paymentLbp', __('portal.err_lbp_input'));

                return;
            }

            if ($rate <= 0) {
                $this->addError('paymentRate', __('refuse.no_lbp_rate'));

                return;
            }
        } else {
            $amount = trim((string) $this->paymentAmount);
            if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
                $this->addError('paymentAmount', __('portal.err_payment_input'));

                return;
            }
        }

        try {
            $result = $unit === 'lbp'
                ? BillingService::collectInLira(
                    $this->subscriber,
                    (float) $lbp,
                    $rate,
                    auth()->id(),
                    $this->paymentNote !== '' ? $this->paymentNote : null,
                    $this->period,
                    $this->paymentMethod,
                )
                : BillingService::collect(
                    $this->subscriber,
                    (float) $amount,
                    auth()->id(),
                    $this->paymentNote !== '' ? $this->paymentNote : null,
                    $this->period,
                    $this->paymentMethod,
                );

            $this->subscriber->refresh();

            $bill = $result['bill']
                ?? $this->subscriptionBillForPeriod($this->period);

            $phone = $this->subscriber->phone;
            $messageAr = WhatsAppService::receiptText(
                $this->subscriber, $bill, $result['applied'], $result['remaining'], 'ar',
            );
            $messageEn = WhatsAppService::receiptText(
                $this->subscriber, $bill, $result['applied'], $result['remaining'], 'en',
            );

            $payment = $result['payment']->fresh();

            // Which invoices this money settled (the receipt must say so).
            $settled = $payment->settledBills()
                ->map(fn (Bill $b) => [
                    'number' => $b->number ?: 'INV-'.$b->period,
                    'period' => $b->period,
                    'amount' => (float) $payment->allocations->firstWhere('bill_id', $b->id)?->amount,
                ])
                ->values()
                ->all();

            // Why a balance is still owed after a full payment (the next cycle's bill).
            $remainingNote = null;
            if ($note = $payment->remainingNote()) {
                [$noteKey, $noteParams] = Payment::remainingNoteText($note, fn (string $period): string => period_label($period));
                $remainingNote = __($noteKey, $noteParams);
            }

            $this->receipt = [
                'applied' => $result['applied'],
                'excess' => $result['excess'],
                'remaining' => $result['remaining'],
                'remaining_note' => $remainingNote,
                'method' => $payment->method,
                'method_label' => $payment->methodLabel(),
                'number' => $payment->number,
                'settled' => $settled,
                'bill_id' => $bill?->id,
                'bill_period' => $bill?->period,
                // The lira this receipt took, when it was taken in lira: what was handed over, the
                // rate, and what came back as change. Null on a dollar receipt — not zero.
                'lbp_given' => $unit === 'lbp' ? $result['lbp_given'] : null,
                'lbp_applied' => $unit === 'lbp' ? $result['lbp_applied'] : null,
                'lbp_change' => $unit === 'lbp' ? $result['lbp_change'] : null,
                'lbp_rate' => $unit === 'lbp' ? $result['lbp_rate'] : null,
                'wa_ar' => WhatsAppService::waLink($phone, $messageAr),
                'wa_en' => WhatsAppService::waLink($phone, $messageEn),
            ];
            $this->lastPaymentId = $result['payment']->id;

            $this->paymentAmount = null;
            $this->paymentLbp = null;
            $this->paymentNote = '';
            $this->view = 'payment';

            if ($unit === 'lbp') {
                // Says both currencies: the lira he just took, and the ledger figure it became.
                $message = __('portal.flash_collected_lbp', [
                    'lbp' => 'LBP '.number_format((float) $result['lbp_applied'], 0, '.', ','),
                    'amount' => money($result['applied']),
                ]);

                if ((float) $result['lbp_change'] > 0) {
                    $message .= ' '.__('portal.flash_lbp_change', [
                        'change' => 'LBP '.number_format((float) $result['lbp_change'], 0, '.', ','),
                    ]);
                    $this->flashMessage($message, 'warning');
                } else {
                    $this->flashMessage($message);
                }
            } elseif ($result['excess'] > 0) {
                $this->flashMessage(__('portal.flash_collected', ['amount' => money($result['applied']), 'excess' => money($result['excess'])]), 'warning');
            } else {
                $this->flashMessage(__('portal.flash_payment_ok'));
            }
        } catch (\RuntimeException $e) {
            $message = $e->getMessage() === 'This subscriber has no outstanding balance to collect.'
                ? __('portal.err_payment_nothing')
                : $e->getMessage();
            $this->addError('payment', $message);
        }
    }

    public function setFullAmount(): void
    {
        $this->paymentAmount = number_format($this->outstanding(), 2, '.', '');
    }

    /**
     * Which currency is in his hand.
     *
     * Switching to lira also picks the matching payment method, because money handed over in lira
     * IS cash in lira — the one thing the old screen could not express. The collector can still
     * change the method afterwards (a lira collection by OMT is a real thing).
     */
    public function setPaymentUnit(string $unit): void
    {
        $this->paymentUnit = $unit === 'lbp' ? 'lbp' : 'usd';
        $this->resetErrorBag();

        if ($this->paymentUnit === 'lbp') {
            if ((float) ($this->paymentRate ?? 0) <= 0) {
                $this->paymentRate = Currency::rate() > 0 ? (string) Currency::rate() : null;
            }

            if ($this->paymentMethod === Payment::METHOD_CASH_USD) {
                $this->paymentMethod = Payment::METHOD_CASH_LBP;
            }
        } elseif ($this->paymentMethod === Payment::METHOD_CASH_LBP) {
            $this->paymentMethod = Payment::METHOD_CASH_USD;
        }
    }

    /**
     * What the lira in his hand is worth in the ledger, shown BEFORE he saves.
     *
     * Null when there is nothing to convert yet, so the hint appears only when it means something.
     */
    public function liraEquivalent(): ?float
    {
        $lbp = (float) ($this->paymentLbp ?? 0);
        $rate = (float) ($this->paymentRate ?? 0);

        return $lbp > 0 && $rate > 0 ? round($lbp / $rate, 2) : null;
    }

    /** The full balance, in lira — the same one-click shortcut the dollar path has. */
    public function setFullAmountLbp(): void
    {
        $rate = (float) ($this->paymentRate ?? 0) ?: Currency::rate();

        if ($rate > 0) {
            $this->paymentLbp = number_format(round($this->outstanding() * $rate), 0, '.', '');
        }
    }

    public function dismissFlash(): void
    {
        $this->flash = null;
    }

    public function clearReceipt(): void
    {
        $this->receipt = null;
        $this->lastPaymentId = null;
    }

    protected function flashMessage(string $message, string $type = 'success'): void
    {
        $this->flash = $message;
        $this->flashType = $type;
    }

    public function render()
    {
        $data = [
            'outstanding' => $this->outstanding(),
            'currentBill' => $this->currentBill(),
            'recentBills' => $this->recentBills(),
            'recentPayments' => $this->recentPayments(),
            'preview' => $this->readingPreview(),
            'prevReading' => $this->previousReadingValue(),
            'price' => $this->price(),
        ];

        return view('livewire.collector.subscriber-show', $data);
    }
}
