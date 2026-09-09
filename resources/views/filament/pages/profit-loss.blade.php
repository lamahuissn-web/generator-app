<x-filament-panels::page>
    {{ $this->form }}

    @php($r = $this->report())

    {{-- Stat cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem">
        <div class="gx-card">
            <div class="gx-card-label">Invoiced</div>
            <div class="gx-stat-value">{{ money($r['invoiced']) }}</div>
            <div class="gx-stat-sub">Bills issued in {{ $r['label'] }}</div>
        </div>
        <div class="gx-card">
            <div class="gx-card-label">Cash collected</div>
            <div class="gx-stat-value gx-pos">{{ money($r['collected']) }}</div>
            <div class="gx-stat-sub">Collection rate {{ $r['rate'] }}%</div>
        </div>
        <div class="gx-card">
            <div class="gx-card-label">Operating expenses</div>
            <div class="gx-stat-value gx-neg">{{ money($r['expenses']) }}</div>
            <div class="gx-stat-sub">{{ count($r['byCategory']) }} categories</div>
        </div>
        <div class="gx-card {{ $r['net'] >= 0 ? 'gx-pos-bg' : 'gx-neg-bg' }}">
            <div class="gx-card-label">Net profit</div>
            <div class="gx-stat-value {{ $r['net'] >= 0 ? 'gx-pos' : 'gx-neg' }}">{{ money($r['net']) }}</div>
            <div class="gx-stat-sub">Collected − expenses</div>
        </div>
    </div>

    {{-- Breakdown --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;margin-top:1rem">
        <div class="gx-card">
            <h4 class="gx-title">Expenses by category</h4>
            @forelse ($r['byCategory'] as $c)
                <div class="gx-feed-item">
                    <span class="gx-feed-meta">{{ $c['label'] }} <span style="color:#94a3b8">({{ $c['count'] }})</span></span>
                    <span class="gx-feed-amount">{{ money($c['total']) }}</span>
                </div>
            @empty
                <p class="gx-empty">No expenses recorded this month.</p>
            @endforelse
            @if ($r['byCategory'])
                <div class="gx-feed-item">
                    <span class="gx-feed-name">Total</span>
                    <span class="gx-feed-amount gx-feed-neg">{{ money($r['expenses']) }}</span>
                </div>
            @endif
        </div>

        <div class="gx-card">
            <h4 class="gx-title">Latest expenses</h4>
            @forelse ($r['recentExpenses'] as $e)
                <div class="gx-feed-item">
                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <span class="gx-feed-meta">
                            {{ $e->expense_date->format('M j') }} · {{ \App\Models\Expense::$categories[$e->category] ?? $e->category }}
                            @if ($e->description) — {{ $e->description }} @endif
                        </span>
                    </span>
                    <span class="gx-feed-amount">{{ money($e->amount) }}</span>
                </div>
            @empty
                <p class="gx-empty">Nothing this month yet.</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
