<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MpesaService
{
    /**
     * Create a new class instance.
     */
    public $base_url;

    public function __construct()
    {
        // List of required environment variables
        $requiredConfigs = [
            'MPESA_ENV',
            'MPESA_CONSUMER_KEY',
            'MPESA_CONSUMER_SECRET',
            'MPESA_SHORT_CODE',
            'MPESA_PASSKEY',
            'MPESA_CALLBACK_URL',
            // B2C
            'MPESA_INITIATOR_NAME',
            'MPESA_QUEUE_TIMEOUT_URL',
            'MPESA_RESULT_URL'
        ];

        foreach ($requiredConfigs as $config) {
            if (!env($config)) {
                throw new \Exception("Missing required configuration: {$config}");
            }
        }

        $this->base_url = env('MPESA_ENV') == 'production' 
            ? "https://api.safaricom.co.ke" 
            : "https://sandbox.safaricom.co.ke";
    }

    /**
     * Authenticate Function: Authorization
     */
    public function authorize()
    {
        $cacheKey = 'mpesa_access_token';

        // Check if the token is cached
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Fetch a new token if not cached
        $url = $this->base_url . "/oauth/v1/generate?grant_type=client_credentials";
        try {
            $response = Http::withBasicAuth(env('MPESA_CONSUMER_KEY'), env('MPESA_CONSUMER_SECRET'))->get($url);
            $accessToken = $response->json("access_token");
            $expiresIn = $response->json("expires_in") ?? 3600;

            // Cache the token until it expires
            Cache::put($cacheKey, $accessToken, $expiresIn);            
            return $accessToken;
        } catch(\Exception $e){
            Log::error('Error in authorize(): ' . $e->getMessage());
            throw new \Exception('Failed to obtain access token from M-Pesa');
        }
        
    }

    /**
     * Generate Security Credentials
     */
    public function generateCredentials($password)
    {
        try{
            $public_key = file_get_contents(public_path('cert.cer'));
            openssl_public_encrypt($password,$encrypted,$public_key);
            return base64_encode($encrypted);
        }catch(\Exception $e){
            Log::error('Error in generateCredentials(): '. $e->getMessage());
            throw new \Exception('Failed to generate security credentials');
        }
        
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

        try {
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
        } catch(\Exception $e) {
            Log::error('Error in stkPush(): '. $e->getMessage());
            throw new \Exception('Failed to make payment request');
        }
    }

    /**
     * Check the status of a Lipa Na M-Pesa Online Payment.
     */
    public function stkQuery($checkoutRequestId)
    {
        $url = $this->base_url . "/mpesa/stkpushquery/v1/query";

        try{
            $timestamp = Carbon::now()->format('YmdHis');
            $password = base64_encode(env('MPESA_SHORT_CODE') . env('MPESA_PASSKEY') . $timestamp);
            
            $response = Http::withToken($this->authorize())->post($url, [
                "BusinessShortCode" => env('MPESA_SHORT_CODE'),    
                "Password" => $password,    
                "Timestamp" => $timestamp,    
                "CheckoutRequestID" => $checkoutRequestId,    
            ]);

            return $response->json();
        } catch(\Exception $e){
            Log::error('Error in stkQuery(): '. $e->getMessage());
            throw new \Exception('Failed to make payment query');
        }
    }

    /**
     * B2C API is an API used to make payments from a Business to Customers (Pay Outs).
     * Also known as Bulk Disbursements.
     */
    public function b2cPayment($phone, $amount, $remarks, $occasion)
    {
        $url = $this->base_url. "/mpesa/b2c/v3/paymentrequest";
        
        try{
            $timestamp = Carbon::now()->format('YmdHis');
            $password = env('MPESA_SHORT_CODE'). env('MPESA_PASSKEY'). $timestamp;

            $response = Http::withToken($this->authorize())->post($url, [
                'OriginatorConversationID' => $this->generateOriginatorConversationId(),
                'InitiatorName' => env('MPESA_INITIATOR_NAME'),
                'SecurityCredential' => $this->generateCredentials($password),
                'CommandID' => "BusinessPayment",
                'Amount' => $amount,
                'PartyA' => env('MPESA_SHORT_CODE'),
                'PartyB' => $phone,
                'Remarks' => $remarks,
                'QueueTimeOutURL' => env('MPESA_QUEUE_TIMEOUT_URL',route('b2c.timeout')),
                'ResultURL' => env('MPESA_RESULT_URL',route('b2c.result')),
                'Occasion' => $occasion,    
            ]);

            return $response->json();
        } catch(\Exception $e) {
            Log::error('Error in b2cPayment(): '. $e->getMessage());
            throw new \Exception('Failed to make payment request');
        }
    }
}
