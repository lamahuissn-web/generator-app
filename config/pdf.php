<?php

return [
    /*
     | mPDF defaults for printed bills & receipts.
     |
     | The Arabic font matters: mPDF ships XB Riyaz, which is the only
     | bundled font that shapes Arabic (connected letters) properly, so it
     | is the default for every generated PDF. Browser print views declare
     | the same family first and fall back to the system Noto Naskh.
     */
    'font' => 'xbriyaz',

    'temp_dir' => storage_path('app/mpdf'),

    /*
     | Paper sizes. 'paper' is handed straight to mPDF's format option
     | (string = preset, array = [width, height] in millimetres).
     */
    'sizes' => [
        'a4' => ['paper' => 'A4', 'label' => 'A4'],
        'a5' => ['paper' => 'A5', 'label' => 'A5'],
        '80mm' => ['paper' => [80, 297], 'label' => '80mm'],
    ],

    'default_bill_size' => 'a5',

    'default_receipt_size' => '80mm',
];
