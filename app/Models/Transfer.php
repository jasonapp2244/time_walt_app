<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_id',
        'user_id',
        'stripe_transfer_id',
        'stripe_transfer_id_index',
        'stripe_connect_account_id',
        'amount',
        'currency',
        'status',
        'transferred_at',
        'failure_reason',
        'stripe_data',
        'admin_id',
        'transfer_type',
        'abandoned_at',
        'email_sent_at',
        'email_status',
        'email_failure_reason',
    ];

    protected $hidden = [
        'stripe_transfer_id_index',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => 'string',
            'transferred_at' => 'datetime',
            'abandoned_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'stripe_data' => 'encrypted:array',
            'stripe_transfer_id' => 'encrypted',
            'stripe_connect_account_id' => 'encrypted',
        ];
    }

    /**
     * Automatically keep the blind-index column in sync when stripe_transfer_id changes.
     */
    protected static function booted(): void
    {
        static::saving(function (self $transfer) {
            if ($transfer->isDirty('stripe_transfer_id')) {
                $transfer->stripe_transfer_id_index = $transfer->stripe_transfer_id !== null
                    ? static::blindIndex($transfer->stripe_transfer_id)
                    : null;
            }
        });
    }

    /**
     * Compute a deterministic HMAC-SHA256 blind index for database lookups.
     */
    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    /**
     * Get the payment hold that owns the transfer.
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(PaymentHold::class, 'hold_id');
    }

    /**
     * Get the user that owns the transfer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who triggered the transfer.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    /**
     * Scope a query to only include abandoned transfers.
     */
    public function scopeAbandoned($query)
    {
        return $query->whereNotNull('abandoned_at');
    }

    /**
     * Scope a query to only include non-abandoned transfers.
     */
    public function scopeNotAbandoned($query)
    {
        return $query->whereNull('abandoned_at');
    }

    /**
     * Scope a query to only include forfeited transfers (account deletion).
     */
    public function scopeForfeited($query)
    {
        return $query->where('transfer_type', 'account_deletion_forfeited');
    }

    /**
     * Check if the transfer is abandoned.
     */
    public function getIsAbandonedAttribute(): bool
    {
        return ! is_null($this->abandoned_at);
    }
}
