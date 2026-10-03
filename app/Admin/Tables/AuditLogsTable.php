<?php

namespace App\Admin\Tables;

use App\Admin\Actions\ViewAuditEntry;
use App\Admin\AuditNarrative;
use App\Admin\TableDefinition;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * سجل التدقيق, read the way the office reads.
 *
 * The trail is written by everything else in the app; this screen is how it is READ. Its own description has
 * always promised «كل تعديل مسجّل: مين، متى، وشو كانت القيمة القديمة» — and it answered in machine language:
 * `refuelling.removed` as a badge, `Refuelling #8` as the subject, the payload's first four keys and then
 * `+8`, and a nested block dumped as JSON with the figure the owner was looking for buried inside it.
 *
 * Now every row is a sentence (see `AuditNarrative`, the ONE place that words a payload), the money column
 * names an amount only when the row actually moved money, the reason sits on the line because «لماذا» is what
 * gets asked, and the row is a door: «تفاصيل» opens every value read-only and «فتح المادة» goes to the
 * household, invoice or device the act was about.
 *
 * Reading writes nothing: no audit row, no money, no payload rewritten.
 */
class AuditLogsTable extends TableDefinition
{
    public function model(): string
    {
        return AuditLog::class;
    }

    public function query(): Builder
    {
        return AuditLog::query()->with('actor:id,name,username');
    }

    public function searchable(): array
    {
        return ['action', 'subject_type', 'subject_id', 'actor.name', 'actor.username'];
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function perPage(): int
    {
        return 50;
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'created_at',
                'label' => __('t.when'),
                'sortable' => true,
                // «٣ تشرين الأول · ٠٨:٤٣» — the day in the office's own month names, because the first thing
                // an operator asks about a change is which day it happened on.
                'render' => fn (AuditLog $r): string => $this->when($r),
            ],
            [
                'key' => 'action',
                'label' => __('t.action'),
                'type' => 'badge',
                'badge' => 'muted',
                'wrap' => true,
                // The cell is the sentence — it opens with the act's own name, so prefixing the badge's title
                // again would say «حذف تعبئة — حذف تعبئة: …» (seen in the live render, and cut).
                'render' => fn (AuditLog $r): string => AuditNarrative::of($r)->sentence(),
            ],
            [
                'key' => 'actor.name',
                'label' => __('t.by'),
                'on_mobile' => false,
                'render' => fn (AuditLog $r): string => $r->actor?->name ?? __('aud.system'),
            ],
            [
                'key' => 'amount',
                'label' => __('t.amount'),
                'on_mobile' => false,
                // Only when the row moved money: an amount invented for a row that has none is worse than an
                // empty cell.
                'render' => function (AuditLog $r): string {
                    $money = AuditNarrative::of($r)->money();

                    return $money === null ? '—' : money_ltr($money);
                },
            ],
        ];
    }

    public function filters(): array
    {
        return [
            [
                'key' => 'month',
                'label' => __('t.month'),
                'type' => 'month',
                'apply' => fn (Builder $query, $value) => $query->whereBetween('created_at', [
                    Carbon::parse($value.'-01')->startOfMonth(),
                    Carbon::parse($value.'-01')->endOfMonth(),
                ]),
            ],
            [
                'key' => 'family',
                'label' => __('aud.kind'),
                'type' => 'select',
                'options_from' => fn (): array => collect(AuditNarrative::FAMILIES)
                    ->mapWithKeys(fn (string $family): array => [$family => __('aud.fam_'.$family)])
                    ->all(),
                'apply' => fn (Builder $query, $value) => $query->whereIn('action', AuditNarrative::actionsIn((string) $value)),
            ],
            [
                'key' => 'actor',
                'label' => __('aud.who'),
                'type' => 'select',
                // Only the people who actually have entries: a filter listing every user is longer than the
                // trail it filters.
                'options_from' => fn (): array => User::query()
                    ->whereIn('id', AuditLog::query()->whereNotNull('actor_id')->distinct()->pluck('actor_id'))
                    ->orderBy('name')->pluck('name', 'id')->all(),
                'apply' => fn (Builder $query, $value) => $query->where('actor_id', $value),
            ],
            [
                'key' => 'action',
                'label' => __('t.action'),
                'type' => 'select',
                // The same list as before, but an operator picks «حذف تعبئة» instead of `refuelling.removed`;
                // the raw slug stays in the detail, where a support call can read it.
                'options_from' => fn (): array => AuditLog::query()->distinct()->orderBy('action')
                    ->pluck('action', 'action')
                    ->mapWithKeys(fn (string $action, string $key): array => [$key => $this->actionLabel($action)])
                    ->all(),
                'apply' => fn (Builder $query, $value) => $query->where('action', $value),
            ],
        ];
    }

    public function rowActions(): array
    {
        return [
            [
                // Every value of the row, read-only: the sentence, the changes, the reason, and the payload
                // key by key.
                'key' => 'details',
                'label' => __('aud.details'),
                'icon' => 'ki-eye',
                'action_form' => ViewAuditEntry::class,
            ],
            [
                // The thing the act was about, one click away: the household's file, the invoice, the device.
                'key' => 'open',
                'label' => __('aud.open_subject'),
                'icon' => 'ki-arrow-up-right',
                'new_tab' => true,
                'url' => fn (AuditLog $r): string => (string) AuditNarrative::of($r)->subjectUrl(),
                'hidden' => fn (AuditLog $r): bool => AuditNarrative::of($r)->subjectUrl() === null,
            ],
        ];
    }

    public function emptyState(): string
    {
        return __('t.no_results');
    }

    private function when(AuditLog $r): string
    {
        $at = $r->created_at;

        return $at ? $at->day.' '.month_names_ar()[(int) $at->month].' · '.$at->format('H:i') : '—';
    }

    private function actionLabel(string $action): string
    {
        $narrative = AuditNarrative::of(new AuditLog(['action' => $action]));

        return $narrative->isKnown() ? $narrative->title() : $action;
    }
}
