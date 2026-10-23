<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use \App\Models\Concerns\SafelyReadsEncryptedAttributes;
    use HasFactory;

    /**
     * Stripe statuses that still give the user access. past_due keeps access
     * while Stripe retries the renewal; Stripe cancels it if every retry fails.
     */
    public const ENTITLED_STATUSES = ['active', 'trialing', 'past_due'];

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'provider',
        'stripe_subscription_id',
        'stripe_subscription_id_index',
        'status',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
    ];

    protected $hidden = [
        'stripe_subscription_id_index',
    ];

    protected function casts(): array
    {
        return [
            'stripe_subscription_id' => 'encrypted',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $subscription) {
            if ($subscription->isDirty('stripe_subscription_id') && $subscription->stripe_subscription_id !== null) {
                $subscription->stripe_subscription_id_index = static::blindIndex($subscription->stripe_subscription_id);
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public static function findByStripeId(string $stripeSubscriptionId): ?self
    {
        return static::where('stripe_subscription_id_index', static::blindIndex($stripeSubscriptionId))->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id')->withTrashed();
    }

    /**
     * Subscriptions that currently lift the free-tier limits.
     */
    public function scopeEntitled($query)
    {
        return $query->whereIn('status', self::ENTITLED_STATUSES)
            ->where('current_period_end', '>', now());
    }

    public function isEntitled(): bool
    {
        return in_array($this->status, self::ENTITLED_STATUSES, true)
            && $this->current_period_end?->isFuture();
    }
}
