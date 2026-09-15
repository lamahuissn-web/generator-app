<?php

namespace App\Services;

use App\Models\Route;
use App\Models\Subscriber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Importing a month of meter readings — and the bills that follow from them.
 *
 * The office keeps this on paper: for a given month, every household's reading, with the one before it
 * beside it. Entering that by hand is the slowest part of moving onto the panel, so the file goes in
 * whole — but not around the rules. Every row is handed to `ReadingService::record()`, the same call
 * the collector's screen and the office's reading sheet make, so the price, the maintenance fee, the
 * rounding, the debt carried from the month before and the bill's number are all produced exactly as
 * they would be by hand. Nothing here decides money.
 *
 * Two checks make the file trustworthy rather than merely accepted:
 *
 *  - The PREVIOUS reading in the file is compared with the chain the panel can prove (the subscriber's
 *    last reading, or the reading he arrived with). A mismatch refuses the row and prints both numbers,
 *    because a wrong previous reading is a wrong bill sent to a household.
 *  - A month that has already been read is refused, never overwritten: «ما في تعديل صامت على قراءة
 *    مقفلة». The office corrects a closed cycle through the correction path, on purpose.
 */
class ReadingImport
{
    /** A bigger file than this is a data-migration job, not a screen. */
    public const MAX_ROWS = 2000;

    /**
     * The columns of the readings file, in order.
     *
     * `headers` lists every heading we accept for a column: the ones this screen prints, and the ones
     * the readings list already exports — so the office can export a month, edit it in Excel and put it
     * back, or type the template by hand.
     */
    public static function columns(): array
    {
        return [
            'meter_no' => ['headers' => ['t.meter_no', 't.meter'], 'rules' => ['nullable', 'string', 'max:40']],
            'name' => ['headers' => ['t.name', 't.subscriber'], 'rules' => ['nullable', 'string', 'max:150']],
            'route' => ['headers' => ['t.route'], 'rules' => ['nullable', 'string', 'max:120']],
            'period' => ['headers' => ['t.period'], 'rules' => ['required']],
            'previous' => ['headers' => ['t.prev_reading'], 'rules' => ['nullable', 'numeric', 'min:0']],
            'current' => ['headers' => ['t.current_reading'], 'rules' => ['required', 'numeric', 'min:0']],
        ];
    }

    /** The header row the readings template carries. */
    public static function headers(): array
    {
        return array_values(array_map(
            fn (array $column): string => __($column['headers'][0]),
            self::columns(),
        ));
    }

    /** The template: the headings plus one row, so the shape is obvious. */
    public static function template(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::headers());
        fputcsv($handle, ['M-1051', 'علي شرارة', 'عين الرمانة', now()->subMonth()->format('Y-m'), '12500', '12580']);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Read a readings file into raw rows keyed by column.
     *
     * @return array{rows: array<int, array<string, string>>, error: ?string}
     */
    public static function parse(string $path): array
    {
        $lines = CsvFile::lines($path);

        if ($lines === []) {
            return ['rows' => [], 'error' => __('imp.file_empty')];
        }

        $headerCells = array_shift($lines);
        $map = self::mapHeader($headerCells);

        if ($map === null) {
            return ['rows' => [], 'error' => __('imp.rd_headers_missing', [
                'expected' => implode(' · ', self::headers()),
            ])];
        }

        $rows = [];
        $position = 0;

        foreach ($lines as $cells) {
            $position++;

            if ($position > self::MAX_ROWS) {
                return ['rows' => $rows, 'error' => __('imp.too_many_rows', ['max' => number_format(self::MAX_ROWS)])];
            }

            $row = [];

            foreach ($map as $index => $key) {
                $row[$key] = CsvFile::clean($cells[$index] ?? '');
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * Judge every row without writing anything.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function preview(array $rows): array
    {
        $routes = Route::query()->get(['id', 'name'])
            ->mapWithKeys(fn (Route $route): array => [CsvFile::word($route->name) => $route->id])
            ->all();

        /** @var Collection<int, Subscriber> $subscribers */
        $subscribers = Subscriber::query()->with('route:id,name')->get();

        $byMeter = [];
        $byName = [];

        foreach ($subscribers as $subscriber) {
            if (filled($subscriber->meter_no)) {
                $byMeter[CsvFile::word($subscriber->meter_no)] = $subscriber;
            }

            $byName[$subscriber->route_id.'|'.CsvFile::word($subscriber->name)] = $subscriber;
        }

        $seen = [];
        $previewed = [];

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $errors = [];
            $entry = [
                'row' => $number,
                'meter_no' => CsvFile::clean($row['meter_no'] ?? ''),
                'name' => CsvFile::clean($row['name'] ?? ''),
                'route' => CsvFile::clean($row['route'] ?? ''),
                'period' => BillingService::cleanPeriod($row['period'] ?? null),
                'previous' => CsvFile::number($row['previous'] ?? ''),
                'current' => CsvFile::number($row['current'] ?? ''),
                'subscriber' => null,
                'opens_with' => null,
            ];

            if ($entry['period'] === null) {
                $errors[] = __('imp.rd_period_bad', ['value' => (string) ($row['period'] ?? '')]);
            }

            if ($entry['current'] === '' || ! is_numeric($entry['current'])) {
                $errors[] = __('imp.rd_current_bad', ['value' => (string) ($row['current'] ?? '')]);
            }

            if ($entry['previous'] !== '' && ! is_numeric($entry['previous'])) {
                $errors[] = __('imp.rd_previous_bad', ['value' => (string) ($row['previous'] ?? '')]);
            }

            if ($errors !== [] || $entry['period'] === null) {
                $previewed[] = $entry + ['status' => 'problem', 'errors' => $errors];

                continue;
            }

            // Who is this? The meter number first — it is what the office reads off the dial — and the
            // name inside its route only when the dial is not on the file.
            $subscriber = null;

            if ($entry['meter_no'] !== '') {
                $subscriber = $byMeter[CsvFile::word($entry['meter_no'])] ?? null;
            }

            if ($subscriber === null && $entry['name'] !== '') {
                $routeId = $entry['route'] === '' ? null : ($routes[CsvFile::word($entry['route'])] ?? null);
                $lookup = ($routeId ?? 'any').'|'.CsvFile::word($entry['name']);

                if ($routeId !== null) {
                    $subscriber = $byName[$lookup] ?? null;
                }

                if ($subscriber === null) {
                    $subscriber = $subscribers->first(
                        fn (Subscriber $candidate): bool => CsvFile::word($candidate->name) === CsvFile::word($entry['name'])
                            && ($routeId === null || $candidate->route_id === $routeId),
                    );
                }
            }

            if ($subscriber === null) {
                $errors[] = __('imp.rd_subscriber_unknown', ['who' => $entry['meter_no'] !== '' ? $entry['meter_no'] : $entry['name']]);
            } elseif ($subscriber->type !== Subscriber::TYPE_METERED) {
                $errors[] = __('imp.rd_not_metered', ['name' => $subscriber->name]);
            }

            if ($errors === []) {
                $entry['subscriber'] = $subscriber->id;

                // The month must not already be read.
                if ($subscriber->readingFor($entry['period']) !== null) {
                    $errors[] = __('imp.rd_period_already_read', ['name' => $subscriber->name, 'period' => $entry['period']]);
                }

                // And the previous reading must be the one the panel can prove — or, for a household
                // whose chain starts here, it becomes the reading it arrived with.
                $chain = BillingService::latestReadingBefore($subscriber, $entry['period']);

                if ($chain !== null) {
                    $expected = (float) $chain->current_reading;

                    if ($entry['previous'] === '' || abs((float) $entry['previous'] - $expected) > 0.009) {
                        $errors[] = __('imp.rd_chain_mismatch', [
                            'name' => $subscriber->name,
                            'period' => $entry['period'],
                            'file' => $entry['previous'] === '' ? '—' : number_format((float) $entry['previous'], 0, '.', ''),
                            'system' => number_format($expected, 0, '.', ''),
                        ]);
                    }
                } elseif ($subscriber->opening_reading !== null) {
                    if ($entry['previous'] === '' || abs((float) $entry['previous'] - (float) $subscriber->opening_reading) > 0.009) {
                        $errors[] = __('imp.rd_chain_mismatch', [
                            'name' => $subscriber->name,
                            'period' => $entry['period'],
                            'file' => $entry['previous'] === '' ? '—' : number_format((float) $entry['previous'], 0, '.', ''),
                            'system' => number_format((float) $subscriber->opening_reading, 0, '.', ''),
                        ]);
                    }
                } elseif ($entry['previous'] === '') {
                    $errors[] = __('imp.rd_previous_needed', ['name' => $subscriber->name]);
                } else {
                    // The chain starts here: this is the dial the household arrived with.
                    $entry['opens_with'] = (float) $entry['previous'];
                }

                if ($errors === [] && (float) $entry['current'] < (float) $entry['previous']) {
                    $errors[] = __('imp.rd_current_lower', [
                        'current' => number_format((float) $entry['current'], 0, '.', ''),
                        'previous' => number_format((float) $entry['previous'], 0, '.', ''),
                    ]);
                }

                $key = $subscriber->id.'|'.$entry['period'];

                if ($errors === []) {
                    if (isset($seen[$key])) {
                        $errors[] = __('imp.rd_duplicate', ['row' => $seen[$key]]);
                    } else {
                        $seen[$key] = $number;
                    }
                }
            }

            $previewed[] = $entry + ['status' => $errors === [] ? 'ok' : 'problem', 'errors' => $errors];
        }

        return $previewed;
    }

    /**
     * Write the readings — and the bills that follow from each one.
     *
     * Per row, in its own transaction: one household failing must not sink a month of readings, and the
     * failure is reported with the panel's own words rather than swallowed.
     *
     * @param  array<int, array<string, mixed>>  $previewed
     * @return array{created: int, failed: array<int, array<string, mixed>>}
     */
    public static function commit(array $previewed, ?int $userId = null): array
    {
        $userId = $userId ?? auth()->id();
        $created = 0;
        $failed = [];

        foreach ($previewed as $row) {
            if ($row['status'] !== 'ok') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $userId): void {
                    $subscriber = Subscriber::findOrFail($row['subscriber']);

                    // A household whose chain starts with this file: the dial it arrived with is what
                    // the next month measures from.
                    if ($row['opens_with'] !== null && $subscriber->opening_reading === null) {
                        $subscriber->forceFill([
                            'opening_reading' => $row['opens_with'],
                            'opening_period' => BillingService::periodBefore($row['period']),
                        ])->save();
                    }

                    ReadingService::record($subscriber, [
                        'period' => $row['period'],
                        'reading_mode' => ReadingService::MODE_NORMAL,
                        'current_reading' => (float) $row['current'],
                        'note' => __('imp.rd_note'),
                    ], $userId);
                });

                $created++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => $row['row'], 'name' => $row['name'] !== '' ? $row['name'] : $row['meter_no'], 'reason' => $e->getMessage()];
            }
        }

        if ($created > 0) {
            Audit::log('reading.imported', null, [
                'created' => $created,
                'refused' => count($previewed) - $created,
                'failed' => count($failed),
            ]);
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /** The error file: the refused rows, with their reasons, ready to be fixed and uploaded again. */
    public static function errorsCsv(array $previewed): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_merge([__('imp.row_number')], self::headers(), [__('imp.reason')]));

        foreach ($previewed as $row) {
            if ($row['status'] === 'ok') {
                continue;
            }

            fputcsv($handle, array_merge([$row['row']], [
                $row['meter_no'],
                $row['name'],
                $row['route'],
                (string) ($row['period'] ?? ''),
                (string) $row['previous'],
                (string) $row['current'],
            ], [implode(' | ', $row['errors'])]));
        }

        // Most refusals are a broken chain or an unknown household; the routes the panel has make the
        // first fixable in the same file.
        fputcsv($handle, []);
        fputcsv($handle, [__('imp.known_routes'), Route::query()->orderBy('name')->pluck('name')->implode(' · ')]);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Match the file's headings to the columns — in either language, or as the readings list exports
     * them, so a month can go out to Excel and come back.
     *
     * @return array<int, string>|null  cell index => column key, or null when the file is not ours
     */
    private static function mapHeader(array $cells): ?array
    {
        $known = [];

        foreach (self::columns() as $key => $column) {
            $known[CsvFile::word($key)] = $key;

            foreach ($column['headers'] as $headerKey) {
                foreach (['ar', 'en'] as $locale) {
                    $known[CsvFile::word(trans($headerKey, [], $locale))] = $key;
                }
            }
        }

        $map = [];

        foreach ($cells as $index => $cell) {
            $word = CsvFile::word((string) $cell);

            if (isset($known[$word]) && ! in_array($known[$word], $map, true)) {
                $map[$index] = $known[$word];
            }
        }

        foreach (['period', 'current'] as $required) {
            if (! in_array($required, $map, true)) {
                return null;
            }
        }

        return $map;
    }
}
