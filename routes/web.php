<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SaleController;

Route::get('/', [SaleController::class, 'index'])->name('pos.index');
Route::post('/sales', [SaleController::class, 'store'])->name('pos.store');
Route::post('/restock', [SaleController::class, 'restock'])->name('pos.restock');
Route::post('/transfer', [SaleController::class, 'transfer'])->name('pos.transfer');