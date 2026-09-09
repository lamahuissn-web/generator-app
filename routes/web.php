<?php

use App\Http\Controllers\Collector\AuthController;
use App\Http\Controllers\Collector\DashboardController;
use App\Http\Controllers\Collector\RouteController;
use App\Http\Controllers\Collector\SubscriberController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// ---- Language switch (any authenticated user, both portals) ------------------
Route::middleware('auth')->post('/locale', function (Request $request) {
    $locale = $request->input('locale') === 'ar' ? 'ar' : 'en';
    if ($user = $request->user()) {
        $user->forceFill(['locale' => $locale])->save();
    }
    $request->session()->put('locale', $locale);

    return back();
})->name('locale.switch');

// ---- Collector mobile portal -------------------------------------------------
Route::middleware(['guest', 'locale'])->group(function () {
    Route::get('/collector/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/collector/login', [AuthController::class, 'login'])->name('collector.login.attempt');
});

Route::middleware(['auth', 'role:collector', 'locale'])->prefix('collector')->name('collector.')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/routes/{route}', [RouteController::class, 'show'])->name('route.show');

    Route::get('/subscribers/{subscriber}', [SubscriberController::class, 'show'])->name('subscriber.show');
});
