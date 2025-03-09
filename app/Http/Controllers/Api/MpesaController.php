<?php

namespace App\Http\Controllers\Api;

use App\Helpers\MpesaHelper;
use App\Http\Controllers\Controller;
use App\Models\MpesaB2CTransaction;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MpesaController extends Controller
{
    protected $mpesaService;

    public function __construct(MpesaService $mpesaService)
    {
        $this->mpesaService = $mpesaService;
    }

    /**
     * Initiate STK Push
     */
    public function initiatePayment(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'reference' => 'nullable|string',
        ]);

        // Format phone number
        $phone = $this->formatPhoneNumber($validated['phone']);
        $reference = $validated['reference'] ?? 'Payment-' . time();

        try {
            // Initiate STK Push
            $response = $this->mpesaService->stkPush($phone, $reference, $validated['amount']);

            // Create transaction record
            MpesaTransaction::create([
                'phone' => $phone,
                'amount' => $validated['amount'],
                'reference' => $reference,
                'merchant_request_id' => $response['MerchantRequestID'] ?? null,
                'checkout_request_id' => $response['CheckoutRequestID'] ?? null,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'STK push initiated successfully',
                'data' => $response,
            ]);
        } catch (\Exception $e) {
            Log::error('M-PESA Payment Error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle M-PESA callback
     */
    public function handleCallback(Request $request)
    {
        // Log the entire callback
        Log::info('M-PESA Callback: ' . json_encode($request->all()));

        $callbackData = $request->all();
        
        // Extract the callback body
        $callbackBody = $callbackData['Body'] ?? null;
        
        if (!$callbackBody) {
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
        }

        $stkCallback = $callbackBody['stkCallback'] ?? null;
        $resultCode = $stkCallback['ResultCode'] ?? null;
        $merchantRequestID = $stkCallback['MerchantRequestID'] ?? null;
        $checkoutRequestID = $stkCallback['CheckoutRequestID'] ?? null;

        // Find the transaction
        $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestID)->first();
        
        if (!$transaction) {
            Log::error('Transaction not found for checkout_request_id: ' . $checkoutRequestID);
            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
        }

        // If payment was successful
        if ($resultCode == 0) {
            // Extract payment details from CallbackMetadata
            $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;
            $transactionDate = null;
            
            foreach ($metadata as $item) {
                if ($item['Name'] == 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'];
                }
                if ($item['Name'] == 'TransactionDate') {
                    $transactionDate = $item['Value'];
                }
            }
            
            // Update transaction
            $transaction->update([
                'mpesa_receipt_number' => $receiptNumber,
                'transaction_date' => $transactionDate,
                'result_code' => $resultCode,
                'result_desc' => $stkCallback['ResultDesc'] ?? 'Success',
                'status' => 'completed',
            ]);
            
            Log::info('Payment successful for: ' . $transaction->reference);
        } else {
            // Update transaction as failed
            $transaction->update([
                'result_code' => $resultCode,
                'result_desc' => $stkCallback['ResultDesc'] ?? 'Failed',
                'status' => 'failed',
            ]);
            
            Log::error('Payment failed for: ' . $transaction->reference . ' with error: ' . ($stkCallback['ResultDesc'] ?? 'Unknown error'));
        }

        // Always respond with success to M-PESA (this doesn't mean the payment succeeded, just that we processed the callback)
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback received successfully']);
    }

    /**
     * Check payment status using STK Query
     */
    public function checkStatus(Request $request)
    {
        $validated = $request->validate([
            'reference' => 'required|string',
        ]);

        // First, find the transaction in our database
        $transaction = MpesaTransaction::where('reference', $validated['reference'])
            ->latest()
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        // If we don't have a checkout_request_id, we can't query M-PESA
        if (!$transaction->checkout_request_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot check status: No checkout request ID',
                'data' => [
                    'reference' => $transaction->reference,
                    'status' => $transaction->status,
                ],
            ], 400);
        }

        try {
            // Query the status from M-PESA
            $queryResponse = $this->mpesaService->stkQuery($transaction->checkout_request_id);

            // Log the full response for debugging
            Log::info('STK Query Response: ' . json_encode($queryResponse));

            $resultCode = $queryResponse['ResultCode'] ?? null;

            // Update transaction status based on query response
            if ($resultCode !== null) {
                if ($resultCode == 0) {
                    $transaction->update([
                        'result_code' => $resultCode,
                        'result_desc' => $queryResponse['ResultDesc'] ?? 'Success',
                        'status' => 'completed',
                    ]);
                } else {
                    $transaction->update([
                        'result_code' => $resultCode,
                        'result_desc' => $queryResponse['ResultDesc'] ?? 'Failed',
                        'status' => 'failed',
                    ]);
                }
            }

            // Return the response with both local and M-PESA status
            return response()->json([
                'success' => true,
                'data' => [
                    'reference' => $transaction->reference,
                    'status' => $transaction->status,
                    'receipt' => $transaction->mpesa_receipt_number,
                    'amount' => $transaction->amount,
                    'date' => $transaction->transaction_date,
                    'result_desc' => $transaction->result_desc,
                    'mpesa_response' => [
                        'result_code' => $resultCode,
                        'result_desc' => $queryResponse['ResultDesc'] ?? null,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('M-PESA Query Error: ' . $e->getMessage());

            // If the query fails, return the local status
            return response()->json([
                'success' => true,
                'message' => 'Could not query M-PESA: ' . $e->getMessage(),
                'data' => [
                    'reference' => $transaction->reference,
                    'status' => $transaction->status,
                    'receipt' => $transaction->mpesa_receipt_number,
                    'amount' => $transaction->amount,
                    'date' => $transaction->transaction_date,
                    'result_desc' => $transaction->result_desc,
                ],
            ]);
        }
    }

    /**
     * Initiate B2C Payments
     */
    public function initiateB2CPayment(Request $request)
    {
        // Validate request
        $validated = $request->validate([
            'phone_number' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'remarks' => 'required|string|max:100',
            'occasion' => 'nullable|string|max:100',
        ]);

        // Format phone number
        $phone = $this->formatPhoneNumber($validated['phone_number']);

        try {
            // Initiate B2C payment using M-PESA service

            $response = $this->mpesaService->b2cPayment($phone, $validated['amount'], $validated['remarks'],$validated['occasion']);

            // Create transaction record
            MpesaB2CTransaction::create([
                'originator_conversation_id' => $response['OriginatorConversationID'] ?? null,
                'command_id' => $validated['command_id'] ?? 'BusinessPayment',
                'initiator_name' => env('MPESA_INITIATOR_NAME'),
                'phone_number' => $phone,
                'amount' => $validated['amount'],
                'remarks' => $validated['remarks'],
                'occasion' => $validated['occasion'] ?? '',
                'status' => 'pending',
                'conversation_id' => $response['ConversationID'] ?? null,
                'result_code' => $response['ResponseCode'] ?? null,
                'result_description' => $response['ResponseDescription'] ?? null,
                'result_data' => $response,
                'request_data' => [
                    'phone_number' => $validated['phone_number'],
                    'amount' => $validated['amount'],
                    'remarks' => $validated['remarks'],
                    'occasion' => $validated['occasion'] ?? '',
                    'command_id' => $validated['command_id'] ?? 'BusinessPayment',
                ],
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment request accepted for processing',
                'data' => $response
            ]);
        } catch (\Exception $e) {
            Log::error('B2C payment failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Payment request failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     *  Handles callbacks when M-Pesa can't process your request within the expected timeframe. 
     *  It's a fallback mechanism.
     */
    public function queueTimeoutCallback(Request $request)
    {
        Log::info('M-Pesa B2C Queue Timeout', $request->all());
        
        // Process timeout notification
        $this->processCallback($request->all());
        
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * Handles callback from M-Pesa when a B2C transaction is completed (successfully or with failure)
     */
    public function resultCallback(Request $request)
    {
        Log::info('M-Pesa B2C Result', $request->all());
        
        // Process result notification
        $this->processCallback($request->all());
        
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
    
    /** 
     * Processes data from both types of callbacks[result&queueTimeout], avoiding code duplication.
    */
    protected function processCallback($data)
    {
        // Find transaction by OriginatorConversationID
        $originatorConversationId = MpesaHelper::getOriginatorConversationID($data);
        if (!$originatorConversationId) {
            Log::warning("Cannot process callback: missing OriginatorConversationID");            
            return;
        }

        $transaction = MpesaB2CTransaction::where('originator_conversation_id', $originatorConversationId)
            ->first();
        
        if (!$transaction) {
            Log::warning('M-Pesa B2C transaction not found', $data);

            // Dispatch job to reattempt processing later
            \App\Jobs\ProcessMpesaCallback::dispatch($data);
            return;
        }

        // Check for duplicate processing
        if (in_array($transaction->status, ['completed', 'failed'])) {
            Log::info('Duplicate callback received for transaction: ' . $transaction->originator_conversation_id);
            return;
        }
        
        // Update transaction details
        $transaction->update([
            'conversation_id' => $data['ConversationID'] ?? null,
            'result_code' => $data['ResultCode'] ?? null,
            'result_description' => $data['ResultDesc'] ?? null,
            'status' => ($data['ResultCode'] ?? 1) == 0 ? 'completed' : 'failed',
            'result_data' => $data,
        ]);
        
        // Process additional business logic based on the result
        if (($data['ResultCode'] ?? 1) == 0) {
            // Payment was successful - trigger any necessary business logic
            // e.g., update order status, send notification, etc.
        } else {
            // Payment failed - handle the failure
            // e.g., notify admin, retry policy, etc.
        }
    }

    /**
     * Format phone number to required format (254XXXXXXXXX)
     */
    private function formatPhoneNumber($phone)
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // If the number starts with 0, replace it with 254
        if (substr($phone, 0, 1) == '0') {
            $phone = '254' . substr($phone, 1);
        }

        // If the number doesn't have country code, add it
        if (strlen($phone) == 9) {
            $phone = '254' . $phone;
        }

        return $phone;
    }

    /**
     * Helper method to extract key field OriginatorConversationID by checking multiple possible locations in the data structure. 
     */
    protected function getOriginatorConversationID(array $data)
    {
        // Check if it exists in the nested "Result" object first
        if (isset($data['Result']['OriginatorConversationID'])) {
            return $data['Result']['OriginatorConversationID'];
        }
        // Fallback: check for a top-level key
        if (isset($data['OriginatorConversationID'])) {
            return $data['OriginatorConversationID'];
        }
        // Log the issue and return null if not found
        Log::warning("OriginatorConversationID not found in callback data: " . json_encode($data));
        return null;
    }

}
