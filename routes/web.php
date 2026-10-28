<?php

use App\Http\Controllers\SaleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('pos.index');
});

Route::middleware('auth')->group(function () {
    Route::get('/pos', [SaleController::class, 'index'])->name('pos.index');
    Route::post('/sales', [SaleController::class, 'store'])->name('pos.store');
    Route::post('/restock', [SaleController::class, 'restock'])->name('pos.restock');
    Route::post('/transfer', [SaleController::class, 'transfer'])->name('pos.transfer');
    Route::post('/checkout-card', [SaleController::class, 'checkoutWithCard'])->name('pos.checkout.card');
});

require __DIR__.'/auth.php';