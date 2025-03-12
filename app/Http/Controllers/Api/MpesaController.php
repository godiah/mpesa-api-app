<?php

namespace App\Http\Controllers\Api;

use App\Helpers\MpesaHelper;
use App\Http\Controllers\Controller;
use App\Models\MpesaB2CTransaction;
use App\Models\MpesaTransaction;
use App\Services\MpesaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

            Log::info('Detailed STK Push Response', [
                'raw_response' => $response,
                'merchant_request_id' => $response['MerchantRequestID'] ?? 'NOT FOUND',
                'checkout_request_id' => $response['CheckoutRequestID'] ?? 'NOT FOUND'
            ]);

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
        Log::info('M-PESA Callback Received', ['data' => $request->all()]);

        try {
            $callbackData = $request->all();
            $callbackBody = $callbackData['Body'] ?? null;

            if (!$callbackBody) {
                Log::error('Invalid callback data - missing Body', ['callback' => $callbackData]);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback data']);
            }

            $stkCallback = $callbackBody['stkCallback'] ?? null;
            if (!$stkCallback) {
                Log::error('Invalid callback data - missing stkCallback', ['body' => $callbackBody]);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Invalid stkCallback data']);
            }

            $resultCode = $stkCallback['ResultCode'] ?? null;
            $merchantRequestID = $stkCallback['MerchantRequestID'] ?? null;
            $checkoutRequestID = $stkCallback['CheckoutRequestID'] ?? null;

            if (!$merchantRequestID || !$checkoutRequestID) {
                Log::error('Missing request IDs in callback', ['stkCallback' => $stkCallback]);
                return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Missing request IDs']);
            }

            // Find the transaction
            $transaction = MpesaTransaction::where('checkout_request_id', $checkoutRequestID)->first();

            if (!$transaction) {
                Log::error('Transaction not found for checkout_request_id', [
                    'checkout_request_id' => $checkoutRequestID,
                    'merchant_request_id' => $merchantRequestID
                ]);

                // Try to find by merchant request ID as fallback
                $transaction = MpesaTransaction::where('merchant_request_id', $merchantRequestID)->first();

                if (!$transaction) {
                    return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Transaction not found']);
                }
            }

            Log::info('Transaction found', [
                'transaction_id' => $transaction->id,
                'order_id' => $transaction->order_id,
                'result_code' => $resultCode
            ]);

            // Process the payment result
            if ($resultCode == 0) {
                // Extract payment details from callback metadata
                $callbackMetadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
                $mpesaReceiptNumber = null;
                $transactionDate = null;
                $phoneNumber = null;
                $amount = null;

                // Parse the callback metadata items
                foreach ($callbackMetadata as $item) {
                    if ($item['Name'] == 'MpesaReceiptNumber') {
                        $mpesaReceiptNumber = $item['Value'] ?? null;
                    } else if ($item['Name'] == 'TransactionDate') {
                        $transactionDate = $item['Value'] ?? null;
                    } else if ($item['Name'] == 'PhoneNumber') {
                        $phoneNumber = $item['Value'] ?? null;
                    } else if ($item['Name'] == 'Amount') {
                        $amount = $item['Value'] ?? null;
                    }
                }

                // Update transaction with payment details
                $transaction->update([
                    'mpesa_receipt_number' => $mpesaReceiptNumber,
                    'transaction_date' => $transactionDate,
                    'status' => 'completed',
                    'result_code' => $resultCode,
                    'result_desc' => $stkCallback['ResultDesc'] ?? 'Success'
                ]);

                // Notify the e-shop application about the payment
                $this->notifyEshop($transaction);
            } else {
                // Payment failed
                $transaction->update([
                    'status' => 'failed',
                    'result_code' => $resultCode,
                    'result_desc' => $stkCallback['ResultDesc'] ?? 'Failed'
                ]);

                // Notify the e-shop application about the failure
                $this->notifyEshop($transaction);
            }

            // Always respond with success to M-PESA
            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Callback received successfully']);
        } catch (\Exception $e) {
            Log::error('Exception in M-PESA callback handler', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['ResultCode' => 1, 'ResultDesc' => 'Error processing callback']);
        }
    }

    /**
     * Notify the e-shop application about the payment status
     */
    private function notifyEshop($transaction)
    {
        try {
            // Make a request to the e-shop's webhook endpoint
            $response = Http::post('http://localhost:5000/api/mpesa/webhook', [
                'reference' => $transaction->reference,
                'merchant_request_id' => $transaction->merchant_request_id,
                'checkout_request_id' => $transaction->checkout_request_id,
                'mpesa_receipt_number' => $transaction->mpesa_receipt_number,
                'transaction_date' => $transaction->transaction_date,
                'status' => $transaction->status,
                'result_code' => $transaction->result_code,
                'result_desc' => $transaction->result_desc,
            ]);

            Log::info('E-shop notification response', [
                'status' => $response->status(),
                'body' => $response->json()
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to notify e-shop', [
                'error' => $e->getMessage(),
                'transaction' => $transaction->id
            ]);
        }
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

            $response = $this->mpesaService->b2cPayment($phone, $validated['amount'], $validated['remarks'], $validated['occasion']);

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
