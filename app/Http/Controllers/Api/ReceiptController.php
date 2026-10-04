<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Models\Payment;
use App\Services\PdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The paper handed at the door.
 *
 * The phone draws nothing itself: it asks for the receipt the office's own printer would produce
 * (`PdfService`, the same mPDF template the panel prints), so a receipt held in a street and a
 * receipt held at the counter are the same document with the same server number.
 *
 * A collector reads back his own receipts; the office reads any. That is the whole authorisation
 * rule, and it is checked here rather than in the query, so a wrong id is a 403 rather than an
 * empty document.
 */
class ReceiptController extends ApiController
{
    public function show(Request $request, Payment $payment): Response
    {
        $user = $request->user();

        if (! $user->isAdmin() && (int) $payment->collector_id !== (int) $user->id) {
            throw new ApiRefusal('forbidden', __('mobile.forbidden'), 403);
        }

        $size = PdfService::normalizeSize(
            (string) $request->query('size', config('pdf.default_receipt_size')),
            PdfService::RECEIPT_SIZES,
        );

        $bytes = PdfService::receipt($payment, $size);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="receipt-'.$payment->id.'-'.$size.'.pdf"',
            'Content-Length' => (string) strlen($bytes),
        ]);
    }
}
