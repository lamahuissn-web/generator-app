<?php

namespace App\Livewire\Collector;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\WhatsAppService;
use Livewire\Component;

class SubscriberShow extends Component
{
    public Subscriber $subscriber;

    public string $period;

    /** @var 'overview'|'reading'|'payment' */
    public string $view = 'overview';

    public ?string $readingInput = null;

    public ?string $paymentAmount = null;

    public string $paymentNote = '';

    /** @var array{applied: float, excess: float, remaining: float, bill_id: ?int, bill_period: ?string}|null */
    public ?array $receipt = null;

    public ?int $lastPaymentId = null;

    public ?string $flash = null;

    public string $flashType = 'success';

    public function mount(Subscriber $subscriber): void
    {
        $this->subscriber = $subscriber;
        $this->period = BillingService::currentPeriod();
    }

    // ---- read helpers --------------------------------------------------------

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
        return $this->subscriber->bills()->where('period', $this->period)->first();
    }

    public function currentReadingDone(): bool
    {
        return $this->subscriber->readingFor($this->period) !== null;
    }

    public function recentBills(): \Illuminate\Support\Collection
    {
        return $this->subscriber->bills()
            ->with('reading:id,current_reading,previous_reading,consumption')
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(6)
            ->get();
    }

    public function recentPayments(): \Illuminate\Support\Collection
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
        $input = trim((string) $this->readingInput);
        if ($input === '' || ! is_numeric($input)) {
            return null;
        }

        $prev = $this->previousReadingValue();
        $consumption = round((float) $input - $prev, 2);
        if ($consumption < 0) {
            return ['error' => 'القراءة الجديدة أقل من القراءة السابقة ('.$prev.')'];
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
            $this->addError('reading', 'هذا المشترك بنظام الاشتراك الثابت — لا قراءة عداد.');

            return;
        }
        if ($this->subscriber->status !== Subscriber::STATUS_ACTIVE) {
            $this->addError('reading', 'المشترك موقوف — لا يمكن تسجيل قراءة.');

            return;
        }
        if ($this->currentReadingDone()) {
            $this->addError('reading', 'تم تسجيل قراءة هذا الشهر مسبقاً.');

            return;
        }

        $input = trim((string) $this->readingInput);
        if ($input === '' || ! is_numeric($input) || (float) $input < 0) {
            $this->addError('readingInput', 'أدخل قراءة صحيحة.');

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
            $this->flashMessage('Reading recorded and bill generated ✅');
            $this->view = 'reading';
        } catch (\RuntimeException $e) {
            $this->addError('reading', $e->getMessage());
        }
    }

    public function savePayment(): void
    {
        $this->resetErrorBag();
        $this->receipt = null;
        $this->flash = null;

        if ($this->subscriber->status !== Subscriber::STATUS_ACTIVE) {
            $this->addError('payment', 'المشترك موقوف — لا يمكن تحصيل.');

            return;
        }

        $amount = trim((string) $this->paymentAmount);
        if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
            $this->addError('paymentAmount', 'أدخل مبلغاً صحيحاً.');

            return;
        }

        try {
            $result = BillingService::collect(
                $this->subscriber,
                (float) $amount,
                auth()->id(),
                $this->paymentNote !== '' ? $this->paymentNote : null,
                $this->period,
            );

            $this->subscriber->refresh();

            $bill = $result['bill']
                ?? $this->subscriber->bills()->where('period', $this->period)->first();

            $phone = $this->subscriber->phone;
            $messageAr = WhatsAppService::receiptText(
                $this->subscriber, $bill, $result['applied'], $result['remaining'], 'ar',
            );
            $messageEn = WhatsAppService::receiptText(
                $this->subscriber, $bill, $result['applied'], $result['remaining'], 'en',
            );

            $this->receipt = [
                'applied' => $result['applied'],
                'excess' => $result['excess'],
                'remaining' => $result['remaining'],
                'bill_id' => $bill?->id,
                'bill_period' => $bill?->period,
                'wa_ar' => WhatsAppService::waLink($phone, $messageAr),
                'wa_en' => WhatsAppService::waLink($phone, $messageEn),
            ];
            $this->lastPaymentId = $result['payment']->id;

            $this->paymentAmount = null;
            $this->paymentNote = '';
            $this->view = 'payment';

            if ($result['excess'] > 0) {
                $this->flashMessage('Collected '.money($result['applied']).' — excess '.money($result['excess']).' not applied (over the balance).', 'warning');
            } else {
                $this->flashMessage('Payment recorded ✅');
            }
        } catch (\RuntimeException $e) {
            $this->addError('payment', $e->getMessage());
        }
    }

    public function setFullAmount(): void
    {
        $this->paymentAmount = number_format($this->outstanding(), 2, '.', '');
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
