<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\AuditLog;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * The audit trail, read-only.
 *
 * Every correction in this panel writes here, so the screen an operator opens when the
 * numbers disagree is this one — it answers "who changed it, when, and from what".
 */
class AuditLogsTable extends TableDefinition
{
    public function model(): string
    {
        return AuditLog::class;
    }

    public function query(): Builder
    {
        return AuditLog::query()->with('actor:id,name');
    }

    public function searchable(): array
    {
        return ['action', 'subject_type', 'actor.name'];
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
            ['key' => 'created_at', 'label' => __('t.when'), 'type' => 'date', 'format' => 'Y-m-d H:i:s', 'sortable' => true],
            [
                'key' => 'actor.name',
                'label' => __('t.by'),
                'render' => fn (AuditLog $r): string => $r->actor?->name ?? '—',
            ],
            [
                'key' => 'action',
                'label' => __('t.action'),
                'type' => 'badge',
                'badge' => 'muted',
                'searchable' => true,
                'render' => fn (AuditLog $r): string => $r->action,
            ],
            [
                'key' => 'subject_type',
                'label' => __('t.subject'),
                'on_mobile' => false,
                'render' => fn (AuditLog $r): string => class_basename((string) $r->subject_type).' #'.$r->subject_id,
            ],
            [
                'key' => 'payload',
                'label' => __('t.details'),
                'wrap' => true,
                'on_mobile' => false,
                // The payload is a JSON blob. Dumped raw it is unreadable — no break
                // opportunities, so the column overflows and the operator sees a fragment
                // starting mid-key. Rendered as `key: value · key: value` it answers the
                // question the screen exists for: what did this change?
                'render' => function (AuditLog $r): string {
                    $payload = collect($r->payload ?? []);

                    if ($payload->isEmpty()) {
                        return '—';
                    }

                    $shown = $payload
                        ->map(fn ($value, string $key): string => $key.': '.(
                            is_scalar($value) || $value === null
                                ? (string) ($value ?? 'null')
                                : json_encode($value, JSON_UNESCAPED_UNICODE)
                        ))
                        ->take(4)
                        ->implode(' · ');

                    $rest = $payload->count() - 4;

                    return $rest > 0 ? $shown.' +'.$rest : $shown;
                },
            ],
        ];
    }

    public function filters(): array
    {
        return [[
            'key' => 'action',
            'label' => __('t.action'),
            'type' => 'select',
            'options_from' => fn (): array => AuditLog::query()->distinct()->orderBy('action')
                ->pluck('action', 'action')->all(),
            'apply' => fn (Builder $query, $value) => $query->where('action', $value),
        ]];
    }

    public function emptyState(): string
    {
        return __('t.no_results');
    }
}
