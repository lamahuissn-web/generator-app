<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiRefusal;
use App\Models\Payment;
use App\Services\ApiPayload;
use App\Services\PdfService;
use App\Services\ReceiptText;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
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
 * empty document. It is the same rule for both shapes the receipt has — the printed page and the
 * text (spec 011 §8) — so a collector can never read back a colleague's receipt in one form while
 * being refused it in the other.
 */
class ReceiptController extends ApiController
{
    /**
     * The same receipt as words (spec 011 §8).
     *
     * The owner's decision was «الإيصال نصًّا لا PDF», and a household that lost the message in WhatsApp
     * needs the words again — not a file. The words come from `ReceiptText`, the same service the phone
     * reads at the moment of collection, so a receipt sent again in the evening is character for character
     * the one that was handed over at the door.
     */
    public function text(Request $request, Payment $payment): JsonResponse
    {
        $user = $request->user();

        if (! $user->isAdmin() && (int) $payment->collector_id !== (int) $user->id) {
            throw new ApiRefusal('forbidden', __('mobile.forbidden'), 403);
        }

        $subscriber = $payment->subscriber;
        $text = ReceiptText::for($payment, $subscriber);

        return response()->json([
            'receipt_text' => $text,
            // The office's own wa.me link, when it can vouch for the household's number
            // (`WhatsAppService::normalizePhone` answers null rather than guessing a country code). The phone
            // opens this and lands IN the household's chat with the receipt already written; a number the
            // office cannot vouch for is not invented on the phone — the share sheet is used instead.
            'wa_link' => WhatsAppService::waLink($subscriber?->phone, $text),
            'payment' => [
                'id' => $payment->id,
                'number' => $payment->number,
                'amount' => ApiPayload::dec($payment->amount),
                'collector_id' => $payment->collector_id,
            ],
        ]);
    }

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
