<?php

namespace App\Admin\Tables;

use App\Admin\TableDefinition;
use App\Models\BackupAttempt;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every backup run, newest first — the list that turns "I think it backs up" into a fact.
 *
 * Nothing here decides anything: the labels come from the model, the colours from the model's own
 * status tone, and the sizes from the model's own formatter. A second copy of "what 'sent' means"
 * would be a second answer to the question the office is actually asking.
 */
class BackupAttemptsTable extends TableDefinition
{
    public function model(): string
    {
        return BackupAttempt::class;
    }

    public function query(): Builder
    {
        return BackupAttempt::query()->with('creator:id,name');
    }

    public function searchable(): array
    {
        // A log is read, not searched: the newest rows are the ones somebody is looking for, and the
        // screen above already answers "was anything sent?" without scrolling.
        return [];
    }

    public function defaultSort(): array
    {
        return ['started_at', 'desc'];
    }

    public function perPage(): int
    {
        return 15;
    }

    public function columns(): array
    {
        return [
            [
                'key' => 'started_at',
                'label' => __('bk.col_when'),
                'type' => 'two-line',
                'sortable' => true,
                'sub' => fn (BackupAttempt $r): ?string => $r->file_name
                    ? ltr_isolate($r->file_name)
                    : ($r->delivered() ? null : $r->triggerLabel()),
            ],
            [
                'key' => 'trigger',
                'label' => __('bk.col_trigger'),
                'type' => 'badge',
                'badge' => 'muted',
                'sortable' => true,
                'on_mobile' => false,
                'render' => fn (BackupAttempt $r): string => $r->triggerLabel(),
            ],
            [
                'key' => 'status',
                'label' => __('bk.col_status'),
                'type' => 'badge',
                'sortable' => true,
                'color' => fn (BackupAttempt $r): string => $r->statusTone(),
                'render' => fn (BackupAttempt $r): string => $r->statusLabel(),
            ],
            [
                'key' => 'size_bytes',
                'label' => __('bk.col_size'),
                'align' => 'end',
                'on_mobile' => false,
                'render' => fn (BackupAttempt $r): string => $r->size_bytes === null
                    ? '—'
                    : ltr_isolate($r->sizeForHumans()),
            ],
            [
                'key' => 'error',
                'label' => __('bk.col_reason'),
                'on_mobile' => false,
                // The reason only exists when something went wrong; a column of dashes would be the
                // widest thing on the screen for no information.
                'render' => fn (BackupAttempt $r): string => $r->error ?? '',
            ],
            [
                'key' => 'created_by',
                'label' => __('bk.col_by'),
                'on_mobile' => false,
                'hidden' => true,
                'render' => fn (BackupAttempt $r): string => $r->creator?->name ?? '',
            ],
        ];
    }

    public function emptyState(): string
    {
        return __('bk.none');
    }
}
