@php($label = $label ?? __('prt.receipts_batch_title'))

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('prt.receipts_batch_title') }} — {{ $label }}</title>
    @include('print.partials.styles', ['size' => $size])
</head>
<body>
    @unless ($forPdf ?? false)
        <div class="toolbar">
            <strong>{{ __('prt.receipts_batch_title') }}</strong>
            <span>{{ $label }} · {{ $payments->count() }} {{ __('mdl.payments') }}</span>
            <span class="spacer"></span>
            @foreach (\App\Services\PdfService::RECEIPT_SIZES as $s)
                <a href="{{ request()->fullUrlWithQuery(['size' => $s]) }}"
                   class="{{ $size === $s ? 'primary' : '' }}">{{ \App\Services\PdfService::labelFor($s) }}</a>
            @endforeach
            <button type="button" class="primary" onclick="window.print()">{{ __('prt.print_all') }}</button>
            <a href="{{ request()->fullUrlWithQuery(['size' => $size]) }}&format=pdf">{{ __('prt.download_pdf') }}</a>
        </div>
    @endunless

    <div class="sheet">
        @foreach ($payments as $payment)
            <div class="{{ $loop->last ? '' : 'page-break' }}">
                @include('print.partials.receipt-body', [
                    'payment' => $payment,
                    'subscriber' => $payment->subscriber,
                    'remaining' => $remaining[$payment->subscriber_id] ?? 0,
                    'size' => $size,
                ])
            </div>
        @endforeach
    </div>

    @unless ($forPdf ?? false)
        <script>if (new URLSearchParams(location.search).get('auto') === '1') window.addEventListener('load', () => window.print());</script>
    @endunless
</body>
</html>
