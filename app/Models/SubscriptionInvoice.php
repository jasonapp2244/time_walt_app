<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    use \App\Models\Concerns\SafelyReadsEncryptedAttributes;
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'user_id',
        'stripe_invoice_id',
        'stripe_invoice_id_index',
        'invoice_number',
        'billing_reason',
        'amount',
        'currency',
        'period_end',
        'hosted_invoice_url',
        'invoice_pdf',
        'paid_at',
    ];

    protected $hidden = [
        'stripe_invoice_id_index',
    ];

    protected function casts(): array
    {
        return [
            'stripe_invoice_id' => 'encrypted',
            'amount' => 'decimal:2',
            'period_end' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $invoice) {
            if ($invoice->isDirty('stripe_invoice_id') && $invoice->stripe_invoice_id !== null) {
                $invoice->stripe_invoice_id_index = static::blindIndex($invoice->stripe_invoice_id);
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The first payment starts a subscription; every later one renews it.
     */
    public function isRenewal(): bool
    {
        return $this->billing_reason !== 'subscription_create';
    }

    public function typeLabel(): string
    {
        return $this->isRenewal() ? 'Renewal' : 'New subscription';
    }
}
