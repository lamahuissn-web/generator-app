{{-- Shared print stylesheet for bills & receipts. Deliberately plain CSS with
     table-based layout: the same markup is rendered by browsers *and* by mPDF,
     which supports neither flexbox nor grid. --}}
@php
    $isRtl = app()->isLocale('ar');
    $paper = ['a4' => 'A4', 'a5' => 'A5', '80mm' => '80mm 297mm'][$size] ?? 'A5';
    $compact = $size === '80mm';
@endphp
<style>
    {{-- Browser-only: mPDF takes its paper size from the constructor and its
         CSS @page handler re-paginates the document into hundreds of pages if
         we declare a paper size here as well. --}}
    @unless ($forPdf ?? false)
        @page { size: {{ $paper }}; margin: 0; }
    @endunless

    body {
        margin: 0;
        background: #f1f5f9;
        color: #0f172a;
        font-family: xbriyaz, "Noto Naskh Arabic", "Segoe UI", Tahoma, sans-serif;
        direction: {{ $isRtl ? 'rtl' : 'ltr' }};
    }

    .sheet {
        background: #fff;
        margin: 0 auto;
        padding: {{ $compact ? '4mm' : ($size === 'a4' ? '14mm' : '9mm') }};
        max-width: {{ $compact ? '92mm' : '100%' }};
        font-size: {{ $compact ? '8.5pt' : ($size === 'a4' ? '11.5pt' : '10pt') }};
        line-height: 1.45;
    }

    .co-name { font-size: {{ $compact ? '11pt' : ($size === 'a4' ? '19pt' : '15pt') }}; font-weight: bold; }
    .co-meta { font-size: .82em; color: #64748b; }
    .doc-title { font-size: {{ $compact ? '10pt' : ($size === 'a4' ? '15pt' : '12.5pt') }}; font-weight: bold; text-align: {{ $isRtl ? 'left' : 'right' }}; }
    .doc-meta { font-size: .82em; color: #64748b; text-align: {{ $isRtl ? 'left' : 'right' }}; }

    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }

    hr.rule { border: 0; border-top: 2px solid #0f172a; margin: 6px 0 8px; }

    table.meta td { padding: 2px 0; font-size: .95em; }
    table.meta td.k { color: #64748b; width: 17%; }
    table.meta td.v { font-weight: bold; width: 33%; }

    table.charges { margin-top: 8px; }
    table.charges th {
        background: #f1f5f9;
        border: 1px solid #cbd5e1;
        padding: 5px 7px;
        font-size: .85em;
        text-align: {{ $isRtl ? 'right' : 'left' }};
    }
    table.charges td { border: 1px solid #e2e8f0; padding: 5px 7px; }
    table.charges td.num, table.charges th.num { text-align: {{ $isRtl ? 'left' : 'right' }}; font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.charges tr.total td { background: #f8fafc; font-weight: bold; border-top: 2px solid #0f172a; }
    table.charges tr.grand td { background: #0f172a; color: #fff; font-weight: bold; font-size: 1.12em; }
    table.charges tr.debt td { color: #be123c; }

    table.owed { margin-top: 8px; font-size: .9em; }
    table.owed th { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; padding: 4px 6px; font-size: .82em; text-align: {{ $isRtl ? 'right' : 'left' }}; }
    table.owed td { border: 1px solid #fecdd3; padding: 4px 6px; }

    .section-title { margin: 10px 0 2px; font-size: .82em; font-weight: bold; text-transform: uppercase; letter-spacing: .06em; color: #64748b; }
    .note { margin-top: 10px; padding: 6px 8px; border: 1px dashed #cbd5e1; border-radius: 6px; font-size: .85em; color: #475569; }
    .thanks { margin-top: 8px; text-align: center; font-size: .9em; font-weight: bold; color: #475569; }
    .status-stamp { margin-top: 5px; text-align: center; }

    .badge { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: .78em; font-weight: bold; }
    .badge-paid { background: #dcfce7; color: #15803d; }
    .badge-partial { background: #fef3c7; color: #b45309; }
    .badge-unpaid { background: #ffe4e6; color: #be123c; }

    /* big amount block on receipts */
    .hero { background: #0f172a; color: #fff; padding: 8px 10px; border-radius: 8px; margin-top: 8px; }
    .hero .lbl { font-size: .78em; text-transform: uppercase; letter-spacing: .08em; color: #cbd5e1; }
    .hero .val { font-size: {{ $compact ? '17pt' : '22pt' }}; font-weight: bold; font-variant-numeric: tabular-nums; }

    .muted { color: #64748b; }
    .strong { font-weight: bold; }
    .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    /* LTR island inside RTL text: mPDF ignores `isolate`, so force the run
       character-by-character or neutrals like "#" and "−" flip to the far side. */
    .ltr { direction: ltr; unicode-bidi: bidi-override; display: inline-block; }
    .page-break { page-break-after: always; }

    /* ---- screen-only toolbar ---- */
    .toolbar {
        position: sticky; top: 0; z-index: 9;
        display: flex; gap: 8px; align-items: center; flex-wrap: wrap;
        padding: 10px 14px; background: #0f172a; color: #e2e8f0;
        font-family: "Segoe UI", Tahoma, sans-serif; font-size: 13px;
        direction: {{ $isRtl ? 'rtl' : 'ltr' }};
    }
    .toolbar a, .toolbar button {
        border: 1px solid #334155; background: #1e293b; color: #e2e8f0;
        padding: 6px 12px; border-radius: 8px; font-size: 13px; font-weight: 600;
        text-decoration: none; cursor: pointer;
    }
    .toolbar a:hover, .toolbar button:hover { background: #334155; }
    .toolbar .primary { background: #f59e0b; border-color: #f59e0b; color: #0f172a; }
    .toolbar .spacer { flex: 1; }

    @media print {
        body { background: #fff; }
        .toolbar { display: none !important; }
        .sheet { padding: 0; margin: 0; max-width: none; }
    }
</style>
