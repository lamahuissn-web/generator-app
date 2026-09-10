@php($label = $label ?? __('col.title'))

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('col.title') }} — {{ $label }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('col.title') }}</strong>
            <span>{{ $label }}</span>
            <span class="spacer"></span>
            @foreach (['a4' => 'A4', 'a5' => 'A5'] as $s => $sLabel)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ $sLabel }}</a>
            @endforeach
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print') }}</button>
            <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @include('print.partials.collection-report-body', [
            'totals' => $totals,
            'byCollector' => $byCollector,
            'byRoute' => $byRoute,
            'byDay' => $byDay,
            'from' => $from,
            'to' => $to,
            'routeName' => $routeName,
            'collectorName' => $collectorName,
            'label' => $label,
        ])
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
