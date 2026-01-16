<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StripeWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'stripe_event_id',
        'event_type',
        'status',
        'payload',
        'processed_at',
        'error_message',
        'retry_count',
        'last_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'payload' => 'array',
            'processed_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }
}
