<x-filament-panels::page>
    {{ $this->form }}

    <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <p class="gx-strip">
            {{ __('over.summary') }}
            <span class="gx-age-red">{{ __('over.red_part', ['amount' => money($this->summary()['red'])]) }}</span> ·
            <span class="gx-warn-text" style="font-weight:600">{{ __('over.yellow_part', ['amount' => money($this->summary()['yellow'])]) }}</span>
        </p>
        <button type="button" class="gx-btn" onclick="window.print()">{{ __('over.print') }}</button>
    </div>

    <div style="margin-top:12px" class="gx-table-wrap">
        <table class="gx-table">
            <thead>
                <tr>
                    <th>{{ __('over.subscriber') }}</th>
                    <th>{{ __('over.route') }}</th>
                    <th>{{ __('over.age') }}</th>
                    <th style="text-align:right">{{ __('over.total_due') }}</th>
                    <th>{{ __('over.since') }}</th>
                    <th>{{ __('over.phone') }}</th>
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
                                <span><span class="gx-dot gx-dot-red"></span><span class="gx-age-red">{{ __('over.red_age') }}</span></span>
                            @elseif ($r['bucket'] === 'yellow')
                                <span><span class="gx-dot gx-dot-amber"></span><span class="gx-age">{{ __('over.yellow_age') }}</span></span>
                            @elseif ($r['bucket'] === 'current')
                                <span><span class="gx-dot gx-dot-sky"></span><span class="gx-age">{{ __('over.current_age') }}</span></span>
                            @else
                                <span><span class="gx-dot gx-dot-green"></span><span class="gx-age">{{ __('over.settled_age') }}</span></span>
                            @endif
                        </td>
                        <td class="gx-num {{ $r['bucket'] === 'red' ? 'gx-text-red' : 'gx-text-strong' }}">{{ money($r['due']) }}</td>
                        <td class="gx-cell-faint">{{ $r['oldest'] ? period_label($r['oldest']) : '—' }}</td>
                        <td class="gx-cell-muted">{{ $r['phone'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="gx-empty">{{ __('over.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <td colspan="3">{{ __('over.grand') }}</td>
                        <td class="gx-num">{{ money(array_sum(array_column($rows, 'due'))) }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
</x-filament-panels::page>
