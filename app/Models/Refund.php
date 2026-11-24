<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund of a deposit, in full or in part, back to the card that paid it.
 *
 * Money columns: amount = vault_amount (taken from the hold) + reversed_amount
 * (pulled back from the user's Connect account before the card refund).
 */
class Refund extends Model
{
    use \App\Models\Concerns\SafelyReadsEncryptedAttributes;
    use HasFactory;

    public const REASONS = [
        'requested_by_customer' => 'Customer request',
        'duplicate' => 'Duplicate payment',
        'fraudulent' => 'Fraud',
        'other' => 'Other',
    ];

    /** Statuses that hold the deposit's money: a new refund is not allowed while one exists. */
    public const ACTIVE_STATUSES = ['pending', 'succeeded'];

    protected $fillable = [
        'payment_id',
        'payment_hold_id',
        'user_id',
        'admin_id',
        'request_token',
        'stripe_refund_id',
        'amount',
        'vault_amount',
        'reversed_amount',
        'currency',
        'card_brand',
        'card_last4',
        'reason_code',
        'reason_note',
        'source',
        'status',
        'hold_status_before',
        'failure_reason',
        'reversals',
        'arn',
        'needs_attention',
        'refunded_at',
    ];

    protected $hidden = [
        'stripe_refund_id_index',
        'request_token',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vault_amount' => 'decimal:2',
            'reversed_amount' => 'decimal:2',
            'stripe_refund_id' => 'encrypted',
            'reversals' => 'encrypted:array',
            'needs_attention' => 'boolean',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $refund) {
            if ($refund->isDirty('stripe_refund_id')) {
                $refund->stripe_refund_id_index = $refund->stripe_refund_id !== null
                    ? static::blindIndex($refund->stripe_refund_id)
                    : null;
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public static function findByStripeId(string $stripeRefundId): ?self
    {
        return static::where('stripe_refund_id_index', static::blindIndex($stripeRefundId))->first();
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

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_code] ?? 'Not given';
    }

    /** "Visa •••• 4242", or "the original card" when the deposit stored no card details. */
    public function cardLabel(): string
    {
        return static::formatCard($this->card_brand, $this->card_last4);
    }

    public static function formatCard(?string $brand, ?string $last4): string
    {
        if (! $last4) {
            return 'the original card';
        }

        $name = match (strtolower((string) $brand)) {
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'amex' => 'American Express',
            'discover' => 'Discover',
            'diners' => 'Diners Club',
            'jcb' => 'JCB',
            'unionpay' => 'UnionPay',
            '' => 'Card',
            default => ucfirst((string) $brand),
        };

        return "{$name} •••• {$last4}";
    }

    /** Less than the whole deposit was refunded by this refund. */
    public function isPartial(): bool
    {
        $deposit = $this->hold?->amount ?? $this->payment?->amount;

        return $deposit !== null && (int) round((float) $this->amount * 100) < (int) round((float) $deposit * 100);
    }

    /** Last 8 characters of the Stripe refund ID, safe to show and email. */
    public function reference(): string
    {
        $id = rescue(fn () => $this->stripe_refund_id, null, false);

        return $id ? '…'.substr($id, -8) : 'REF-'.str_pad((string) $this->id, 5, '0', STR_PAD_LEFT);
    }
}
