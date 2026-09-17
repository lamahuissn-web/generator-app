<?php

use App\Services\Currency;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! function_exists('money')) {
    /** Format a USD amount the way Lebanese generator operators expect: $1,234.50 */
    function money(float|int|string|null $value): string
    {
        return '$'.number_format((float) ($value ?? 0), 2, '.', ',');
    }
}

if (! function_exists('ltr_isolate')) {
    /**
     * Wrap a value in a Unicode LTR isolate (U+2066 … U+2069).
     *
     * Meter numbers, invoice numbers and $ amounts are Latin/numeric runs dropped
     * into Arabic sentences. Without isolation the bidi algorithm re-orders them
     * against the surrounding right-to-left text, which can strand a value away
     * from the label it belongs to ("العداد 2202 - 10A" reading as two tokens).
     * The isolate is invisible and survives HTML escaping, so it also works for
     * strings assembled in PHP and echoed with {{ }}.
     */
    function ltr_isolate(string|int|float|null $value): string
    {
        return "\u{2066}".$value."\u{2069}";
    }
}

if (! function_exists('money_ltr')) {
    /**
     * money() wrapped in an LTR isolate.
     *
     * Dropped into an Arabic sentence, a bare "$100.00" renders as "100.00$": the
     * currency sign is a neutral character in the Unicode bidi algorithm, so it
     * follows the surrounding right-to-left run instead of staying with its digits.
     *
     * Use this only where an amount is interpolated INTO translated prose; plain
     * amounts rendered straight into markup ({{ money($x) }}) need no isolation.
     */
    function money_ltr(float|int|string|null $value): string
    {
        return ltr_isolate(money($value));
    }
}

if (! function_exists('lbp')) {
    /**
     * A dollar amount in lira: 'LBP 9,850,000', or null when no rate has been set.
     *
     * Nullable on purpose, and null rather than an empty string: a screen must be able to tell
     * "this subscriber owes nothing in lira" from "nobody has told the panel what a dollar is
     * worth", and only the second one should keep the lira line off the page entirely.
     */
    function lbp(float|int|string|null $usd): ?string
    {
        return Currency::isConfigured() ? Currency::label($usd) : null;
    }
}

if (! function_exists('lbp_ltr')) {
    /** lbp() wrapped in an LTR isolate, for a lira figure interpolated into Arabic prose. */
    function lbp_ltr(float|int|string|null $usd): ?string
    {
        return ($label = lbp($usd)) === null ? null : ltr_isolate($label);
    }
}

if (! function_exists('reading_pair')) {
    /**
     * A meter's two numbers as one readable cell: "4478 → 4512".
     *
     * Isolated as a WHOLE rather than number by number. The arrow is a neutral character in the
     * Unicode bidi algorithm, so in an Arabic sentence it flips and the pair reads backwards —
     * which is the one mistake this cell exists to prevent ("هل قرأه طالع ولا نازل؟"). Wrapping
     * the pair keeps its left-to-right order wherever it is dropped.
     */
    function reading_pair(float|int|string|null $previous, float|int|string|null $current): string
    {
        return ltr_isolate(
            number_format((float) $previous, 0, '.', '').' → '.number_format((float) $current, 0, '.', '')
        );
    }
}

if (! function_exists('plural')) {
    /**
     * Counted label: pick the translation form that agrees with $count, then bind
     * ":n" to that same number.
     *
     * Arabic has six CLDR plural forms (zero, one, two, few, many, other), so a
     * key written as ":n منطقة" would print "3 منطقة" — a singular noun under a
     * plural count, which is plain wrong to an Arabic reader. Laravel's selector
     * already knows the Arabic rules, but only trans_choice() applies them and only
     * when the translation is written as pipe-separated forms in that exact order.
     *
     * Binding :n here rather than at each call site is deliberate: the number that
     * selects the form and the number printed must never drift apart.
     */
    function plural(string $key, int $count, array $replace = []): string
    {
        return trans_choice($key, $count, $replace + ['n' => $count]);
    }
}

if (! function_exists('tone_text')) {
    /**
     * Semantic tone -> Tailwind text colour.
     *
     * The class names are written out here rather than assembled at the call site
     * ("text-".$key) so they appear as literals in a file Tailwind scans: a class built by
     * string concatenation is invisible to the scanner and silently has no CSS.
     */
    function tone_text(string $tone): string
    {
        return match ($tone) {
            'danger' => 'text-destructive',
            'success' => 'text-green-700 dark:text-green-500',
            'warning' => 'text-amber-700 dark:text-amber-400',
            'info' => 'text-blue-700 dark:text-blue-400',
            default => 'text-mono',
        };
    }
}

if (! function_exists('period_label')) {
    /** '2026-09' => 'Sep 2026' */
    function period_label(string $ym): string
    {
        return Carbon::createFromFormat('Y-m', $ym)->format('M Y');
    }
}

if (! function_exists('period_label_i18n')) {
    /**
     * A billing period in the reader's language.
     *
     * Both labels already exist ('Sep 2026' / 'أيلول 2026'); this picks between them so a
     * picker built from period keys does not need to know which locale the panel is in.
     * Digits stay Latin either way, as everywhere else in this app.
     */
    function period_label_i18n(string $ym): string
    {
        return app()->isLocale('ar') ? period_label_ar($ym) : period_label($ym);
    }
}

if (! function_exists('month_names_ar')) {
    /**
     * The twelve months as this office says them: 1 => 'كانون الثاني' … 12 => 'كانون الأول'.
     *
     * Arabic calendar months are the Levantine names, not a transliteration of January — the office
     * reads 'تموز', never 'يوليو'. Kept here rather than inside one label so a picker, a heading and a
     * print all name a month the same way; anything that needs a month's NAME asks this.
     *
     * @return array<int, string>
     */
    function month_names_ar(): array
    {
        return [
            1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان',
            5 => 'أيار', 6 => 'حزيران', 7 => 'تموز', 8 => 'آب',
            9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
        ];
    }
}

if (! function_exists('month_names')) {
    /**
     * The twelve month names in the reader's language, WITHOUT a year: 1 => 'تموز' / 'July'.
     *
     * Used by the month picker, whose grid prints a month name under a year chosen separately, so a
     * label that carried its own year would say the year twice and contradict the arrows.
     *
     * @return array<int, string>
     */
    function month_names(): array
    {
        if (app()->isLocale('ar')) {
            return month_names_ar();
        }

        $names = [];

        for ($month = 1; $month <= 12; $month++) {
            $names[$month] = Carbon::create(2000, $month, 1)->format('F');
        }

        return $names;
    }
}

if (! function_exists('period_label_ar')) {
    /** '2026-09' => 'أيلول 2026' (Lebanese month names, Latin digits) */
    function period_label_ar(string $ym): string
    {
        $d = Carbon::createFromFormat('Y-m', $ym);

        return month_names_ar()[(int) $d->format('n')].' '.$d->format('Y');
    }
}

if (! function_exists('periods_with_data')) {
    /**
     * The cycles the ledger actually holds something for: a reading, or a bill.
     *
     * The month picker marks these with a dot, which is the difference between "this program has no
     * June" and "June is here and empty" — an operator looking at an empty month should know which of
     * the two they are looking at.
     *
     * Two indexed DISTINCT lookups, deliberately not cached in a static: a static would survive a
     * test's database refresh and start answering for a ledger that no longer exists.
     *
     * @return array<int, string>
     */
    function periods_with_data(): array
    {
        if (! Schema::hasTable('bills') || ! Schema::hasTable('readings')) {
            return [];
        }

        return DB::table('bills')->distinct()->pluck('period')
            ->merge(DB::table('readings')->distinct()->pluck('period'))
            ->unique()
            ->values()
            ->all();
    }
}

if (! function_exists('clean_email')) {
    /**
     * An email as the phone keyboard meant it.
     *
     * Android keyboards and autofill insert invisible characters into a typed address: bidi marks
     * and isolates (U+200E/U+200F/U+2066–U+2069), zero-width spaces, a non-breaking space, a space
     * after an accepted suggestion. Nothing looks wrong on screen, and the browser's own email
     * check refuses to submit — blaming a character the operator cannot see. Everything that
     * cannot legally appear in an address is stripped here, so "looks identical" becomes "is
     * identical" before the address is validated or used.
     */
    function clean_email(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stripped = preg_replace(
            '/[\x{0000}-\x{0020}\x{007F}-\x{009F}\x{00A0}\x{1680}\x{180E}\x{2000}-\x{200F}'
            .'\x{2028}-\x{202F}\x{205F}\x{2060}-\x{206F}\x{3000}\x{FEFF}]/u',
            '',
            (string) $value
        );

        return mb_strtolower($stripped ?? '', 'UTF-8');
    }
}
