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
        'payment_intent_id_index',
        'amount',
        'currency',
        'status',
        'paid_at',
        'stripe_data',
        'failure_reason',
        'card_brand',
        'card_last4',
        'card_exp_month',
        'card_exp_year',
        'card_funding',
        'card_country',
        'payment_method_type',
    ];

    protected $hidden = [
        'payment_intent_id_index',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => 'string',
            'paid_at' => 'datetime',
            'stripe_data' => 'encrypted:array',
            'payment_intent_id' => 'encrypted',
            'card_exp_month' => 'integer',
            'card_exp_year' => 'integer',
        ];
    }

    /**
     * Automatically keep the blind-index column in sync when payment_intent_id changes.
     */
    protected static function booted(): void
    {
        static::saving(function (self $payment) {
            if ($payment->isDirty('payment_intent_id') && $payment->payment_intent_id !== null) {
                $payment->payment_intent_id_index = static::blindIndex($payment->payment_intent_id);
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
