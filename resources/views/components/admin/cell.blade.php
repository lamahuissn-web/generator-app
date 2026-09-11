@props([
    'align' => 'start',
    'tone' => null,
    'amount' => false,
    'onMobile' => true,
    'muted' => false,
])

<td @class([
        'px-4 py-3 text-sm align-middle',
        'text-end' => $align === 'end',
        'hidden sm:table-cell' => ! $onMobile,
    ])>
    <span @class([
        $amount ? 'amount-cell font-medium' : 'text-secondary-foreground',
        $tone ? tone_text($tone) : ($amount ? 'text-mono' : null),
        'text-muted-foreground' => $muted,
    ])>{{ $slot }}</span>
</td>
