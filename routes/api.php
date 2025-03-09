<?php

use App\Http\Controllers\Api\MpesaController;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/mpesa-token', function(){
    return (new MpesaService())->authorize();
});

Route::prefix('mpesa')->group(function () {
    // Public callback URL
    Route::post('callback', [MpesaController::class, 'handleCallback'])->name('stk.callback');
    
    // These could be protected with auth if needed
    Route::post('pay', [MpesaController::class, 'initiatePayment']);
    Route::get('status', [MpesaController::class, 'checkStatus']);
});
