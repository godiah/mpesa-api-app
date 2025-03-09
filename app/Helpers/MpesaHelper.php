<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Log;

class MpesaHelper
{
    public static function getOriginatorConversationID(array $data)
    {
        if (isset($data['Result']['OriginatorConversationID'])) {
            return $data['Result']['OriginatorConversationID'];
        }
        if (isset($data['OriginatorConversationID'])) {
            return $data['OriginatorConversationID'];
        }
        Log::warning("OriginatorConversationID not found in callback data: " . json_encode($data));
        return null;
    }
}
