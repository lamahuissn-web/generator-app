<?php

namespace App\Services;

use App\Models\Route;
use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Importing subscribers from a spreadsheet — the fast way in when the real data arrives.
 *
 * One list of columns describes the whole feature: the template that ships, the CSV the office
 * exports, and what the importer accepts are the same twelve headings in the same order. A test
 * compares all three, because a template that does not match the importer is worse than none.
 *
 * Nothing is written until the operator has seen the preview, and a row that would overwrite a
 * subscriber already in the panel is refused rather than applied (the office asked for exactly that:
 * «ما بينمحى ولا بينتعدّل إنسان»). The file is read as bytes, never trusted: the delimiter, the
 * encoding (Excel writes Arabic as Windows-1256, or UTF-16 with a BOM) and the digits are all
 * normalised here, and any row that still does not fit is named with a reason the office can act on.
 */
class SubscriberImport
{
    /** A bigger file than this is a data-migration job, not a screen. */
    public const MAX_ROWS = 2000;

    /**
     * The columns, in the order they appear in the file.
     *
     * `key` is the attribute name; `header` is what the operator sees in the template and the export;
     * `rules` mirror SubscriberForm — the import must not accept what the form would refuse.
     */
    public static function columns(): array
    {
        return [
            'name' => ['header' => 't.name', 'rules' => ['required', 'string', 'max:150']],
            'route' => ['header' => 't.route', 'rules' => ['required', 'string', 'max:120']],
            'meter_no' => ['header' => 't.meter_no', 'rules' => ['nullable', 'string', 'max:40']],
            'phone' => ['header' => 't.phone', 'rules' => ['nullable', 'string', 'max:30']],
            'address' => ['header' => 't.address', 'rules' => ['nullable', 'string', 'max:200']],
            'type' => ['header' => 't.type', 'rules' => ['required']],
            'status' => ['header' => 't.status', 'rules' => ['required']],
            'amperage' => ['header' => 't.amperage_a', 'rules' => ['nullable', 'integer', 'min:1', 'max:200']],
            'maintenance_mode' => ['header' => 'imp.col_mode', 'rules' => ['required']],
            'maintenance_fee' => ['header' => 't.maint_fee', 'rules' => ['nullable', 'numeric', 'min:0']],
            'flat_fee' => ['header' => 't.flat_fee', 'rules' => ['nullable', 'numeric', 'min:0']],
            'notes' => ['header' => 's.notes', 'rules' => ['nullable', 'string', 'max:1000']],
            // Appended rather than inserted: the columns before these are the ones the office already
            // knows, and a heading that moves is a heading somebody fills in wrong.
            'opening_reading' => ['header' => 'imp.col_opening_reading', 'rules' => ['nullable', 'numeric', 'min:0']],
            'opening_period' => ['header' => 'imp.col_opening_period', 'rules' => ['nullable']],
        ];
    }

    /**
     * The header row the template and the export carry, in the panel's language.
     *
     * A plain LIST, not a map keyed by column: this is written straight into a CSV line and compared
     * position by position, and `array_map` over a string-keyed array keeps the keys.
     */
    public static function headers(): array
    {
        return array_values(array_map(fn (array $column): string => __($column['header']), self::columns()));
    }

    /** Every spelling of a heading we accept: both languages, plus the raw column key. */
    private static function headerWords(string $key, array $column): array
    {
        $words = [$key];

        foreach (['ar', 'en'] as $locale) {
            $words[] = trans($column['header'], [], $locale);
        }

        return $words;
    }

    /**
     * What the operator may type in an enum column, in either language.
     *
     * A file filled from the Arabic template must still import while the panel is in English, and a
     * technical operator may prefer the raw value, so all three spellings resolve to the same code.
     *
     * @return array<string, array<string, string>>  column => [normalised word => code]
     */
    public static function valueAliases(): array
    {
        return [
            'type' => self::aliasesFor('s.metered', 's.flat_rate', Subscriber::TYPE_METERED, Subscriber::TYPE_FLAT),
            'status' => self::aliasesFor('portal.active', 'portal.inactive', Subscriber::STATUS_ACTIVE, Subscriber::STATUS_INACTIVE),
            'maintenance_mode' => self::aliasesFor('imp.mode_custom', 'imp.mode_exempt', Subscriber::MAINT_CUSTOM, Subscriber::MAINT_EXEMPT, autoKey: 'imp.mode_auto'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function aliasesFor(string $firstKey, string $secondKey, string $firstValue, string $secondValue, ?string $autoKey = null): array
    {
        $map = [];

        foreach ([[$firstKey, $firstValue], [$secondKey, $secondValue]] as [$key, $value]) {
            $map[CsvFile::word($value)] = $value;

            foreach (['ar', 'en'] as $locale) {
                $word = trans($key, [], $locale);
                $map[CsvFile::word($word)] = $value;
            }
        }

        if ($autoKey !== null) {
            $map[CsvFile::word(Subscriber::MAINT_AUTO)] = Subscriber::MAINT_AUTO;

            foreach (['ar', 'en'] as $locale) {
                $map[CsvFile::word(trans($autoKey, [], $locale))] = Subscriber::MAINT_AUTO;
            }
        }

        return $map;
    }

    /**
     * Read a CSV into raw rows keyed by column.
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
            return ['rows' => [], 'error' => __('imp.headers_missing', [
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

            // A line of empty commas is padding from a spreadsheet, not a subscriber.
            if (implode('', $row) === '') {
                continue;
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

        $existing = Subscriber::query()->get(['id', 'name', 'route_id', 'meter_no']);
        $byMeter = [];
        $byName = [];

        foreach ($existing as $subscriber) {
            if (filled($subscriber->meter_no)) {
                $byMeter[CsvFile::word($subscriber->meter_no)] = $subscriber->name;
            }

            $byName[$subscriber->route_id.'|'.CsvFile::word($subscriber->name)] = $subscriber->name;
        }

        $seen = [];
        $previewed = [];

        foreach ($rows as $index => $row) {
            $number = $index + 1;
            $errors = [];
            $attributes = self::attributes($row, $routes, $errors);

            if ($errors === []) {
                $meter = CsvFile::word((string) ($attributes['meter_no'] ?? ''));
                $nameKey = $attributes['route_id'].'|'.CsvFile::word((string) $attributes['name']);
                $key = $meter !== '' ? 'meter:'.$meter : 'name:'.$nameKey;

                if ($meter !== '' && isset($byMeter[$meter])) {
                    // The panel already knows this meter. The row is refused, never merged: the office
                    // asked for «ما بينمحى ولا بينتعدّل إنسان».
                    $errors[] = __('imp.exists_meter', ['name' => $byMeter[$meter], 'meter' => $attributes['meter_no']]);
                } elseif ($meter === '' && isset($byName[$nameKey])) {
                    $errors[] = __('imp.exists_name', ['name' => $attributes['name'], 'route' => $attributes['route_name'] ?? '']);
                } elseif (isset($seen[$key])) {
                    $errors[] = __('imp.duplicate_in_file', ['row' => $seen[$key]]);
                } else {
                    $seen[$key] = $number;
                }
            }

            $previewed[] = [
                'row' => $number,
                'status' => $errors === [] ? 'ok' : 'problem',
                'errors' => $errors,
                'attributes' => $attributes,
                'name' => $attributes['name'] ?? ($row['name'] ?? ''),
                'route' => $attributes['route_name'] ?? ($row['route'] ?? ''),
                'type' => $attributes['type'] ?? null,
            ];
        }

        return $previewed;
    }

    /**
     * Write the rows that passed the preview.
     *
     * Per row, in its own transaction: one subscriber failing must not sink the eighty that are fine,
     * and the failure is reported with its reason rather than swallowed.
     *
     * @param  array<int, array<string, mixed>>  $previewed
     * @return array{created: int, failed: array<int, array<string, mixed>>}
     */
    public static function commit(array $previewed, ?int $userId = null): array
    {
        $created = 0;
        $failed = [];

        foreach ($previewed as $row) {
            if ($row['status'] !== 'ok') {
                continue;
            }

            $attributes = $row['attributes'];
            unset($attributes['route_name']);
            $attributes['created_by'] = $userId ?? auth()->id();

            try {
                DB::transaction(fn () => Subscriber::create($attributes));
                $created++;
            } catch (\Throwable $e) {
                $failed[] = ['row' => $row['row'], 'name' => $row['name'], 'reason' => $e->getMessage()];
            }
        }

        if ($created > 0) {
            Audit::log('subscriber.imported', null, [
                'created' => $created,
                'refused' => count($previewed) - $created,
                'failed' => count($failed),
            ]);
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * Turn one raw row into attributes, or collect the reasons it cannot be one.
     *
     * @param  array<string, array<int, string>>  $routes
     * @param  array<int, string>  $errors
     * @return array<string, mixed>
     */
    private static function attributes(array $row, array $routes, array &$errors): array
    {
        $simple = [];
        $labels = [];

        foreach (self::columns() as $key => $column) {
            // The label a person reads in the message, not the language key behind it.
            $labels[$key] = __($column['header']);
        }

        foreach (['name', 'meter_no', 'phone', 'address', 'notes'] as $key) {
            $simple[$key] = $row[$key] !== '' ? $row[$key] : null;
        }

        $simple['amperage'] = $row['amperage'] !== '' ? (int) CsvFile::number($row['amperage']) : null;

        // The dial as it stood when the office took the subscriber on. Without it the first cycle
        // bills the whole counter, so a file that carries it is a file that bills correctly.
        $opening = CsvFile::number($row['opening_reading'] ?? '');
        $simple['opening_reading'] = $opening === '' ? null : (float) $opening;

        $rawPeriod = trim((string) ($row['opening_period'] ?? ''));
        $period = Subscriber::cleanOpeningPeriod($rawPeriod);

        if ($rawPeriod !== '' && $period === null) {
            $errors[] = __('imp.period_invalid', ['value' => $rawPeriod]);
        }

        // A reading with no month belongs to the month the file is being imported in.
        if ($simple['opening_reading'] !== null && $period === null) {
            $period = now()->format('Y-m');
        }

        $simple['opening_period'] = $simple['opening_reading'] === null ? null : $period;

        // The route is chosen by name — the operator knows the neighbourhood, not the database id.
        $routeName = trim($row['route']);
        $routeId = $routeName === '' ? null : ($routes[CsvFile::word($routeName)] ?? null);
        $simple['route_id'] = $routeId;
        $simple['route_name'] = $routeName;

        if ($routeId === null) {
            $errors[] = $routeName === ''
                ? __('imp.route_required')
                : __('imp.route_unknown', ['route' => $routeName]);
        }

        foreach (self::valueAliases() as $key => $aliases) {
            $word = CsvFile::word($row[$key]);
            $resolved = $aliases[$word] ?? null;

            if ($resolved === null) {
                $errors[] = __('imp.value_unknown', ['column' => $labels[$key], 'value' => $row[$key]]);

                continue;
            }

            $simple[$key] = $resolved;
        }

        $isFlat = ($simple['type'] ?? null) === Subscriber::TYPE_FLAT;
        $fee = CsvFile::number($row['maintenance_fee']);
        $flatFee = CsvFile::number($row['flat_fee']);

        $simple['maintenance_fee'] = (! $isFlat && ($simple['maintenance_mode'] ?? null) === Subscriber::MAINT_CUSTOM)
            ? (float) ($fee === '' ? 0 : $fee)
            : 0.0;
        $simple['flat_fee'] = $isFlat ? (float) ($flatFee === '' ? 0 : $flatFee) : null;

        // The form's shape, kept: a flat subscriber carries no meter, and a metered one carries no
        // flat fee — otherwise the list prints a meter for a house that has not had one for months.
        if ($isFlat) {
            // A flat subscriber has no dial, so he has no reading to start from either.
            $simple['meter_no'] = null;
            $simple['amperage'] = null;
            $simple['opening_reading'] = null;
            $simple['opening_period'] = null;
        }

        $validator = Validator::make($simple, [
            'name' => ['required', 'string', 'max:150'],
            'meter_no' => ['nullable', 'string', 'max:40'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:200'],
            'amperage' => ['nullable', 'integer', 'min:1', 'max:200'],
            'maintenance_fee' => ['nullable', 'numeric', 'min:0'],
            'flat_fee' => ['nullable', 'numeric', 'min:0'],
        ], [
            'flat_fee' => __('imp.flat_fee_required'),
        ], $labels);

        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }

        if ($isFlat && (float) $simple['flat_fee'] <= 0) {
            $errors[] = __('imp.flat_fee_required');
        }

        return $simple;
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

            $attributes = $row['attributes'];
            $line = [$row['row']];

            foreach (array_keys(self::columns()) as $key) {
                $line[] = match ($key) {
                    'route' => $attributes['route_name'] ?? '',
                    'type' => $attributes['type'] ?? '',
                    'status' => $attributes['status'] ?? '',
                    'maintenance_mode' => $attributes['maintenance_mode'] ?? '',
                    default => (string) ($attributes[$key] ?? ''),
                };
            }

            $line[] = implode(' | ', $row['errors']);
            fputcsv($handle, $line);
        }

        // Most refusals are «منطقة غير معروفة»; naming the routes the panel actually has turns a dead
        // end into an edit the office can make in this same file.
        fputcsv($handle, []);
        fputcsv($handle, [__('imp.known_routes'), Route::query()->orderBy('name')->pluck('name')->implode(' · ')]);

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The three words a file may use for a value — and the words the export writes back.
     *
     * One pair of functions for both directions, so a subscriber exported and re-imported matches
     * itself. A test walks every constant through this and back through the aliases.
     */
    public static function typeLabel(?string $code): string
    {
        return match ($code) {
            Subscriber::TYPE_FLAT => __('s.flat_rate'),
            default => __('s.metered'),
        };
    }

    public static function statusLabel(?string $code): string
    {
        return match ($code) {
            Subscriber::STATUS_INACTIVE => __('portal.inactive'),
            default => __('portal.active'),
        };
    }

    public static function modeLabel(?string $code): string
    {
        return match ($code) {
            Subscriber::MAINT_CUSTOM => __('imp.mode_custom'),
            Subscriber::MAINT_EXEMPT => __('imp.mode_exempt'),
            default => __('imp.mode_auto'),
        };
    }

    /** The template: the headings plus one metered and one flat example, so the shape is obvious. */
    public static function template(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::headers());
        fputcsv($handle, [
            'علي شرارة', 'عين الرمانة', 'M-1051', '03123456', 'شارع المدرسة', __('s.metered'),
            __('portal.active'), '10', __('imp.mode_auto'), '', '', '', '12500', now()->format('Y-m'),
        ]);
        fputcsv($handle, [
            'سامي دندشي', 'عين الرمانة', '', '03777888', '', __('s.flat_rate'),
            __('portal.active'), '', __('imp.mode_custom'), '', '25', __('imp.example_flat_note'), '', '',
        ]);
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Match the file's headings to the columns.
     *
     * @return array<int, string>|null  cell index => column key, or null when the file is not ours
     */
    private static function mapHeader(array $cells): ?array
    {
        $known = [];

        foreach (self::columns() as $key => $column) {
            // Both languages on purpose: the office fills the template in whatever language they
            // think in, and a file must not be refused because the panel is set to the other one.
            foreach (self::headerWords($key, $column) as $word) {
                $known[CsvFile::word($word)] = $key;
            }
        }

        $map = [];

        foreach ($cells as $index => $cell) {
            $word = CsvFile::word((string) $cell);

            if (isset($known[$word])) {
                $map[$index] = $known[$word];
            }
        }

        // The three columns without which a row cannot become a subscriber.
        foreach (['name', 'route', 'type'] as $required) {
            if (! in_array($required, $map, true)) {
                return null;
            }
        }

        return $map;
    }

    private static function cleanCell(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** Numbers off a spreadsheet arrive as `$1,200.50` — or in Arabic-Indic digits. */
    public static function number(string $value): string
    {
        $value = CsvFile::clean($value);
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return trim(str_replace(['$', ',', ' '], '', $value));
    }

    /** Lower-case, single-spaced, no trailing star — how a human types a heading vs how we print it. */
    private static function normaliseWord(string $value): string
    {
        $value = str_replace('*', '', CsvFile::clean($value));

        return mb_strtolower($value, 'UTF-8');
    }
}
