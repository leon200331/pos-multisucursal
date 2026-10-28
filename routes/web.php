<?php

use App\Http\Controllers\SaleController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('pos.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/pos', [SaleController::class, 'index'])->name('pos.index');
    Route::post('/sales', [SaleController::class, 'store'])->name('pos.store');
    Route::post('/checkout-card', [SaleController::class, 'checkoutWithCard'])->name('pos.checkout.card');
    Route::post('/restock', [SaleController::class, 'restock'])->name('pos.restock');
    Route::post('/transfer', [SaleController::class, 'transfer'])->name('pos.transfer');
});

require __DIR__.'/auth.php';

// FUERA del grupo auth: Stripe no tiene sesión de navegador
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])->name('webhooks.stripe');