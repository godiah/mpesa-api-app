<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaB2CTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'originator_conversation_id',
        'conversation_id',
        'command_id',
        'initiator_name',
        'phone_number',
        'amount',
        'result_code',
        'result_description',
        'remarks',
        'occasion',
        'status',
        'request_data',
        'result_data',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_data' => 'array',
        'result_data' => 'array',
    ];
}
