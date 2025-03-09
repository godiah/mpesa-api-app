<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

class MpesaService
{
    /**
     * Create a new class instance.
     */
    public $base_url;

    public function __construct()
    {
        $this->base_url = env('MPESA_ENV') == 'production' ? "https://api.safaricom.co.ke" : "https://sandbox.safaricom.co.ke";
    }

    /**
     * Authenticate Function: Authorization
     */
    public function authorize()
    {
        $url = $this->base_url . "/oauth/v1/generate?grant_type=client_credentials";
        $response = Http::withBasicAuth(env('MPESA_CONSUMER_KEY'), env('MPESA_CONSUMER_SECRET'))->get($url);

        return $response->json("access_token");
    }
    /**
     * Generate unique originator ID for B2C Payment
     */
    public function generateOriginatorConversationId()
    {
        return 'HAVEN-' . strtoupper(str()->random(7)). '-' .time();
    }

    /**
     * Mpesa Express: Lipa na M-PESA online API
     * STK Push prompt     
     */
    public function stkPush($phone, $ref="N/A",$amount)
    {
        $url = $this->base_url . "/mpesa/stkpush/v1/processrequest";
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(env('MPESA_SHORT_CODE') . env('MPESA_PASSKEY') . $timestamp);
        $response = Http::withToken($this->authorize(),'Bearer')->post($url,[   
            "BusinessShortCode" => env('MPESA_SHORT_CODE'),    
            "Password" => $password,    
            "Timestamp" => $timestamp,    
            "TransactionType" => "CustomerPayBillOnline",    
            "Amount" => $amount,    
            "PartyA" => $phone,    
            "PartyB" => env('MPESA_SHORT_CODE'),    
            "PhoneNumber" => $phone,    
            "CallBackURL" => env('MPESA_CALLBACK_URL',route('stk.callback')),    
            "AccountReference" => $ref,    
            "TransactionDesc" => $ref
        ]);

        return $response->json();
    }

    /**
     * Check the status of a Lipa Na M-Pesa Online Payment.
     */
    public function stkQuery($checkoutRequestId)
    {
        $url = $this->base_url . "/mpesa/stkpushquery/v1/query";
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(env('MPESA_SHORT_CODE') . env('MPESA_PASSKEY') . $timestamp);
        
        $response = Http::withToken($this->authorize())->post($url, [
            "BusinessShortCode" => env('MPESA_SHORT_CODE'),    
            "Password" => $password,    
            "Timestamp" => $timestamp,    
            "CheckoutRequestID" => $checkoutRequestId,    
        ]);

        return $response->json();
    }

    /**
     * B2C API is an API used to make payments from a Business to Customers (Pay Outs).
     * Also known as Bulk Disbursements.
     */
    public function b2cPayment($amount, $phone, $remarks)
    {
        $url = $this->base_url. "/mpesa/b2c/v3/paymentrequest";
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode(env('MPESA_SHORT_CODE'). env('MPESA_PASSKEY'). $timestamp);

        $response = Http::withToken($this->authorize())->post($url, [
            'OriginatorConversationID' => $this->generateOriginatorConversationId(),
            'InitiatorName' => env('MPESA_INITIATOR_NAME'),
            'SecurityCredential' => $password,
            'CommandID' => "BusinessPayment",
            'Amount' => $amount,
            'PartyA' => env('MPESA_SHORT_CODE'),
            'PartyB' => $phone,
            'Remarks' => $remarks,
            'QueueTimeOutURL' => env('MPESA_QUEUE_TIMEOUT_URL'),
            'ResultURL' => env('MPESA_RESULT_URL'),
            'Occasion' => $remarks,    
        ]);

        return $response->json();
    }
}
