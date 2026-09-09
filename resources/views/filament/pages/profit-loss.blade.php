<x-filament-panels::page>
    {{ $this->form }}

    @php($r = $this->report())

    {{-- Stat cards --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem">
        <div class="gx-card">
            <div class="gx-card-label">{{ __('pl.invoiced') }}</div>
            <div class="gx-stat-value">{{ money($r['invoiced']) }}</div>
            <div class="gx-stat-sub">{{ __('pl.inv_sub', ['label' => $r['label']]) }}</div>
        </div>
        <div class="gx-card">
            <div class="gx-card-label">{{ __('pl.cash_collected') }}</div>
            <div class="gx-stat-value gx-pos">{{ money($r['collected']) }}</div>
            <div class="gx-stat-sub">{{ __('pl.rate_sub', ['rate' => $r['rate']]) }}</div>
        </div>
        <div class="gx-card">
            <div class="gx-card-label">{{ __('pl.op_expenses') }}</div>
            <div class="gx-stat-value gx-neg">{{ money($r['expenses']) }}</div>
            <div class="gx-stat-sub">{{ __('pl.cat_sub', ['n' => count($r['byCategory'])]) }}</div>
        </div>
        <div class="gx-card {{ $r['net'] >= 0 ? 'gx-pos-bg' : 'gx-neg-bg' }}">
            <div class="gx-card-label">{{ __('pl.net_profit') }}</div>
            <div class="gx-stat-value {{ $r['net'] >= 0 ? 'gx-pos' : 'gx-neg' }}">{{ money($r['net']) }}</div>
            <div class="gx-stat-sub">{{ __('pl.net_sub') }}</div>
        </div>
    </div>

    {{-- Breakdown --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;margin-top:1rem">
        <div class="gx-card">
            <h4 class="gx-title">{{ __('pl.cat_title') }}</h4>
            @forelse ($r['byCategory'] as $c)
                <div class="gx-feed-item">
                    <span class="gx-feed-meta">{{ $c['label'] }} <span style="color:#94a3b8">({{ $c['count'] }})</span></span>
                    <span class="gx-feed-amount">{{ money($c['total']) }}</span>
                </div>
            @empty
                <p class="gx-empty">{{ __('pl.no_expenses') }}</p>
            @endforelse
            @if ($r['byCategory'])
                <div class="gx-feed-item">
                    <span class="gx-feed-name">{{ __('pl.total_lbl') }}</span>
                    <span class="gx-feed-amount gx-feed-neg">{{ money($r['expenses']) }}</span>
                </div>
            @endif
        </div>

        <div class="gx-card">
            <h4 class="gx-title">{{ __('pl.latest_title') }}</h4>
            @forelse ($r['recentExpenses'] as $e)
                <div class="gx-feed-item">
                    <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <span class="gx-feed-meta">
                            {{ $e->expense_date->format('M j') }} · {{ \App\Models\Expense::label($e->category) }}
                            @if ($e->description) — {{ $e->description }} @endif
                        </span>
                    </span>
                    <span class="gx-feed-amount">{{ money($e->amount) }}</span>
                </div>
            @empty
                <p class="gx-empty">{{ __('pl.nothing_yet') }}</p>
            @endforelse
        </div>
    </div>
</x-filament-panels::page>
