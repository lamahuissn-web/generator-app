<?php

namespace App\Http\Controllers\Collector;

use App\Http\Controllers\Controller;
use App\Models\Subscriber;
use Illuminate\View\View;

class SubscriberController extends Controller
{
    public function show(Subscriber $subscriber): View
    {
        return view('collector.subscriber', compact('subscriber'));
    }
}
