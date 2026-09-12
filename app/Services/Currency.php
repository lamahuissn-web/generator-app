<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;

/**
 * Lira, and the one rate the panel converts with.
 *
 * The app bills in dollars, but the street pays in lira, and until now the only trace of that was
 * a payment METHOD ("نقداً ليرة") with a dollar amount beside it — a collector converted in their
 * head, wrote the dollar figure, and the lira figure was gone. The office could not answer "أكم
 * قيمتها ليرة اليوم؟" for a debt, nor "كم قبضنا ليرة هذا الشهر؟" for a run.
 *
 * Everything that needs a rate reads it from HERE, so the settings screen, the file's cards, a
 * receipt and a report can never quote two different liras for the same dollar.
 *
 * TWO CONVERSIONS, ON PURPOSE:
 *   - exact() is money that exists (what a receipt is worth when it is stored, what a report adds
 *     up). Nothing is invented.
 *   - display() is what a person writes down: lira in the round numbers the street uses, because
 *     "$614.23 = LBP 54,973,585" is precision nobody asks for and nobody can pay. The ballpark is
 *     the nearest thousand — one line to change here if the office rounds differently.
 */
final class Currency
{
    /** Setting key: how many lira one dollar is worth. 0 = the operator has not set a rate. */
    public const RATE_KEY = 'lbp_rate';

    public static function rate(): float
    {
        return round((float) Setting::get(self::RATE_KEY, 0), 2);
    }

    /** Without a rate the panel shows nothing in lira, rather than a made-up zero. */
    public static function isConfigured(): bool
    {
        return self::rate() > 0;
    }

    /** The exact lira value of a dollar amount: money, not a caption. */
    public static function exact(float|int|string|null $usd): float
    {
        return round((float) $usd * self::rate(), 0);
    }

    /** The same amount in the rounded lira a person would say out loud. */
    public static function display(float|int|string|null $usd): float
    {
        return round(self::exact($usd) / 1000) * 1000;
    }

    /** 'LBP 9,850,000' — the label used wherever a lira figure is shown. */
    public static function label(float|int|string|null $usd): string
    {
        return 'LBP '.number_format(self::display($usd), 0, '.', ',');
    }

    /**
     * Is lira part of this office's bookkeeping at all?
     *
     * True when a rate is configured, OR when receipts taken in lira already exist (an office that
     * stopped converting still has last month's lira in its books, and a column that hides real
     * money because a setting changed would be lying).
     *
     * Deliberately NOT memoised: the answer changes the moment a lira receipt is written, and a
     * cached "no" would keep the column hidden for the rest of the process — which is exactly the
     * kind of stale answer a long-running worker (or a test) would never notice.
     */
    public static function liraInUse(): bool
    {
        return self::isConfigured() || Payment::query()->whereNotNull('lbp_amount')->exists();
    }
}
