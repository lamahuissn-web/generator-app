<?php

namespace App\Admin;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\CashHandover;
use App\Models\EquipmentService;
use App\Models\Refuelling;
use App\Models\Reading;
use App\Models\Subscriber;
use App\Models\User;

/**
 * قارئ سجل التدقيق — one audit row, told in the office's own language.
 *
 * The trail already records everything (29 actions across readings, bills, money, subscribers, devices,
 * expenses, handovers, imports and users); what it never had was a reader. This class is that reader, and it
 * is deliberately the ONLY place that knows how a payload is worded: the list, the detail modal and any
 * later screen (a subscriber's own history, a monthly summary) all ask this class, so «حذف تعبئة: ٢٠٠ لتر»
 * is worded once and cannot drift between two screens.
 *
 * Three rules it exists to keep:
 *  - **nothing is written here** — reading a trail must never add to it, and no payload is rewritten;
 *  - **a value is never truncated away** — `facts()` returns every key, and a nested block is rendered as
 *    `key: value` pairs rather than a JSON blob an operator cannot read;
 *  - **an unknown action still reads** — a caller that logs something new gets its own key as the title and
 *    its payload as labelled pairs, never an exception and never an empty row.
 */
final class AuditNarrative
{
    public const FAMILY_READINGS = 'readings';
    public const FAMILY_BILLS = 'bills';
    public const FAMILY_MONEY = 'money';
    public const FAMILY_SUBSCRIBERS = 'subscribers';
    public const FAMILY_DEVICES = 'devices';
    public const FAMILY_EXPENSES = 'expenses';
    public const FAMILY_HANDOVERS = 'handovers';
    public const FAMILY_IMPORTS = 'imports';
    public const FAMILY_USERS = 'users';

    /** The families as the filter shows them, in the office's order of interest. */
    public const FAMILIES = [
        self::FAMILY_MONEY,
        self::FAMILY_READINGS,
        self::FAMILY_BILLS,
        self::FAMILY_SUBSCRIBERS,
        self::FAMILY_DEVICES,
        self::FAMILY_EXPENSES,
        self::FAMILY_HANDOVERS,
        self::FAMILY_IMPORTS,
        self::FAMILY_USERS,
    ];

    /**
     * Every recorded action: its title key, its family, and what the row's money figure means.
     *
     * `money` names the payload key that carries the amount this row moved (null = no money in this act), so
     * the list can show a «المبلغ» column without inventing one for rows that have none.
     *
     * @var array<string, array{title: string, family: string, money: ?string}>
     */
    private const ACTIONS = [
        'reading.created' => ['title' => 'aud.t_reading_created', 'family' => self::FAMILY_READINGS, 'money' => 'total'],
        'reading.deleted' => ['title' => 'aud.t_reading_deleted', 'family' => self::FAMILY_READINGS, 'money' => 'bill_total'],
        'reading.baseline' => ['title' => 'aud.t_reading_baseline', 'family' => self::FAMILY_READINGS, 'money' => null],
        'reading.imported' => ['title' => 'aud.t_reading_imported', 'family' => self::FAMILY_IMPORTS, 'money' => null],
        'bill.generated' => ['title' => 'aud.t_bill_generated', 'family' => self::FAMILY_BILLS, 'money' => 'new_total'],
        'bill.deleted' => ['title' => 'aud.t_bill_deleted', 'family' => self::FAMILY_BILLS, 'money' => 'bill_total'],
        'bill.special_created' => ['title' => 'aud.t_bill_special_created', 'family' => self::FAMILY_BILLS, 'money' => 'total'],
        'bill.special_deleted' => ['title' => 'aud.t_bill_special_deleted', 'family' => self::FAMILY_BILLS, 'money' => 'total'],
        'bill.discounted' => ['title' => 'aud.t_bill_discounted', 'family' => self::FAMILY_BILLS, 'money' => 'discount'],
        'payment.created' => ['title' => 'aud.t_payment_created', 'family' => self::FAMILY_MONEY, 'money' => 'cash'],
        'payment.reversed' => ['title' => 'aud.t_payment_reversed', 'family' => self::FAMILY_MONEY, 'money' => 'amount'],
        'subscriber.exempted' => ['title' => 'aud.t_subscriber_exempted', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => null],
        'subscriber.exemption_removed' => ['title' => 'aud.t_subscriber_exemption_removed', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => null],
        'subscriber.converted_to_flat' => ['title' => 'aud.t_subscriber_converted_to_flat', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => 'flat_fee'],
        'subscriber.converted_to_metered' => ['title' => 'aud.t_subscriber_converted_to_metered', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => null],
        'subscriber.meter_reported_broken' => ['title' => 'aud.t_subscriber_meter_broken', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => null],
        'subscriber.deleted' => ['title' => 'aud.t_subscriber_deleted', 'family' => self::FAMILY_SUBSCRIBERS, 'money' => null],
        'subscriber.imported' => ['title' => 'aud.t_subscriber_imported', 'family' => self::FAMILY_IMPORTS, 'money' => null],
        'refuelling.corrected' => ['title' => 'aud.t_refuelling_corrected', 'family' => self::FAMILY_DEVICES, 'money' => null],
        'refuelling.removed' => ['title' => 'aud.t_refuelling_removed', 'family' => self::FAMILY_DEVICES, 'money' => null],
        'service.corrected' => ['title' => 'aud.t_service_corrected', 'family' => self::FAMILY_DEVICES, 'money' => 'cost'],
        'service.removed' => ['title' => 'aud.t_service_removed', 'family' => self::FAMILY_DEVICES, 'money' => 'cost'],
        'expense.created' => ['title' => 'aud.t_expense_created', 'family' => self::FAMILY_EXPENSES, 'money' => 'amount'],
        'expense.updated' => ['title' => 'aud.t_expense_updated', 'family' => self::FAMILY_EXPENSES, 'money' => 'amount'],
        'expense.deleted' => ['title' => 'aud.t_expense_deleted', 'family' => self::FAMILY_EXPENSES, 'money' => 'amount'],
        'handover.created' => ['title' => 'aud.t_handover_created', 'family' => self::FAMILY_HANDOVERS, 'money' => 'amount'],
        'handover.updated' => ['title' => 'aud.t_handover_updated', 'family' => self::FAMILY_HANDOVERS, 'money' => 'amount'],
        'handover.deleted' => ['title' => 'aud.t_handover_deleted', 'family' => self::FAMILY_HANDOVERS, 'money' => 'amount'],
        'user.deleted' => ['title' => 'aud.t_user_deleted', 'family' => self::FAMILY_USERS, 'money' => null],
    ];

    /** Payload keys whose Arabic label the office already has somewhere else in the app. */
    private const FIELD_KEYS = [
        'date' => 't.date',
        'period' => 't.month',
        'amount' => 't.amount',
        'total' => 't.total',
        'reason' => 't.reason',
        'notes' => 't.note',
        'note' => 't.note',
        'name' => 't.name',
        'kind' => 'eq.service_kind',
        'litres' => 'eq.litres',
        'price_per_litre' => 'eq.price_per_litre',
        'engine_hours' => 'eq.engine_hours',
        'kwh_produced' => 'eq.kwh_produced',
        'title' => 'aud.f_title',
        'category' => 'aud.f_category',
        'method' => 'aud.f_method',
        'role' => 't.role',
        'status' => 't.status',
        'username' => 't.username',
        'meter_no' => 't.meter_no',
        'period_created' => 'aud.f_created_on',
        'prev_debt' => 'aud.f_prev_debt',
        'current_reading' => 'aud.f_current_reading',
        'previous_reading' => 'aud.f_previous_reading',
        'consumption' => 'aud.f_consumption',
        'bill_id' => 'aud.f_bill',
        'bill_total' => 'aud.f_bill_total',
        'new_total' => 'aud.f_new_total',
        'discount' => 'aud.f_discount',
        'charge' => 'aud.f_charge',
        'cash' => 'aud.f_cash',
        'applied' => 'aud.f_applied',
        'excess' => 'aud.f_excess',
        'credited' => 'aud.f_credited',
        'remaining' => 'aud.f_remaining',
        'in_hand_after' => 'aud.f_in_hand_after',
        'collector_id' => 'aud.f_collector',
        'subscriber_id' => 'aud.f_subscriber',
        'route' => 'aud.f_route',
        'flat_fee' => 'aud.f_flat_fee',
        'initial_reading' => 'aud.f_initial_reading',
        'value' => 'aud.f_value',
        'estimated' => 'aud.f_estimated',
        'estimate_reason' => 'aud.f_estimate_reason',
        'cycle_closed' => 'aud.f_cycle_closed',
        'released_meter' => 'aud.f_released_meter',
        'already_open' => 'aud.f_already_open',
        'estimates_already_billed' => 'aud.f_estimates_already_billed',
        'meter_case_open' => 'aud.f_meter_case_open',
        'estimate_extension' => 'aud.f_estimate_extension',
        'estimates_used' => 'aud.f_estimates_used',
        'meter_swap' => 'aud.f_meter_swap',
        'credit_undone' => 'aud.f_credit_undone',
        'expense_removed' => 'aud.f_expense_removed',
        'expense_id' => 'aud.f_expense',
        'expense_amount_before' => 'aud.f_expense_before',
        'replaced_row_id' => 'aud.f_replaced_row',
        'created' => 'aud.f_created',
        'refused' => 'aud.f_refused',
        'failed' => 'aud.f_failed',
        'run' => 'aud.f_run',
        'by' => 'aud.f_by',
        'items' => 'aud.f_items',
        'number' => 'aud.f_number',
        'before' => 'aud.f_before',
        'after' => 'aud.f_after',
        'removed' => 'aud.f_removed',
        'quantity' => 'aud.f_quantity',
        'supplier' => 'aud.f_supplier',
        'kwh_source' => 'aud.f_kwh_source',
        'expense_date' => 'aud.f_expense_date',
    ];

    public function __construct(private readonly AuditLog $row) {}

    public static function of(AuditLog $row): self
    {
        return new self($row);
    }

    /**
     * Every action that belongs to one family — what the «النوع» filter narrows to, from the same table the
     * titles come from, so a family can never list an action the reader does not know.
     *
     * @return list<string>
     */
    public static function actionsIn(string $family): array
    {
        return array_keys(array_filter(
            self::ACTIONS,
            fn (array $meta): bool => $meta['family'] === $family
        ));
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return is_array($this->row->payload) ? $this->row->payload : [];
    }

    public function action(): string
    {
        return (string) $this->row->action;
    }

    public function isKnown(): bool
    {
        return isset(self::ACTIONS[$this->action()]);
    }

    /** The badge: Arabic for a known act, its own key for one nobody has templated yet. */
    public function title(): string
    {
        return $this->isKnown() ? __(self::ACTIONS[$this->action()]['title']) : $this->action();
    }

    public function family(): string
    {
        if ($this->isKnown()) {
            return self::ACTIONS[$this->action()]['family'];
        }

        return match (explode('.', $this->action())[0]) {
            'reading' => self::FAMILY_READINGS,
            'bill' => self::FAMILY_BILLS,
            'payment' => self::FAMILY_MONEY,
            'subscriber' => self::FAMILY_SUBSCRIBERS,
            'refuelling', 'service' => self::FAMILY_DEVICES,
            'expense' => self::FAMILY_EXPENSES,
            'handover' => self::FAMILY_HANDOVERS,
            'user' => self::FAMILY_USERS,
            default => self::FAMILY_IMPORTS,
        };
    }

    public function familyLabel(): string
    {
        return __('aud.fam_'.$this->family());
    }

    /** The act in one line: what happened, to whom, with the figures the row carries. */
    public function sentence(): string
    {
        return implode(' · ', array_filter(array_merge([$this->head()], $this->tail())));
    }

    /** The money this row moved, or null when the act moved none. */
    public function money(): ?float
    {
        $payload = $this->payload();
        $key = $this->isKnown() ? self::ACTIONS[$this->action()]['money'] : null;

        if ($key !== null && filled($payload[$key] ?? null)) {
            return (float) $payload[$key];
        }

        // A refuelling's money is litres × the price paid — the same arithmetic the fuel card uses — and only
        // when both are on the row. A guessed amount would be worse than an empty column.
        if (in_array($this->family(), [self::FAMILY_DEVICES], true)) {
            $facts = $this->deviceRow();
            if (filled($facts['litres'] ?? null) && filled($facts['price_per_litre'] ?? null)) {
                return round((float) $facts['litres'] * (float) $facts['price_per_litre'], 2);
            }
        }

        return null;
    }

    public function reason(): ?string
    {
        $reason = $this->payload()['reason'] ?? null;

        return filled($reason) ? (string) $reason : null;
    }

    /** Was the month this act touched already closed? Null when the act has no month. */
    public function monthClosed(): ?bool
    {
        return array_key_exists('cycle_closed', $this->payload())
            ? (bool) $this->payload()['cycle_closed']
            : null;
    }

    public function period(): ?string
    {
        $period = $this->payload()['period'] ?? null;

        return is_string($period) && preg_match('/^\d{4}-\d{2}$/', $period) ? $period : null;
    }

    /**
     * The changes a correction made: only the fields that actually moved, in the office's words.
     *
     * A `before` + `after` pair is what the correction actions write; a `removed` block is the same idea with
     * an absence on the right-hand side («حُذف»), which is exactly how an operator reads a deletion.
     *
     * @return list<array{label: string, from: string, to: string}>
     */
    public function changes(): array
    {
        $payload = $this->payload();
        $before = $payload['before'] ?? null;
        $after = $payload['after'] ?? $payload['removed'] ?? null;

        if (! is_array($before) || ! is_array($after)) {
            return [];
        }

        $rows = [];
        foreach ($before as $key => $from) {
            $to = array_key_exists($key, $after) ? $after[$key] : null;
            $gone = ! array_key_exists('after', $payload);

            if (! $gone && $this->same($from, $to)) {
                continue;                       // only what moved: a full dump is what this feature replaced
            }

            $rows[] = [
                'label' => $this->label((string) $key),
                'from' => $this->value($from),
                'to' => $gone ? __('aud.removed_mark') : $this->value($to),
            ];
        }

        // A field the correction ADDED (absent before, present after) belongs here too.
        if (array_key_exists('after', $payload)) {
            foreach ($after as $key => $to) {
                if (array_key_exists($key, $before)) {
                    continue;
                }
                $rows[] = ['label' => $this->label((string) $key), 'from' => __('aud.empty_mark'), 'to' => $this->value($to)];
            }
        }

        return $rows;
    }

    /**
     * Every key of the payload as a labelled pair — the detail's body, and the fallback for an action nobody
     * has templated. Nested blocks are rendered as pairs rather than JSON.
     *
     * @return list<array{label: string, value: string}>
     */
    public function facts(): array
    {
        $facts = [];

        foreach ($this->payload() as $key => $value) {
            if (in_array($key, ['before', 'after', 'removed'], true)) {
                continue;                       // shown as CHANGES, not twice
            }

            $facts[] = ['label' => $this->label((string) $key), 'value' => $this->value($value)];
        }

        // The two technical strings a support call needs, kept at the end where they belong.
        $facts[] = ['label' => __('aud.f_action'), 'value' => $this->action()];
        $facts[] = ['label' => __('aud.f_subject'), 'value' => $this->subjectTechnical()];

        return $facts;
    }

    /** The household, device or document this row is about, by name. */
    public function subjectLabel(): string
    {
        $payload = $this->payload();

        foreach (['name', 'title', 'description'] as $key) {
            if (filled($payload[$key] ?? null)) {
                return (string) $payload[$key];
            }
        }

        if ($subscriber = $this->subscriber()) {
            return $subscriber->name;
        }

        if ($device = $this->deviceName()) {
            return $device;
        }

        return $this->subjectTechnical();
    }

    /** Where the thing itself lives, when it still exists — so a row is a door, not a dead end. */
    public function subjectUrl(): ?string
    {
        $type = (string) $this->row->subject_type;
        $id = $this->row->subject_id;

        try {
            return match ($type) {
                Subscriber::class => $id ? route('admin.subscribers.show', $id) : null,
                Reading::class => ($sub = $this->subscriber()) ? route('admin.subscribers.show', $sub->id) : null,
                Bill::class => $id ? route('print.bill', ['bill' => $id, 'size' => config('pdf.default_bill_size')]) : null,
                \App\Models\Payment::class => ($sub = $this->subscriber()) ? route('admin.subscribers.show', $sub->id) : null,
                Refuelling::class, EquipmentService::class => ($device = $this->equipmentId()) ? route('admin.device-file', $device) : null,
                CashHandover::class => $id ? route('admin.handovers.edit', $id) : null,
                User::class => route('admin.users.index'),
                default => null,
            };
        } catch (\Throwable) {
            // A route that no longer exists must not take the screen down; the trail is still readable.
            return null;
        }
    }

    public function subjectTechnical(): string
    {
        $type = class_basename((string) $this->row->subject_type);

        return $type !== '' ? $type.' #'.$this->row->subject_id : '—';
    }

    // ---- the sentence's parts -------------------------------------------------------------------

    private function head(): string
    {
        $name = $this->subjectLabel();
        $title = $this->title();

        return $name !== '—' ? $title.': '.$name : $title;
    }

    /** @return list<string> */
    private function tail(): array
    {
        $payload = $this->payload();
        $parts = [];
        $money = $this->money();

        if ($money !== null) {
            $parts[] = money($money);
        }

        foreach ([
            'litres' => fn ($v) => number_format((float) $v, 0).' '.__('eq.litres'),
            'price_per_litre' => fn ($v) => '$'.number_format((float) $v, 3).' / '.__('eq.price_per_litre'),
            'current_reading' => fn ($v) => __('aud.f_current_reading').' '.number_format((float) $v, 1),
            'consumption' => fn ($v) => number_format((float) $v, 1).' '.__('t.kwh'),
            'kwh_produced' => fn ($v) => number_format((float) $v, 1).' '.__('t.kwh'),
            'engine_hours' => fn ($v) => __('ops.device_hours', ['hours' => number_format((float) $v, 0)]),
            'flat_fee' => fn ($v) => __('aud.flat_fee_is', ['fee' => money((float) $v)]),
            'in_hand_after' => fn ($v) => __('aud.in_hand_after', ['amount' => money((float) $v)]),
            'remaining' => fn ($v) => __('aud.remaining_is', ['amount' => money((float) $v)]),
            'credit_undone' => fn ($v) => __('aud.credit_undone', ['amount' => money((float) $v)]),
            'created' => fn ($v) => __('aud.created_n', ['n' => (int) $v]),
        ] as $key => $format) {
            // `pick()` and not `$payload[$key]`: a correction or a removal keeps the figures one level down
            // (in `after` / `removed`), and reading only the top level is how the litres of a deleted
            // refuelling stayed invisible while an amount was printed for it.
            if (filled($value = $this->pick($key))) {
                $parts[] = $format($value);
            }
        }

        if ($period = $this->period()) {
            $parts[] = __('aud.for_month', ['month' => period_label_i18n($period)]);
        }

        if (($closed = $this->monthClosed()) === true) {
            $parts[] = __('aud.closed_month');
        }

        if (filled($payload['bill_id'] ?? null) && filled($payload['total'] ?? null)) {
            $parts[] = __('aud.invoice_numbered', ['number' => (int) $payload['bill_id'], 'total' => money((float) $payload['total'])]);
        }

        if ($reason = $this->reason()) {
            $parts[] = __('t.reason').': '.$reason;
        }

        if (($payload['estimated'] ?? null) === true) {
            $parts[] = __('aud.estimated_yes');
        }

        return $parts;
    }

    // ---- reading a payload value the same way everywhere -----------------------------------------

    /** The subscriber this row is about: from the payload, or through the row it points at. */
    private function subscriber(): ?Subscriber
    {
        $id = $this->payload()['subscriber_id'] ?? null;

        if (filled($id)) {
            return Subscriber::find((int) $id);
        }

        if ($this->row->subject_type === Subscriber::class && $this->row->subject_id) {
            return Subscriber::find((int) $this->row->subject_id);
        }

        if ($this->row->subject_type === Reading::class && $this->row->subject_id) {
            return Reading::with('subscriber')->find((int) $this->row->subject_id)?->subscriber;
        }

        if ($this->row->subject_type === Bill::class && $this->row->subject_id) {
            return Bill::with('subscriber')->find((int) $this->row->subject_id)?->subscriber;
        }

        return null;
    }

    private function equipmentId(): ?int
    {
        foreach ([Refuelling::class, EquipmentService::class] as $model) {
            if ($this->row->subject_type === $model && $this->row->subject_id) {
                return $model::find((int) $this->row->subject_id)?->equipment_id;
            }
        }

        return null;
    }

    private function deviceName(): ?string
    {
        foreach ([Refuelling::class, EquipmentService::class] as $model) {
            if ($this->row->subject_type === $model && $this->row->subject_id) {
                return $model::with('equipment')->find((int) $this->row->subject_id)?->equipment?->name;
            }
        }

        return null;
    }

    /**
     * One value, read from wherever the payload keeps it: the row's own state, or the side of a change that
     * carries it (`after` first — the state the office ended in — then `removed`, then `before`).
     */
    private function pick(string $key): mixed
    {
        $payload = $this->payload();

        if (filled($payload[$key] ?? null)) {
            return $payload[$key];
        }

        foreach (['after', 'removed', 'before'] as $side) {
            if (is_array($payload[$side] ?? null) && filled($payload[$side][$key] ?? null)) {
                return $payload[$side][$key];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function deviceRow(): array
    {
        $payload = $this->payload();

        // A correction keeps both sides of the row; a removal keeps the removed side.
        return (array) ($payload['after'] ?? $payload['removed'] ?? $payload['before'] ?? $this->payload());
    }

    private function label(string $key): string
    {
        $translated = isset(self::FIELD_KEYS[$key]) ? __(self::FIELD_KEYS[$key]) : null;

        // A key with no Arabic label of its own keeps its name: a guessed translation is worse than a
        // developer's word, and the office can always ask what it means.
        return filled($translated) && $translated !== self::FIELD_KEYS[$key] ? $translated : $key;
    }

    private function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? __('t.yes') : __('t.no');
        }

        if ($value === null) {
            return __('aud.empty_mark');
        }

        if (is_array($value)) {
            // A nested block (items, a before/after inside a payload) is pairs, never JSON: an operator reads
            // «الوصف: فلتر · الكمية: 2», not braces.
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = (is_string($key) ? $this->label($key).': ' : '').$this->value($item);
            }

            return implode(' · ', $pairs);
        }

        if (is_numeric($value)) {
            return number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2, '.', ',');
        }

        return (string) $value;
    }

    private function same(mixed $from, mixed $to): bool
    {
        if (is_array($from) || is_array($to)) {
            return json_encode($from, JSON_UNESCAPED_UNICODE) === json_encode($to, JSON_UNESCAPED_UNICODE);
        }

        if (is_numeric($from) && is_numeric($to)) {
            return (float) $from === (float) $to;
        }

        return $from === $to;
    }
}
