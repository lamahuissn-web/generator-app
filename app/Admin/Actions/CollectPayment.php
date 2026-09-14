<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use App\Services\Currency;
use Illuminate\Database\Eloquent\Model;

/**
 * Take a cash payment from a subscriber.
 *
 * The amount defaults to the full balance, and the service decides how the money is
 * allocated (FIFO across unpaid bills) — this screen only collects the number.
 */
class CollectPayment extends ActionDefinition
{
    public function subjectModel(): string
    {
        return Subscriber::class;
    }

    public function label(): string
    {
        return __('a.collect');
    }

    /**
     * ki-dollar, NOT ki-wallet: the payments TAB on the same screen carries ki-wallet (as does
     * the sidebar's payments item), so a wallet here painted the same glyph twice, 70px apart,
     * meaning "take money" and "list of money taken". The tab keeps ki-wallet to stay identical
     * to the sidebar; the action takes the glyph that reads as money coming in.
     */
    public function icon(): ?string
    {
        return 'ki-dollar';
    }

    public function triggerClass(): string
    {
        return '';
    }

    public function modalHeading(Model $subject): string
    {
        return __('a.collect_for', ['name' => $subject->name]);
    }

    public function modalDescription(): ?string
    {
        $description = __('a.collect_desc');

        // A stopped account can still owe (principle VI), and the operator taking the money should
        // be told which one they are taking it from: a rule nobody sees being applied is a rule
        // nobody remembers. The ordinary subscriber's modal says exactly what it said before.
        if ($this->subject instanceof Subscriber && $this->subject->status !== Subscriber::STATUS_ACTIVE) {
            $description .= ' '.__('a.collect_inactive_notice');
        }

        return $description;
    }

    public function submitLabel(): string
    {
        return __('a.record_collection');
    }

    /**
     * Is there money to take? That is the whole question.
     *
     * A stopped subscriber can still owe, and the debt has nothing to do with whether the meter
     * runs today: refusing the collection does not protect anything, it just makes the invoice
     * uncollectable while it sits in the receivables. So the status does not appear here. It
     * governs SERVICE — the readings and the next invoice — which is why RecordReading still asks
     * it, and why SpecialInvoice (a NEW charge) does too. The status is shown to the operator
     * instead, on the modal itself, so taking an old debt from a stopped account is a decision
     * rather than an accident. See principle VI.
     */
    public function visible(Model $subject): bool
    {
        return $subject->outstanding() > 0;
    }

    public function sections(): array
    {
        return [[
            'title' => '',
            'fields' => [
                [
                    // Money in the street arrives in lira as often as in dollars, and the office
                    // taking a payment over the phone is handed either. The switch decides which
                    // amount below is asked for — not both, so neither can be left blank.
                    'key' => 'unit',
                    'label' => __('a.collect_unit'),
                    'type' => 'select',
                    'default' => 'usd',
                    'options' => [
                        'usd' => __('portal.pay_unit_usd'),
                        'lbp' => __('portal.pay_unit_lbp'),
                    ],
                    'rules' => ['required'],
                ],
                [
                    'key' => 'amount',
                    'label' => __('t.amount_received'),
                    'type' => 'money',
                    'default' => 0,
                    'span' => 'full',
                    'helper' => __('a.collect_helper'),
                    'visible_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') !== 'lbp',
                    'required_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') !== 'lbp',
                    'rules' => ['numeric', 'min:0.01'],
                ],
                [
                    'key' => 'lbp',
                    'label' => __('portal.amount_lbp'),
                    'type' => 'number',
                    'span' => 'full',
                    'helper' => __('a.collect_lbp_helper'),
                    'visible_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') === 'lbp',
                    'required_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') === 'lbp',
                    'rules' => ['numeric', 'min:1'],
                ],
                [
                    'key' => 'rate',
                    'label' => __('portal.rate_label'),
                    'type' => 'number',
                    'helper' => __('a.rate_helper'),
                    'visible_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') === 'lbp',
                    'required_when' => fn (array $state): bool => ($state['unit'] ?? 'usd') === 'lbp',
                    'rules' => ['numeric', 'min:1'],
                ],
                [
                    'key' => 'method',
                    'label' => __('t.payment_method'),
                    'type' => 'select',
                    'default' => Payment::METHOD_CASH_USD,
                    'options_from' => fn (): array => Payment::methodOptions(),
                    'rules' => ['required'],
                ],
                [
                    'key' => 'note',
                    'label' => __('t.note'),
                    'rules' => ['nullable', 'string', 'max:200'],
                ],
            ],
        ]];
    }

    /**
     * The amount a collector normally takes: the whole outstanding balance. Prefilled so the
     * common case is one click, still editable for a part payment — and prefilled in LIRA too, so
     * switching the unit does not leave the office doing the multiplication by hand.
     */
    public function defaults(?Model $subject = null): array
    {
        $outstanding = $subject instanceof Subscriber ? $subject->outstanding() : null;
        $rate = Currency::rate();

        return [
            'unit' => 'usd',
            'amount' => $outstanding === null ? null : number_format($outstanding, 2, '.', ''),
            'lbp' => $outstanding === null || $rate <= 0 ? null : number_format(round($outstanding * $rate), 0, '.', ''),
            'rate' => $rate > 0 ? rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.') : null,
            'method' => Payment::METHOD_CASH_USD,
            'note' => null,
        ];
    }

    public function handle(array $state, Model $subject): array
    {
        /** @var Subscriber $subject */
        if (($state['unit'] ?? 'usd') === 'lbp') {
            $rate = (float) ($state['rate'] ?? 0);

            $result = BillingService::collectInLira(
                $subject,
                (float) $state['lbp'],
                $rate > 0 ? $rate : null,
                auth()->id(),
                $state['note'] ?: null,
                null,
                $state['method'] ?? Payment::METHOD_CASH_LBP,
            );

            $message = __('a.collect_lbp_ok', [
                'lbp' => 'LBP '.number_format((float) $result['lbp_applied'], 0, '.', ','),
                'amount' => money_ltr($result['applied']),
                'remaining' => money_ltr($result['remaining']),
            ]);

            if ((float) $result['lbp_change'] > 0) {
                $message .= ' '.__('a.collect_lbp_change', [
                    'change' => 'LBP '.number_format((float) $result['lbp_change'], 0, '.', ','),
                ]);
            }

            return ['ok' => true, 'message' => $message];
        }

        $result = BillingService::collect(
            $subject,
            (float) $state['amount'],
            auth()->id(),
            $state['note'] ?: null,
            null,
            $state['method'] ?? Payment::METHOD_CASH_USD,
        );

        $message = __('a.collect_ok', [
            'amount' => money_ltr($result['applied']),
            'remaining' => money_ltr($result['remaining']),
            'method' => $result['payment']->methodLabel(),
        ]);

        if (($result['excess'] ?? 0) > 0) {
            $message .= ' '. __('a.collect_excess', ['excess' => money_ltr($result['excess'])]);
        }

        return ['ok' => true, 'message' => $message];
    }
}
