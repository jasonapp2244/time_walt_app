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
        'status',
        'payouts_enabled',
        'onboarding_url',
        'stripe_data',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'payouts_enabled' => 'boolean',
            'stripe_data' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the Stripe Connect account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
