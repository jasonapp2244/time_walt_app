<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee Stripe kept, copied from one Stripe balance transaction by
 * stripe:sync-fees. Admin reporting only; nothing in the app's money flow
 * reads it.
 */
class StripeFee extends Model
{
    use \App\Models\Concerns\SafelyReadsEncryptedAttributes;

    public const CATEGORIES = [
        'deposit' => 'Deposit card fee',
        'subscription' => 'Subscription card fee',
        'chargeback' => 'Chargeback fee',
        'refund' => 'Refund',
        'platform' => 'Platform / Connect fee',
        'other' => 'Other',
    ];

    protected $fillable = [
        'balance_transaction_id',
        'balance_transaction_id_index',
        'type',
        'category',
        'description',
        'user_id',
        'payment_id',
        'subscription_invoice_id',
        'dispute_id',
        'gross',
        'fee',
        'net',
        'fee_details',
        'currency',
        'occurred_at',
    ];

    protected $hidden = [
        'balance_transaction_id_index',
    ];

    protected function casts(): array
    {
        return [
            'balance_transaction_id' => 'encrypted',
            'gross' => 'decimal:2',
            'fee' => 'decimal:2',
            'net' => 'decimal:2',
            'fee_details' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $fee) {
            if ($fee->isDirty('balance_transaction_id') && $fee->balance_transaction_id !== null) {
                $fee->balance_transaction_id_index = static::blindIndex($fee->balance_transaction_id);
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }

    /** The last 8 characters of the Stripe reference, enough to search it in the Dashboard. */
    public function reference(): string
    {
        $id = (string) $this->balance_transaction_id;

        return $id === '' ? '—' : '…'.substr($id, -8);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function subscriptionInvoice(): BelongsTo
    {
        return $this->belongsTo(SubscriptionInvoice::class);
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }
}
