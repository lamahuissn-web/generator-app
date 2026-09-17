<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Subscriber;
use App\Services\BillingService;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The operator's landing screen.
 *
 * Every figure here is the same expression the old dashboard widget used, so the two
 * admins cannot disagree about the month while both are reachable.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $period = now()->format('Y-m');
        $previous = now()->subMonthNoOverflow();
        $prevPeriod = $previous->format('Y-m');

        $invoiced = (float) Bill::query()->where('period', $period)->sum('total');
        // The day the money was collected, not the day it was typed in: this rate says how much of
        // the month's billing actually came in during that month.
        $collected = (float) Payment::query()
            ->collectedInRange(now()->copy()->startOfMonth()->toDateString(), now()->copy()->endOfMonth()->toDateString())
            ->sum('amount');
        $rate = $invoiced > 0 ? round($collected / $invoiced * 100, 1) : 0.0;

        // Exempt houses are outside the chase (see Feature 004): the office said it does not claim from
        // them, so their old debt is not part of what the panel is working on. It stays on their own file.
        $outstanding = (float) Bill::query()
            ->whereHas('subscriber', fn ($query) => $query->whereNull('exempt_at'))
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->whereRaw('paid_amount < total')
            ->value('o');

        // How much of that sits on STOPPED accounts. It is collected the same way (see principle
        // VI), but money nobody is chasing is money the panel is quietly not working on, and the
        // office cannot decide about a figure it cannot see.
        $inactiveOutstanding = (float) Bill::query()
            ->whereHas('subscriber', fn ($query) => $query->where('status', Subscriber::STATUS_INACTIVE)->whereNull('exempt_at'))
            ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as o')
            ->whereRaw('paid_amount < total')
            ->value('o');

        $active = Subscriber::query()->active();
        $activeCount = (clone $active)->count();
        $meteredCount = (clone $active)->metered()->count();

        $kwh = (float) Bill::query()->where('period', $period)->sum('consumption');

        $prevInvoiced = (float) Bill::query()->where('period', $prevPeriod)->sum('total');
        $prevCollected = (float) Payment::query()
            ->collectedInRange($previous->copy()->startOfMonth()->toDateString(), $previous->copy()->endOfMonth()->toDateString())
            ->sum('amount');
        $prevRate = $prevInvoiced > 0 ? round($prevCollected / $prevInvoiced * 100, 1) : 0.0;

        $tariff = BillingService::kwhPrice($period);
        $tariffSource = BillingService::priceSource($period);

        $stats = [
            [
                'label' => __('dash.outstanding'),
                'value' => money($outstanding),
                'sub' => $inactiveOutstanding > 0
                    ? __('dash.outstanding_sub_inactive', ['amount' => money($inactiveOutstanding)])
                    : __('dash.outstanding_sub'),
                'icon' => 'ki-information',
                'tone' => 'danger',
            ],
            [
                'label' => __('dash.rate'),
                'value' => $rate.'%',
                // A percent sign and a leading sign are bidi-NEUTRAL, so inside an Arabic
                // sentence "36.2%" renders as "%36.2" and "-28.9%" as "%28.9-". The isolate
                // keeps each figure and its sign together; Western digits are unaffected.
                'sub' => __('dash.rate_sub', [
                    'prev' => ltr_isolate($prevRate.'%'),
                    'delta' => ltr_isolate(($rate - $prevRate >= 0 ? '+' : '').round($rate - $prevRate, 1)),
                ]),
                'icon' => 'ki-chart-line-down',
                'tone' => $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger'),
            ],
            [
                'label' => __('dash.collected'),
                'value' => money($collected),
                'sub' => now()->translatedFormat('M Y'),
                'icon' => 'ki-wallet',
                'tone' => 'success',
            ],
            [
                'label' => __('dash.invoiced', ['period' => period_label($period)]),
                'value' => money($invoiced),
                'sub' => $prevInvoiced > 0
                    ? __('dash.vs_previous', [
                        'delta' => ltr_isolate(round(($invoiced - $prevInvoiced) / $prevInvoiced * 100, 1).'%'),
                    ])
                    : __('dash.total_billed'),
                'icon' => 'ki-bill',
                'tone' => 'info',
            ],
            [
                'label' => __('dash.tariff', ['period' => period_label($period)]),
                'value' => money($tariff),
                'sub' => $tariffSource === 'tariff' ? __('dash.tariff_cycle') : __('dash.tariff_global'),
                'icon' => 'ki-dollar',
                'tone' => $tariffSource === 'tariff' ? 'warning' : 'muted',
            ],
            [
                'label' => __('dash.kwh'),
                'value' => number_format($kwh, 1),
                'sub' => period_label($period),
                'icon' => 'ki-flash-circle',
                'tone' => 'muted',
            ],
            [
                'label' => __('dash.active'),
                'value' => (string) $activeCount,
                'sub' => __('dash.active_sub', ['metered' => $meteredCount, 'flat' => $activeCount - $meteredCount]),
                'icon' => 'ki-users',
                'tone' => 'info',
            ],
        ];

        $recentPayments = Payment::query()
            ->with(['subscriber:id,name', 'collector:id,name'])
            ->latest('created_at')
            ->limit(8)
            ->get();

        $topDebtors = DB::table('bills')
            ->join('subscribers', 'subscribers.id', '=', 'bills.subscriber_id')
            ->leftJoin('routes', 'routes.id', '=', 'subscribers.route_id')
            ->selectRaw('subscribers.id as subscriber_id, subscribers.name as subscriber_name, routes.name as route_name, COALESCE(SUM(bills.total - bills.paid_amount), 0) as owed, COUNT(*) as bills_open')
            ->whereRaw('bills.paid_amount < bills.total')
            ->whereNull('subscribers.exempt_at')
            ->groupBy('subscribers.id', 'subscribers.name', 'routes.name')
            ->orderByDesc('owed')
            ->limit(8)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentPayments', 'topDebtors', 'period'));
    }
}
