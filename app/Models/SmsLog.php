<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'recipient_phone',
        'message',
        'purpose',
        'driver',
        'status',
        'provider_message_id',
        'provider_response_code',
        'response_payload',
        'error_message',
        'retries_count',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'retries_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
