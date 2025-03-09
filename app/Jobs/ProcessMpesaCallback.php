<?php

namespace App\Jobs;

use App\Helpers\MpesaHelper;
use App\Models\MpesaB2CTransaction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMpesaCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Reattempt to find the transaction
        $originatorConversationId = MpesaHelper::getOriginatorConversationID($this->data);
        $transaction = MpesaB2CTransaction::where('originator_conversation_id', $originatorConversationId)->first();

        if (!$transaction) {
            // Log that the job was unable to process the callback, or flag for manual review.
            Log::warning('Callback reprocessing failed: transaction not found', $this->data);
            return;
        }

        // Process the callback data if transaction is found...
        $transaction->update([
            'conversation_id' => $this->data['ConversationID'] ?? null,
            'result_code' => $this->data['ResultCode'] ?? null,
            'result_description' => $this->data['ResultDesc'] ?? null,
            'status' => ($this->data['ResultCode'] ?? 1) == 0 ? 'completed' : 'failed',
            'result_data' => $this->data,
        ]);
    }
}
