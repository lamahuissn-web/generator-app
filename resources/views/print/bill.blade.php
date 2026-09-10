<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('prt.bill_title') }} — {{ $subscriber->name }} — {{ $bill->period }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('prt.bill_title') }}</strong>
            <span>{{ $subscriber->name }} · {{ app()->isLocale('ar') ? period_label_ar($bill->period) : period_label($bill->period) }}</span>
            <span class="spacer"></span>
            @foreach (\App\Services\PdfService::BILL_SIZES as $s)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ \App\Services\PdfService::labelFor($s) }}</a>
            @endforeach
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print') }}</button>
            <a href="{{ route('print.bill.pdf', ['bill' => $bill->id, 'size' => $size]) }}">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @include('print.partials.bill-body', ['bill' => $bill, 'subscriber' => $subscriber, 'unpaidBills' => $unpaidBills, 'size' => $size])
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
