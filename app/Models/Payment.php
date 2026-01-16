<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_intent_id',
        'amount',
        'currency',
        'status',
        'paid_at',
        'stripe_data',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => 'string',
            'paid_at' => 'datetime',
            'stripe_data' => 'array',
        ];
    }

    /**
     * Get the user that owns the payment.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the payment hold for the payment.
     */
    public function hold(): HasOne
    {
        return $this->hasOne(PaymentHold::class);
    }
}
