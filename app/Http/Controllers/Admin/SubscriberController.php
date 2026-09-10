<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
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
     * The subscriber profile: identity and balance on top, then the three records that
     * make up their account (readings, bills, payments) as tabs.
     *
     * The tabs are the same DataTable component the list pages use, handed a constraint,
     * so a bill row behaves identically here and on /bills.
     */
    public function show(Subscriber $subscriber): View
    {
        $subscriber->load('route:id,name');

        $tab = request('tab', 'bills');
        if (! in_array($tab, ['bills', 'readings', 'payments'], true)) {
            $tab = 'bills';
        }

        return view('admin.subscribers.show', [
            'subscriber' => $subscriber,
            'tab' => $tab,
            'outstanding' => $subscriber->outstanding(),
            'unpaidCount' => $subscriber->bills()->whereRaw('paid_amount < total')->count(),
        ]);
    }
}
