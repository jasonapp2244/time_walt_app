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
        'title',
        'amount',
        'remaining_amount',
        'hold_start_at',
        'hold_end_at',
        'hold_days',
        'hold_period_type',
        'status',
        'ready_at',
        'transferred_at',
        'abandoned_at',
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
            'abandoned_at' => 'datetime',
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

    /**
     * Scope a query to only include abandoned holds.
     */
    public function scopeAbandoned($query)
    {
        return $query->whereNotNull('abandoned_at');
    }

    /**
     * Scope a query to only include non-abandoned holds.
     */
    public function scopeNotAbandoned($query)
    {
        return $query->whereNull('abandoned_at');
    }

    /**
     * Check if the hold is abandoned.
     */
    public function getIsAbandonedAttribute(): bool
    {
        return ! is_null($this->abandoned_at);
    }
}
