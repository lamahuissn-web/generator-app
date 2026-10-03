<?php

namespace App\Admin\Actions;

use App\Admin\ActionDefinition;
use App\Admin\AuditNarrative;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * تفاصيل الحركة — the audit row opened, read-only.
 *
 * The list says the act in one line; this shows everything the row recorded: what happened, what changed,
 * why, and every remaining key of the payload as a labelled pair (a nested block as pairs, never JSON). It is
 * built on the app's own modal so it behaves like every other panel dialog — and it is READ-ONLY by
 * construction: the fields are `static` lines, and `handle()` writes nothing, audits nothing and moves no
 * money. A trail that can be edited from the screen that reads it is not a trail.
 *
 * Available to an admin only, like the screen that opens it.
 */
class ViewAuditEntry extends ActionDefinition
{
    public function subjectModel(): string
    {
        return AuditLog::class;
    }

    public function label(): string
    {
        return __('aud.details');
    }

    public function icon(): ?string
    {
        return 'ki-eye';
    }

    public function triggerClass(): string
    {
        return 'kt-btn-ghost';
    }

    public function modalHeading(Model $subject): string
    {
        return __('aud.detail_heading', ['when' => $subject->created_at?->format('Y-m-d H:i')]);
    }

    public function submitLabel(): string
    {
        return __('t.close');
    }

    /** المدير فقط — the screen itself is admin-only, and this modal carries the same reasons and prices. */
    public function visible(Model $subject): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function sections(): array
    {
        $row = $this->subject;
        if (! $row instanceof AuditLog) {
            return [];
        }

        $audit = AuditNarrative::of($row);

        return array_values(array_filter([
            $this->happened($audit),
            $this->changed($audit),
            $this->everything($audit),
        ]));
    }

    /** قراءة فقط: nothing to save, nothing to send — the modal's button only closes it. */
    public function handle(array $state, Model $subject): array
    {
        return ['ok' => true, 'message' => __('aud.readonly')];
    }

    /** @return array{title: string, fields: list<array<string, mixed>>} */
    private function happened(AuditNarrative $audit): array
    {
        $fields = [
            ['key' => 'narrative', 'label' => __('aud.what_happened'), 'type' => 'static', 'value' => $audit->sentence(), 'span' => 'full'],
            ['key' => 'subject', 'label' => __('aud.f_subject'), 'type' => 'static', 'value' => $audit->subjectLabel(), 'span' => 'full'],
        ];

        if (($money = $audit->money()) !== null) {
            $fields[] = ['key' => 'amount', 'label' => __('t.amount'), 'type' => 'static', 'value' => money_ltr($money)];
        }

        if ($period = $audit->period()) {
            $fields[] = ['key' => 'period', 'label' => __('t.month'), 'type' => 'static', 'value' => period_label_i18n($period)];
        }

        $fields[] = [
            'key' => 'cycle',
            'label' => __('aud.f_cycle_closed'),
            'type' => 'static',
            'value' => $audit->monthClosed() === true ? __('t.yes') : __('t.no'),
        ];

        if ($reason = $audit->reason()) {
            $fields[] = ['key' => 'reason', 'label' => __('t.reason'), 'type' => 'static', 'value' => $reason, 'span' => 'full'];
        }

        return ['title' => __('aud.sec_happened'), 'fields' => $fields];
    }

    /** @return array{title: string, fields: list<array<string, mixed>>}|null */
    private function changed(AuditNarrative $audit): ?array
    {
        $changes = $audit->changes();

        if ($changes === []) {
            return null;
        }

        $fields = [];
        foreach ($changes as $index => $change) {
            $fields[] = [
                'key' => 'change_'.$index,
                'label' => $change['label'],
                'type' => 'static',
                'value' => $change['from'].'  ←  '.$change['to'],
            ];
        }

        return ['title' => __('aud.sec_changed'), 'fields' => $fields];
    }

    /** @return array{title: string, fields: list<array<string, mixed>>} */
    private function everything(AuditNarrative $audit): array
    {
        $fields = [];
        foreach ($audit->facts() as $index => $fact) {
            $fields[] = [
                'key' => 'fact_'.$index,
                'label' => $fact['label'],
                'type' => 'static',
                'value' => $fact['value'],
                'span' => 'full',
            ];
        }

        return ['title' => __('aud.sec_all'), 'fields' => $fields];
    }
}
