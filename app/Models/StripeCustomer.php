<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeCustomer extends Model
{
    protected $fillable = [
        'user_id',
        'stripe_customer_id',
        'stripe_customer_id_index',
        'stripe_data',
    ];

    protected $hidden = [
        'stripe_customer_id_index',
    ];

    protected function casts(): array
    {
        return [
            'stripe_customer_id' => 'encrypted',
            'stripe_data' => 'encrypted:array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $customer) {
            if ($customer->isDirty('stripe_customer_id') && $customer->stripe_customer_id !== null) {
                $customer->stripe_customer_id_index = static::blindIndex($customer->stripe_customer_id);
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
