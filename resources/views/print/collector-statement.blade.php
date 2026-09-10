@php($label = $label ?? __('col.led_doc_title'))

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('col.led_doc_title') }} — {{ $collector->name }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('col.led_doc_title') }}</strong>
            <span>{{ $collector->name }} · {{ $periodLabel }}</span>
            <span class="spacer"></span>
            @foreach (['a4' => 'A4', 'a5' => 'A5'] as $s => $sLabel)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ $sLabel }}</a>
            @endforeach
            @if ($allTime)
                <a href="{{ request()->fullUrlWithQuery(['all_time' => null]) }}">{{ __('col.led_back_to_range') }}</a>
            @else
                <a href="{{ request()->fullUrlWithQuery(['all_time' => 1]) }}">{{ __('col.st_all_time') }}</a>
            @endif
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print') }}</button>
            <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @include('print.partials.collector-statement-body', [
            'collector' => $collector,
            'ledger' => $ledger,
            'inHand' => $inHand,
            'allTime' => $allTime,
            'from' => $from,
            'to' => $to,
            'periodLabel' => $periodLabel,
            'label' => $label,
        ])
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
