<x-filament-panels::page>
    {{ $this->form }}

    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <p class="gx-strip">
            Unpaid bills aged by their oldest outstanding period vs. now.
            <span class="gx-age-red">{{ money($this->summary()['red']) }}</span> red (2+ months) ·
            <span class="gx-warn-text" style="font-weight:600">{{ money($this->summary()['yellow']) }}</span> yellow (1 month)
        </p>
        <button type="button" class="gx-btn" onclick="window.print()">Print / PDF</button>
    </div>

    <div style="margin-top:12px" class="gx-table-wrap">
        <table class="gx-table">
            <thead>
                <tr>
                    <th>Subscriber</th>
                    <th>Route</th>
                    <th>Age</th>
                    <th style="text-align:right">Total due</th>
                    <th>Since</th>
                    <th>Phone</th>
                </tr>
            </thead>
            <tbody>
                @php($rows = $this->rows())
                @forelse ($rows as $r)
                    <tr>
                        <td>
                            <a href="{{ \App\Filament\Resources\SubscriberResource::getUrl('view', ['record' => $r['id']]) }}" class="gx-link">{{ $r['name'] }}</a>
                            @if ($r['status'] === 'inactive')
                                <span class="gx-chip">inactive</span>
                            @endif
                        </td>
                        <td class="gx-cell-muted">{{ $r['route'] ?? '—' }}</td>
                        <td>
                            @if ($r['bucket'] === 'red')
                                <span><span class="gx-dot gx-dot-red"></span><span class="gx-age-red">2+ months</span></span>
                            @elseif ($r['bucket'] === 'yellow')
                                <span><span class="gx-dot gx-dot-amber"></span><span class="gx-age">1 month</span></span>
                            @elseif ($r['bucket'] === 'current')
                                <span><span class="gx-dot gx-dot-sky"></span><span class="gx-age">Due this month</span></span>
                            @else
                                <span><span class="gx-dot gx-dot-green"></span><span class="gx-age">Settled</span></span>
                            @endif
                        </td>
                        <td class="gx-num {{ $r['bucket'] === 'red' ? 'gx-text-red' : 'gx-text-strong' }}">{{ money($r['due']) }}</td>
                        <td class="gx-cell-faint">{{ $r['oldest'] ? period_label($r['oldest']) : '—' }}</td>
                        <td class="gx-cell-muted">{{ $r['phone'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="gx-empty">Nothing matches this filter 🎉</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <td colspan="3">Total outstanding</td>
                        <td class="gx-num">{{ money(array_sum(array_column($rows, 'due'))) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
