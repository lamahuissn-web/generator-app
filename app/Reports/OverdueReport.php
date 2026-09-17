<?php

namespace App\Reports;

use App\Models\Bill;
use App\Models\Subscriber;
use Illuminate\Support\Collection;

/**
 * Who owes what, aged into buckets.
 *
 * The bucketing rule ("two months or more behind = red")
 * is the report's whole meaning, and it does not belong in a view.
 *
 * The dues are aggregated in ONE query and joined to subscribers in PHP rather than
 * sub-queried per row: the previous version paid a query per subscriber on a list that is
 * every subscriber in the network.
 */
final class OverdueReport
{
    public const BUCKET_RED = 'red';

    public const BUCKET_YELLOW = 'yellow';

    public const BUCKET_CURRENT = 'current';

    public const BUCKET_SETTLED = 'settled';

    public function __construct(
        private readonly string $bucket = 'all',
        private readonly ?int $routeId = null,
    ) {}

    public static function make(string $bucket = 'all', ?int $routeId = null): self
    {
        return new self($bucket, $routeId);
    }

    /** Bucket options for the picker, in the order they are ranked. */
    public static function bucketOptions(): array
    {
        return [
            'all' => __('over.opt_all'),
            self::BUCKET_RED => __('over.opt_red'),
            self::BUCKET_YELLOW => __('over.opt_yellow'),
            self::BUCKET_CURRENT => __('over.opt_current'),
            self::BUCKET_SETTLED => __('over.opt_settled'),
        ];
    }

    /**
     * A subscriber's bucket from their oldest unpaid period.
     *
     * Static + public so the rule can be asserted directly instead of through a rendered
     * page. Called with months of age, not dates, so the caller does the month maths once.
     */
    public static function bucketFor(float $due, int $oldestMonthsAgo): string
    {
        if ($due <= 0) {
            return self::BUCKET_SETTLED;
        }

        return $oldestMonthsAgo >= 2
            ? self::BUCKET_RED
            : ($oldestMonthsAgo === 1 ? self::BUCKET_YELLOW : self::BUCKET_CURRENT);
    }

    /** Months from $period up to $current, both 'Y-m'. */
    public static function monthsBetween(string $current, string $period): int
    {
        [$currentYear, $currentMonth] = array_map('intval', explode('-', $current));
        [$periodYear, $periodMonth] = array_map('intval', explode('-', $period));

        return ($currentYear * 12 + $currentMonth) - ($periodYear * 12 + $periodMonth);
    }

    /** @return Collection<int, array<string, mixed>> worst first, then largest debt */
    public function rows(): Collection
    {
        $dues = Bill::query()
            ->selectRaw('subscriber_id, MIN(period) as oldest, MAX(period) as newest, COALESCE(SUM(total - paid_amount), 0) as due')
            ->whereRaw('paid_amount < total')
            ->groupBy('subscriber_id')
            ->get()
            ->keyBy('subscriber_id');

        $current = now()->format('Y-m');

        $rows = Subscriber::query()
            ->with('route:id,name')
            ->whereNull('exempt_at')
            ->when($this->routeId, fn ($query) => $query->where('route_id', $this->routeId))
            ->get()
            ->map(function (Subscriber $subscriber) use ($dues, $current): array {
                $agg = $dues->get($subscriber->id);
                $due = (float) ($agg->due ?? 0);
                $oldest = $agg->oldest ?? null;

                return [
                    'id' => $subscriber->id,
                    'name' => $subscriber->name,
                    'route' => $subscriber->route?->name,
                    'type' => $subscriber->type,
                    'status' => $subscriber->status,
                    'phone' => $subscriber->phone,
                    'due' => $due,
                    'oldest' => $oldest,
                    'newest' => $agg->newest ?? null,
                    'bucket' => self::bucketFor($due, $oldest ? self::monthsBetween($current, (string) $oldest) : 0),
                ];
            })
            ->sortBy([
                fn ($a, $b) => self::rank($a['bucket']) <=> self::rank($b['bucket']),
                fn ($a, $b) => $b['due'] <=> $a['due'],
            ])
            ->values();

        if ($this->bucket !== 'all') {
            $rows = $rows->where('bucket', $this->bucket)->values();
        }

        return $rows;
    }

    /** Worst bucket first. Drives both the sort and the row colour. */
    public static function rank(string $bucket): int
    {
        return match ($bucket) {
            self::BUCKET_RED => 0,
            self::BUCKET_YELLOW => 1,
            self::BUCKET_CURRENT => 2,
            default => 3,
        };
    }

    /** @return array{total: float, red: float, yellow: float, count: int} */
    public function summary(): array
    {
        $rows = $this->rows();
        $sum = fn (callable $filter): float => (float) $rows->filter($filter)->sum('due');

        return [
            'total' => (float) $rows->sum('due'),
            'red' => $sum(fn (array $r) => $r['bucket'] === self::BUCKET_RED),
            'yellow' => $sum(fn (array $r) => $r['bucket'] === self::BUCKET_YELLOW),
            'count' => $rows->where('bucket', '!=', self::BUCKET_SETTLED)->count(),
        ];
    }

    /**
     * The report as CSV, for the operator who takes it to a meeting.
     *
     * Settled subscribers are dropped (a debt report listing zeroes is noise) and the file
     * opens with a UTF-8 BOM, without which Excel renders every Arabic name as mojibake.
     */
    public function csv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            __('t.name'), __('t.route'), __('t.type'), __('t.phone'),
            __('t.status'), __('over.oldest'), __('t.total_due'),
        ]);

        foreach ($this->rows()->where('bucket', '!=', self::BUCKET_SETTLED) as $row) {
            fputcsv($handle, [
                $row['name'],
                $row['route'] ?? '',
                $row['type'] === Subscriber::TYPE_METERED ? __('t.type_metered') : __('t.type_flat'),
                $row['phone'] ?? '',
                $row['status'],
                $row['oldest'] ?? '',
                number_format($row['due'], 2, '.', ''),
            ]);
        }

        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }
}
