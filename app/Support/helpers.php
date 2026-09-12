<?php

use Carbon\Carbon;

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

if (! function_exists('period_label_ar')) {
    /** '2026-09' => 'أيلول 2026' (Lebanese month names, Latin digits) */
    function period_label_ar(string $ym): string
    {
        $months = [
            1 => 'كانون الثاني', 2 => 'شباط', 3 => 'آذار', 4 => 'نيسان',
            5 => 'أيار', 6 => 'حزيران', 7 => 'تموز', 8 => 'آب',
            9 => 'أيلول', 10 => 'تشرين الأول', 11 => 'تشرين الثاني', 12 => 'كانون الأول',
        ];
        $d = Carbon::createFromFormat('Y-m', $ym);

        return $months[(int) $d->format('n')].' '.$d->format('Y');
    }
}

if (! function_exists('period_options')) {
    /**
     * 'Y-m' => label for the cycles an operator picks from: this month, 17 back, 3 forward.
     *
     * The window is deliberately lopsided. Everything the panel does — readings, bills,
     * payments, reports — happens on the cycle that just started or one of the recent ones; a
     * few months forward are there for pre-entering a reading the office already has on paper.
     * Further back than a year and a half is archaeology, and a picker nobody scrolls to the
     * end of is a picker that costs a scroll on every use.
     *
     * Shared by every screen with a cycle picker so adding one cannot quietly offer a
     * different range than the screen next to it.
     *
     * @return array<string, string>
     */
    function period_options(): array
    {
        $options = [now()->format('Y-m') => period_label_i18n(now()->format('Y-m'))];

        for ($i = 1; $i <= 17; $i++) {
            $key = now()->startOfMonth()->subMonths($i)->format('Y-m');
            $options[$key] = period_label_i18n($key);
        }

        for ($i = 1; $i <= 3; $i++) {
            $key = now()->startOfMonth()->addMonths($i)->format('Y-m');
            $options[$key] = period_label_i18n($key);
        }

        return $options;
    }
}
