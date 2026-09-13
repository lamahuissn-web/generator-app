@props(['url'])

{{--
     A row's own link: the one way this panel prints a name so that clicking it opens that record.

     Rows have been linkable since `TableDefinition::rowUrl()` existed — but the anchor was drawn by
     the plain-cell branch alone, so a list whose first column is a TWO-LINE cell (the subscriber
     list: the name with the meter and the amperage under it) printed names that looked clickable and
     did nothing at all. A capability the view forgets to draw is not a capability.

     One component, used by every branch, so a row's link can never again depend on which cell type
     its first column happens to be. The colour and the hover are the ones the panel already uses for
     a subscriber's name in the overdue report and in the dashboard's debtor list: a third way to say
     "this opens his file" would just be a third thing to keep consistent.

     `url` is required rather than defaulted: an empty href is a link to the page you are already on,
     which is the silent failure this whole file exists to avoid.
--}}

<a href="{{ $url }}" {{ $attributes->merge(['class' => 'font-medium text-mono transition hover:text-amber-700 dark:hover:text-amber-400']) }}>{{ $slot }}</a>
