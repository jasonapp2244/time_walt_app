<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'features',
        'price',
        'currency',
        'interval',
        'stripe_product_id',
        'stripe_price_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Plans the app may offer: switched on and already priced in Stripe.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->whereNotNull('stripe_price_id')
            ->orderBy('sort_order')
            ->orderBy('price');
    }

    public function priceInCents(): int
    {
        return (int) round((float) $this->price * 100);
    }

    /**
     * The plan's price as a monthly amount, for MRR.
     */
    public function monthlyAmount(): float
    {
        return $this->interval === 'year'
            ? round((float) $this->price / 12, 2)
            : (float) $this->price;
    }

    /**
     * Shape returned to the mobile app.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'features' => $this->features ?? [],
            'price' => (float) $this->price,
            'currency' => strtoupper($this->currency),
            'interval' => $this->interval,
        ];
    }
}
