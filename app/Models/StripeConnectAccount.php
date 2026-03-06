<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeConnectAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'connect_account_id',
        'connect_account_id_index',
        'status',
        'payouts_enabled',
        'onboarding_url',
        'stripe_data',
        'verified_at',
    ];

    protected $hidden = [
        'connect_account_id_index',
    ];

    protected function casts(): array
    {
        return [
            'payouts_enabled' => 'boolean',
            'stripe_data' => 'encrypted:array',
            'verified_at' => 'datetime',
            'connect_account_id' => 'encrypted',
            'onboarding_url' => 'encrypted',
        ];
    }

    /**
     * Automatically keep the blind-index column in sync.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account) {
            if ($account->isDirty('connect_account_id') && $account->connect_account_id !== null) {
                $account->connect_account_id_index = static::blindIndex($account->connect_account_id);
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
     * Get the user that owns the Stripe Connect account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
