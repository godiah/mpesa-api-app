<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'merchant_request_id',
        'checkout_request_id',
        'reference',
        'phone',
        'amount',
        'mpesa_receipt_number',
        'transaction_date',
        'result_code',
        'result_desc',
        'status',
    ];
}
