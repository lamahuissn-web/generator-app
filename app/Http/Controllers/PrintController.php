<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Route;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Printable documents: bill, receipt and per-period/per-route batches.
 *
 * Every endpoint has a browser twin (HTML + window.print) and a PDF twin
 * (mPDF) — see resources/views/print/*.
 */
class PrintController extends Controller
{
    /* ---------------------------------------------------------------- bills */

    public function bill(Request $request, Bill $bill)
    {
        $this->authorizeAdmin($request);
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_bill_size')), PdfService::BILL_SIZES);

        return view('print.bill', [
            'bill' => $bill,
            'subscriber' => $bill->subscriber,
            'unpaidBills' => $bill->subscriber->unpaidBills(),
            'size' => $size,
            'forPdf' => false,
        ]);
    }

    public function billPdf(Request $request, Bill $bill)
    {
        $this->authorizeAdmin($request);
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_bill_size')), PdfService::BILL_SIZES);

        return $this->pdfResponse(
            PdfService::bill($bill, $size),
            PdfService::fileStem($bill->period, $bill->subscriber?->name, $bill->subscriber_id).'-'.$size.'.pdf'
        );
    }

    /** Batch for a period (optionally one route) or an explicit id list. */
    public function bills(Request $request)
    {
        $this->authorizeAdmin($request);
        $format = (string) $request->input('format', 'html');
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_bill_size')), PdfService::BILL_SIZES);
        $bills = $this->billsFor($request);

        if ($format === 'zip') {
            $path = PdfService::billsZip($bills, $size);
            $filename = 'bills-'.($this->batchLabel($request) ?: 'batch').'-'.$size.'.zip';

            return response()->streamDownload(function () use ($path) {
                readfile($path);
                @unlink($path);
            }, $filename, ['Content-Type' => 'application/zip']);
        }

        if ($format === 'pdf') {
            return $this->pdfResponse(PdfService::billsBatch($bills, $size), 'bills-'.$size.'.pdf');
        }

        return view('print.bills-batch', [
            'bills' => $bills,
            'unpaid' => PdfService::unpaidMap($bills),
            'size' => $size,
            'label' => $this->batchLabel($request),
            'forPdf' => false,
        ]);
    }

    /* ------------------------------------------------------------- receipts */

    public function receipt(Request $request, Payment $payment)
    {
        $this->authorizeCollector($request);
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_receipt_size')), PdfService::RECEIPT_SIZES);

        return view('print.receipt', [
            'payment' => $payment,
            'subscriber' => $payment->subscriber,
            'remaining' => $payment->subscriber->outstanding(),
            'size' => $size,
            'forPdf' => false,
        ]);
    }

    public function receiptPdf(Request $request, Payment $payment)
    {
        $this->authorizeCollector($request);
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_receipt_size')), PdfService::RECEIPT_SIZES);

        return $this->pdfResponse(
            PdfService::receipt($payment, $size),
            'receipt-'.$payment->id.'-'.$size.'.pdf'
        );
    }

    public function receipts(Request $request)
    {
        $this->authorizeCollector($request);
        $format = (string) $request->input('format', 'html');
        $size = PdfService::normalizeSize((string) $request->input('size', config('pdf.default_receipt_size')), PdfService::RECEIPT_SIZES);
        $payments = $this->paymentsFor($request);

        if ($format === 'pdf') {
            return $this->pdfResponse(PdfService::receiptsBatch($payments, $size), 'receipts-'.$size.'.pdf');
        }

        return view('print.receipts-batch', [
            'payments' => $payments,
            'remaining' => PdfService::remainingMap($payments),
            'size' => $size,
            'label' => $this->batchLabel($request),
            'forPdf' => false,
        ]);
    }

    /* -------------------------------------------------------------- helpers */

    /** Bills of a period (optionally one route), or an explicit `ids` list. */
    private function billsFor(Request $request): Collection
    {
        $ids = $request->input('ids');
        $query = Bill::query()->with(['subscriber.route', 'reading'])->whereHas('subscriber');

        if (is_string($ids) && $ids !== '') {
            $query->whereIn('id', collect(explode(',', $ids))->map(fn ($id) => (int) trim($id))->filter()->all());
        } elseif ($request->filled('period')) {
            $query->where('period', (string) $request->input('period'))
                ->when($request->filled('route_id'), fn ($q) => $q->whereHas(
                    'subscriber',
                    fn ($s) => $s->where('route_id', (int) $request->input('route_id'))
                ));
        }

        return $this->sortByRouteAndName($query->get());
    }

    private function paymentsFor(Request $request): Collection
    {
        $ids = $request->input('ids');
        $query = Payment::query()->with(['subscriber.route', 'collector', 'allocations.bill']);

        if (is_string($ids) && $ids !== '') {
            $query->whereIn('id', collect(explode(',', $ids))->map(fn ($id) => (int) trim($id))->filter()->all());
        } else {
            $query->when($request->filled('period'), fn ($q) => $q->where('created_at', 'like', $request->input('period').'%'))
                ->when($request->filled('route_id'), fn ($q) => $q->whereHas(
                    'subscriber',
                    fn ($s) => $s->where('route_id', (int) $request->input('route_id'))
                ))
                ->when($request->filled('collector_id'), fn ($q) => $q->where('collector_id', (int) $request->input('collector_id')));
        }

        return $this->sortByRouteAndName($query->get());
    }

    private function sortByRouteAndName(Collection $rows): Collection
    {
        return $rows->sortBy([
            fn ($row) => (string) ($row->subscriber?->route?->name ?? ''),
            fn ($row) => (string) ($row->subscriber?->name ?? ''),
            fn ($row) => $row->id,
        ])->values();
    }

    private function batchLabel(Request $request): string
    {
        $parts = [];
        if ($request->filled('period')) {
            $parts[] = app()->isLocale('ar')
                ? period_label_ar((string) $request->input('period'))
                : period_label((string) $request->input('period'));
        }
        if ($request->filled('route_id')) {
            $parts[] = (string) Route::whereKey($request->input('route_id'))->value('name');
        }
        if ($request->filled('ids')) {
            $parts[] = collect(explode(',', (string) $request->input('ids')))->filter()->count().' '.__('mdl.bills');
        }

        return implode(' · ', array_filter($parts));
    }

    private function pdfResponse(string $bytes, string $filename)
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }

    private function authorizeCollector(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->isCollector()), 403);
    }
}
