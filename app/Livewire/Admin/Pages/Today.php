<?php

namespace App\Livewire\Admin\Pages;

use App\Reports\TodayQueue;
use Livewire\Component;

/**
 * The office's day: what is unfinished, and the one screen that closes each thing.
 *
 * A Livewire page rather than a plain controller because the operator leaves this open while
 * they work — a handover recorded in another tab, a reading entered by a collector on the
 * street — and "تحديث" is the whole interaction the screen needs. Everything else is read.
 */
class Today extends Component
{
    public static function subheading(): string
    {
        return __('today.sub');
    }

    public function render()
    {
        $queue = TodayQueue::make();

        return view('livewire.admin.pages.today', [
            'period' => $queue->period(),
            'strip' => $queue->strip(),
            'items' => $queue->items(),
            'done' => $queue->done(),
        ]);
    }
}
