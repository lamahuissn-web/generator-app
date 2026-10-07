<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\ReadingController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\RouteSheetController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
 | The mobile API (feature 008, specs/008-mobile-app/spec.md).
 |
 | A collector's phone and the panel write the SAME ledger: every endpoint below calls the service
 | the corresponding screen calls, so a rule changed in one place is changed for both — and no
 | amount, balance or invoice number is ever accepted from the phone.
 |
 | Two doors only stay shut on purpose: the sign-in is throttled (a phone that guesses is a phone
 | that must slow down), and every money-writing route demands an `Idempotency-Key` (a queue flushed
 | twice must not charge twice).
 */
Route::prefix('v1')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.login');

    Route::middleware(['auth:sanctum', 'api.active'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::get('me', [AuthController::class, 'me'])->name('api.me');

        Route::middleware('api.role:collector,admin')->group(function (): void {
            Route::get('areas', [RouteSheetController::class, 'areas'])->name('api.areas');
            Route::get('areas/{route}/houses', [RouteSheetController::class, 'houses'])->name('api.areas.houses');
            Route::get('houses/{subscriber}', [RouteSheetController::class, 'house'])->name('api.house');

            Route::get('search', [SearchController::class, 'index'])->name('api.search');

            // The collector's own purse (spec 011): his cash, his day, his readings, his handovers — about
            // whoever holds the token, and nobody else, because the route carries no id.
            Route::get('me/summary', [MeController::class, 'summary'])->name('api.me.summary');

            Route::post('readings', [ReadingController::class, 'store'])->name('api.readings.store');
            Route::post('collections', [CollectionController::class, 'store'])->name('api.collections.store');

            Route::get('receipts/{payment}', [ReceiptController::class, 'show'])->name('api.receipts.show');
            // The same receipt as words, for a household that lost the message (spec 011 §8).
            Route::get('receipts/{payment}/text', [ReceiptController::class, 'text'])->name('api.receipts.text');
        });

        // The manager's phone (spec 009): read-only, and only for a manager — a collector must never
        // see the cash his colleagues are carrying.
        Route::middleware('api.role:admin')->group(function (): void {
            Route::get('manager/board', [ManagerController::class, 'board'])->name('api.manager.board');

            // Phase B: the one write a manager's phone makes — a collector handing cash to the office.
            Route::post('manager/handovers', [ManagerController::class, 'handover'])->name('api.manager.handovers.store');

            // Phase C: taking one receipt back — with a written reason, an audit entry, and no undo.
            Route::post('manager/reversals', [ManagerController::class, 'reversal'])->name('api.manager.reversals.store');
        });
    });
});
