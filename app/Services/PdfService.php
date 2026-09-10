<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Renders the printable bill / receipt documents.
 *
 * The same Blade views back both the browser print view and the PDF
 * download, so the paper copy and the on-screen copy can never drift apart.
 */
class PdfService
{
    public const BILL_SIZES = ['a4', 'a5'];

    public const RECEIPT_SIZES = ['a5', '80mm'];

    /** Operator identity printed on every document (System → Settings). */
    public static function company(): array
    {
        return [
            'name' => (string) Setting::get('company_name', '') ?: __('prt.company_default'),
            'phone' => (string) Setting::get('company_phone', ''),
            'address' => (string) Setting::get('company_address', ''),
            'footer' => (string) Setting::get('bill_footer', ''),
        ];
    }

    public static function normalizeSize(string $size, array $allowed): string
    {
        $size = strtolower($size);

        return in_array($size, $allowed, true) ? $size : $allowed[0];
    }

    public static function paperFor(string $size): string|array
    {
        return config("pdf.sizes.{$size}.paper", 'A5');
    }

    public static function labelFor(string $size): string
    {
        return config("pdf.sizes.{$size}.label", strtoupper($size));
    }

    public static function newPdf(string $size): Mpdf
    {
        $tempDir = config('pdf.temp_dir');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => self::paperFor($size),
            'directionality' => app()->isLocale('ar') ? 'rtl' : 'ltr',
            'default_font' => config('pdf.font'),
            'tempDir' => $tempDir,
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 6,
            'margin_bottom' => 6,
        ]);

        $pdf->autoScriptToLang = true;
        $pdf->autoLangToFont = true;
        $pdf->SetAuthor(self::company()['name']);
        $pdf->SetTitle(__('prt.doc_title'));

        return $pdf;
    }

    /** Render a print view to PDF bytes. */
    public static function render(string $view, array $data, string $size): string
    {
        $pdf = self::newPdf($size);
        $pdf->WriteHTML(view($view, [...$data, 'size' => $size, 'forPdf' => true])->render());

        return $pdf->Output('', Destination::STRING_RETURN);
    }

    public static function bill(Bill $bill, string $size = 'a5'): string
    {
        $size = self::normalizeSize($size, self::BILL_SIZES);

        return self::render('print.bill', [
            'bill' => $bill,
            'subscriber' => $bill->subscriber,
            'unpaidBills' => $bill->subscriber->unpaidBills(),
        ], $size);
    }

    public static function receipt(Payment $payment, string $size = '80mm'): string
    {
        $size = self::normalizeSize($size, self::RECEIPT_SIZES);

        return self::render('print.receipt', [
            'payment' => $payment,
            'subscriber' => $payment->subscriber,
            'remaining' => $payment->subscriber->outstanding(),
        ], $size);
    }

    /** All given bills in one document, one page each. */
    public static function billsBatch(Collection $bills, string $size = 'a5'): string
    {
        $size = self::normalizeSize($size, self::BILL_SIZES);

        return self::render('print.bills-batch', [
            'bills' => $bills,
            'unpaid' => self::unpaidMap($bills),
        ], $size);
    }

    public static function receiptsBatch(Collection $payments, string $size = '80mm'): string
    {
        $size = self::normalizeSize($size, self::RECEIPT_SIZES);

        return self::render('print.receipts-batch', [
            'payments' => $payments,
            'remaining' => self::remainingMap($payments),
        ], $size);
    }

    /** [subscriber_id => unpaid bills] for every subscriber behind $bills. */
    public static function unpaidMap(Collection $bills): array
    {
        return $bills->pluck('subscriber')->filter()->unique('id')
            ->mapWithKeys(fn ($subscriber) => [$subscriber->id => $subscriber->unpaidBills()])
            ->all();
    }

    /** [subscriber_id => outstanding balance] for every subscriber behind $payments. */
    public static function remainingMap(Collection $payments): array
    {
        return $payments->pluck('subscriber')->filter()->unique('id')
            ->mapWithKeys(fn ($subscriber) => [$subscriber->id => $subscriber->outstanding()])
            ->all();
    }

    /** One PDF per bill inside a zip (built on disk, caller deletes it). */
    public static function billsZip(Collection $bills, string $size = 'a5'): string
    {
        $size = self::normalizeSize($size, self::BILL_SIZES);
        $path = tempnam(sys_get_temp_dir(), 'bills-').'.zip';

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($bills as $bill) {
            $name = self::fileStem($bill->period, $bill->subscriber?->name, $bill->subscriber_id).'.pdf';
            $zip->addFromString($name, self::bill($bill, $size));
        }

        $zip->close();

        return $path;
    }

    /** Filesystem-safe name fragment that keeps Arabic readable. */
    public static function fileStem(string $period, ?string $name, int $id): string
    {
        $name = preg_replace('/[\\\\\\/\\:\\*\\?"<>\\|]+/u', '', (string) $name);
        $name = trim(preg_replace('/\\s+/u', '-', (string) $name));

        return $period.'_'.($name !== '' ? $name : 'subscriber').'_'.$id;
    }
}
