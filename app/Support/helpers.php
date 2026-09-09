<?php

use Carbon\Carbon;

if (! function_exists('money')) {
    /** Format a USD amount the way Lebanese generator operators expect: $1,234.50 */
    function money(float|int|string|null $value): string
    {
        return '$'.number_format((float) ($value ?? 0), 2, '.', ',');
    }
}

if (! function_exists('period_label')) {
    /** '2026-09' => 'Sep 2026' */
    function period_label(string $ym): string
    {
        return Carbon::createFromFormat('Y-m', $ym)->format('M Y');
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
