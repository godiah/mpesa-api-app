<?php

use App\Http\Controllers\Api\MpesaController;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::prefix('mpesa')->group(function () {   
    Route::post('/pay', [MpesaController::class, 'initiatePayment']);
    Route::get('/status', [MpesaController::class, 'checkStatus']);

    Route::post('/b2c/send', [MpesaController::class, 'initiateB2CPayment']);
    Route::post('/b2c/queue', [MpesaController::class, 'queueTimeoutCallback'])->name('b2c.timeout');
    Route::post('/b2c/result', [MpesaController::class, 'resultCallback'])->name('b2c.result');
});

// Public callback URL
Route::post('mpesa/callback', [MpesaController::class, 'handleCallback'])->name('stk.callback');
