<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'hold_hours',
        'hold_minutes',
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
            'hold_hours' => 'integer',
            'hold_minutes' => 'integer',
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
     * Get all transfers for the hold.
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'hold_id');
    }

    /**
     * Get the latest transfer for the hold (backward-compatible).
     */
    public function transfer(): HasOne
    {
        return $this->hasOne(Transfer::class, 'hold_id')->latestOfMany();
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
