<?php

use Illuminate\Support\Facades\Route;
use Iquesters\Commerce\Http\Controllers\OrderAdminController;
use Iquesters\Commerce\Http\Controllers\ProductAdminController;
use Iquesters\Commerce\Http\Controllers\ProductQuoteController;

$ulid = '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}';

Route::middleware(['web', 'auth'])->prefix('products')->group(function () use ($ulid) {
    // Super-admins and organisation admins; the controller limits them to their organisations.
    Route::middleware('ensure.product.manager')->name('products.')->group(function () use ($ulid) {
        // Generic tables (data_url) and the forms that save them.
        Route::get('/data', [ProductAdminController::class, 'products'])->name('data');
        Route::get('/data/{uid}', [ProductAdminController::class, 'product'])->where('uid', $ulid)->name('record');
        Route::post('/', [ProductAdminController::class, 'storeProduct'])->name('store');
        Route::put('/{uid}', [ProductAdminController::class, 'updateProduct'])->where('uid', $ulid)->name('update');

        Route::get('/discounts/data', [ProductAdminController::class, 'discounts'])->name('discounts.data');
        Route::get('/discounts/data/{uid}', [ProductAdminController::class, 'discount'])->where('uid', $ulid)->name('discounts.record');
        Route::post('/discounts', [ProductAdminController::class, 'storeDiscount'])->name('discounts.store');
        Route::put('/discounts/{uid}', [ProductAdminController::class, 'updateDiscount'])->where('uid', $ulid)->name('discounts.update');

        // Options of the forms' selects.
        Route::get('/options/organisations', [ProductAdminController::class, 'organisationOptions'])->name('options.organisations');
        Route::get('/options/products', [ProductAdminController::class, 'productOptions'])->name('options.products');
        Route::get('/options/items', [ProductAdminController::class, 'itemOptions'])->name('options.items');
    });

    // Anyone signed in: the price of an active product, with an optional coupon code.
    Route::get('/{uid}/quote', [ProductQuoteController::class, 'show'])->where('uid', $ulid)->name('products.quote');
});

// Orders (generic table): super-admins and organisation admins, limited to their organisations.
Route::middleware(['web', 'auth', 'ensure.product.manager'])->prefix('orders')->name('orders.')->group(function () use ($ulid) {
    Route::get('/data', [OrderAdminController::class, 'data'])->name('data');
    Route::get('/data/{uid}', [OrderAdminController::class, 'record'])->where('uid', $ulid)->name('record');
    Route::get('/{uid}/summary', [OrderAdminController::class, 'summary'])->where('uid', $ulid)->name('summary');
    Route::post('/{uid}/cancel', [OrderAdminController::class, 'cancel'])->where('uid', $ulid)->name('cancel');
});
