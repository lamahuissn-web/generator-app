<?php

namespace App\Livewire\Collector;

use App\Models\Subscriber;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $q = '';

    #[Computed]
    public function results(): array
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return [];
        }

        $subscribers = Subscriber::query()
            ->where(function ($query) {
                $like = '%'.trim($this->q).'%';
                $query->where('name', 'like', $like)
                    ->orWhere('meter_no', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->with('route:id,name')
            ->orderBy('name')
            ->limit(8)
            ->get();

        $owed = DB::table('bills as b')
            ->selectRaw('b.subscriber_id, COALESCE(SUM(b.total - b.paid_amount), 0) as o')
            ->whereRaw('b.paid_amount < b.total')
            ->whereIn('b.subscriber_id', $subscribers->pluck('id'))
            ->groupBy('b.subscriber_id')
            ->pluck('o', 'subscriber_id');

        return $subscribers->map(fn (Subscriber $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'meter_no' => $s->meter_no,
            'type' => $s->type,
            'status' => $s->status,
            'route' => $s->route?->name,
            'owed' => (float) ($owed[$s->id] ?? 0),
        ])->all();
    }

    public function render()
    {
        return view('livewire.collector.global-search');
    }
}
