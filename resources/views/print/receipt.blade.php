<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('prt.receipt_title') }} #{{ $payment->id }} — {{ $subscriber->name }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('prt.receipt_title') }} #{{ $payment->id }}</strong>
            <span>{{ $subscriber->name }} · <span class="ltr">{{ $payment->created_at->format('Y-m-d H:i') }}</span></span>
            <span class="spacer"></span>
            @foreach (\App\Services\PdfService::RECEIPT_SIZES as $s)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ \App\Services\PdfService::labelFor($s) }}</a>
            @endforeach
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print') }}</button>
            <a href="{{ route('print.receipt.pdf', ['payment' => $payment->id, 'size' => $size]) }}">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @include('print.partials.receipt-body', ['payment' => $payment, 'subscriber' => $subscriber, 'remaining' => $remaining, 'size' => $size])
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
