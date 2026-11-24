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
        'disputed_at',
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
            'disputed_at' => 'datetime',
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
     * Get the refunds of this hold's deposit.
     */
    /**
     * The status the mobile app is given. A fully refunded deposit is shown
     * to the app as `canceled` (no countdown, no withdraw, money gone), a
     * status every released app version already handles; `refunded` stays an
     * admin-only status in the database and the admin panel.
     */
    public function appStatus(): ?string
    {
        return $this->status === 'refunded' ? 'canceled' : $this->status;
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(Dispute::class, 'payment_hold_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_hold_id');
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
