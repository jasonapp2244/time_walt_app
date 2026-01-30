<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'user_id',
        'amount',
        'remaining_amount',
        'hold_start_at',
        'hold_end_at',
        'hold_days',
        'hold_period_type',
        'status',
        'ready_at',
        'transferred_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'hold_start_at' => 'datetime',
            'hold_end_at' => 'datetime',
            'hold_days' => 'integer',
            'status' => 'string',
            'ready_at' => 'datetime',
            'transferred_at' => 'datetime',
        ];
    }

    /**
     * Get the payment that owns the hold.
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the user that owns the hold.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the transfer for the hold.
     */
    public function transfer(): HasOne
    {
        return $this->hasOne(Transfer::class, 'hold_id');
    }
}
