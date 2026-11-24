<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chargeback (Stripe dispute) on a deposit. See RefundService/DisputeService.
 */
class Dispute extends Model
{
    public const REASONS = [
        'fraudulent' => 'Fraudulent',
        'duplicate' => 'Duplicate',
        'product_not_received' => 'Product not received',
        'product_unacceptable' => 'Product unacceptable',
        'subscription_canceled' => 'Subscription canceled',
        'credit_not_processed' => 'Credit not processed',
        'unrecognized' => 'Unrecognized',
        'general' => 'General',
    ];

    protected $fillable = [
        'payment_id',
        'payment_hold_id',
        'user_id',
        'stripe_dispute_id',
        'amount',
        'vault_amount',
        'currency',
        'reason',
        'stripe_status',
        'outcome',
        'needs_attention',
        'note',
        'evidence_due_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vault_amount' => 'decimal:2',
            'needs_attention' => 'boolean',
            'evidence_due_by' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function hold(): BelongsTo
    {
        return $this->belongsTo(PaymentHold::class, 'payment_hold_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) ($this->reason ?: 'unknown')));
    }

    /** Vault money taken by lost disputes on a hold (part of the ledger). */
    public static function lostVaultTotal(int $holdId): float
    {
        return (float) static::where('payment_hold_id', $holdId)->where('outcome', 'lost')->sum('vault_amount');
    }
}
