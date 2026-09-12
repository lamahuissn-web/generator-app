<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use App\Reports\SubscriberFile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SubscriberController extends Controller
{
    public function index(): View
    {
        return view('admin.subscribers.index');
    }

    public function create(): View
    {
        return view('admin.subscribers.form', ['subscriber' => null]);
    }

    public function edit(Subscriber $subscriber): View
    {
        return view('admin.subscribers.form', ['subscriber' => $subscriber]);
    }

    /**
     * A subscriber's file: identity and actions on top, then the account read as of ONE cycle —
     * what was billed, what was paid, what is owed — with the three records it is made of
     * (readings, bills, receipts) below it, each scoped to the same cycle.
     *
     * The cycle is a query string rather than page state: the office sends each other the link
     * ("شوف آب"), and the back button has to walk the cycles that were looked at. An unknown or
     * absent value means the whole account, which is also what a bare /subscribers/{id} opens.
     */
    public function show(Request $request, Subscriber $subscriber): View
    {
        $subscriber->load('route:id,name');

        $periods = (new SubscriberFile($subscriber))->periods();

        $period = $request->query('period');
        $period = is_string($period) && array_key_exists($period, $periods) ? $period : null;

        $file = new SubscriberFile($subscriber, $period);

        return view('admin.subscribers.show', [
            'subscriber' => $subscriber,
            'file' => $file,
            'periods' => $periods,
            'period' => $period,
            'totals' => $file->totals(),
            'counts' => $file->counts(),
            'openBills' => $file->openBillCount(),
        ]);
    }
}
