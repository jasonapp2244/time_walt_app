<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBankAccount extends Model
{
    protected $fillable = [
        'user_id',
        'account_holder_name',
        'bank_name',
        'account_number',
        'account_number_index',
        'routing_number',
        'iban',
        'swift_code',
        'account_type',
        'country',
        'currency',
        'stripe_bank_account_id',
        'stripe_bank_account_id_index',
        'dob',
        'is_primary',
    ];

    protected $hidden = [
        'account_number_index',
        'stripe_bank_account_id_index',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'account_holder_name' => 'encrypted',
            'bank_name' => 'encrypted',
            'account_number' => 'encrypted',
            'routing_number' => 'encrypted',
            'iban' => 'encrypted',
            'stripe_bank_account_id' => 'encrypted',
            'dob' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $bankAccount) {
            if ($bankAccount->isDirty('account_number') && $bankAccount->account_number !== null) {
                $bankAccount->account_number_index = static::blindIndex($bankAccount->account_number);
            }

            if ($bankAccount->isDirty('stripe_bank_account_id')) {
                $bankAccount->stripe_bank_account_id_index = $bankAccount->stripe_bank_account_id !== null
                    ? static::blindIndex($bankAccount->stripe_bank_account_id)
                    : null;
            }
        });
    }

    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    public function getMaskedAccountNumberAttribute(): string
    {
        $number = $this->account_number ?? '';

        return '****'.substr($number, -4);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
