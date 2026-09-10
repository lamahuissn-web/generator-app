@php($label = $label ?? __('col.statement'))

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('col.statement') }} — {{ $subscriber->name }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('col.statement') }}</strong>
            <span>{{ $subscriber->name }}</span>
            <span class="spacer"></span>
            @foreach (['a4' => 'A4', 'a5' => 'A5'] as $s => $sLabel)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ $sLabel }}</a>
            @endforeach
            <a href="{{ request()->fullUrlWithQuery(['from' => '']) }}">{{ __('col.st_all_time') }}</a>
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print') }}</button>
            <a href="{{ request()->fullUrlWithQuery(['format' => 'pdf']) }}">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @include('print.partials.statement-body', [
            'subscriber' => $subscriber,
            'statement' => $statement,
            'from' => $from,
            'label' => $label,
            'size' => $size,
        ])
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
