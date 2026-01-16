<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transfer extends Model
{
    use HasFactory;

    protected $fillable = [
        'hold_id',
        'user_id',
        'stripe_transfer_id',
        'stripe_connect_account_id',
        'amount',
        'currency',
        'status',
        'transferred_at',
        'failure_reason',
        'stripe_data',
        'admin_id',
        'transfer_type',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => 'string',
            'transferred_at' => 'datetime',
            'stripe_data' => 'array',
        ];
    }

    /**
     * Get the payment hold that owns the transfer.
     */
    public function hold(): BelongsTo
    {
        return $this->belongsTo(PaymentHold::class, 'hold_id');
    }

    /**
     * Get the user that owns the transfer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the admin who triggered the transfer.
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
