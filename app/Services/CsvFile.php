<?php

namespace App\Services;

/**
 * Reading a spreadsheet a human actually produced.
 *
 * Excel writes Arabic as UTF-8, CP1256 or UTF-16 depending on the machine and the save dialog, the
 * delimiter depends on the locale, and a number arrives as `$1,200.50` or in Arabic-Indic digits. All
 * of that is the same work for every importer in the panel, so it lives here once: an importer says
 * what columns it wants, and this turns the bytes into rows.
 */
class CsvFile
{
    /**
     * The file as rows of cells, with the encoding, the delimiter and the padding already dealt with.
     *
     * @return array<int, array<int, string>>  blank lines dropped, every cell trimmed
     */
    public static function lines(string $path): array
    {
        $contents = self::toUtf8((string) file_get_contents($path));

        if (trim($contents) === '') {
            return [];
        }

        $delimiter = self::delimiter($contents);
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $contents) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $cells = array_map(
                fn ($cell): string => self::clean((string) $cell),
                str_getcsv($line, $delimiter, '"', '\\'),
            );

            // A line of empty commas is padding from a spreadsheet, not a record.
            if (implode('', $cells) === '') {
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** Excel writes Arabic as UTF-8, Windows-1256 or UTF-16 — accept all three. */
    public static function toUtf8(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return (string) substr($contents, 3);
        }

        if (str_starts_with($contents, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($contents, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($contents, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }

        // The Arabic Windows codepage that Excel writes is a name iconv and mbstring DISAGREE about —
        // this build's mbstring does not know `CP1256` at all and throws when handed it on PHP 8, so
        // iconv goes first and mbstring is asked only for names it actually lists.
        if (function_exists('iconv')) {
            foreach (['CP1256', 'Windows-1256', 'CP1252', 'Windows-1252'] as $from) {
                $converted = @iconv($from, 'UTF-8//IGNORE', $contents);

                if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                    return $converted;
                }
            }
        }

        $known = array_map('strtoupper', mb_list_encodings());

        foreach (['ISO-8859-6'] as $from) {
            if (! in_array(strtoupper($from), $known, true)) {
                continue;
            }

            $converted = @mb_convert_encoding($contents, 'UTF-8', $from);

            if (is_string($converted) && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        return $contents;
    }

    /** Comma, semicolon or tab — whichever the first line uses most. */
    public static function delimiter(string $contents): string
    {
        $firstLine = preg_split('/\r\n|\n|\r/', $contents)[0] ?? '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    public static function clean(string $value): string
    {
        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** Numbers off a spreadsheet arrive as `$1,200.50` — or in Arabic-Indic digits. */
    public static function number(string $value): string
    {
        $value = self::clean($value);
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return trim(str_replace(['$', ',', ' '], '', $value));
    }

    /** Lower-case, single-spaced, no trailing star — how a human types a heading vs how we print it. */
    public static function word(string $value): string
    {
        return mb_strtolower(str_replace('*', '', self::clean($value)), 'UTF-8');
    }
}
