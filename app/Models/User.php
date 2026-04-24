<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'full_name',
        'email',
        'email_index',
        'phone',
        'phone_index',
        'password',
        'profile',
        'otp_code',
        'otp_expires_at',
        'is_verified',
        'status',
        'two_factor_enabled',
        'email_verified_at',
        'provider',
        'provider_id',
        'provider_id_index',
        'timezone',
        'language',
        'fcm_token',
        'device_id',
        'device_type',
        'token',
        'expires_at',
        'last_active_at',
        'deleted_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'token',
        'email_index',
        'phone_index',
        'provider_id_index',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'otp_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_active_at' => 'datetime',
            'deleted_at' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
            'two_factor_enabled' => 'boolean',
            // Encrypted PII fields
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'full_name' => 'encrypted',
            'provider_id' => 'encrypted',
            'fcm_token' => 'encrypted',
            'device_id' => 'encrypted',
            'otp_code' => 'encrypted',
            'token' => 'encrypted',
        ];
    }

    /**
     * Automatically keep blind-index columns in sync when searchable fields change.
     */
    protected static function booted(): void
    {
        static::saving(function (self $user) {
            if ($user->isDirty('email') && $user->email !== null) {
                $user->email_index = static::blindIndex(strtolower($user->email));
            }

            if ($user->isDirty('phone')) {
                $user->phone_index = $user->phone !== null
                    ? static::blindIndex(strtolower($user->phone))
                    : null;
            }

            if ($user->isDirty('provider_id')) {
                $user->provider_id_index = $user->provider_id !== null
                    ? static::blindIndex($user->provider_id)
                    : null;
            }
        });
    }

    /**
     * Compute a deterministic HMAC-SHA256 blind index for database lookups.
     * Uses APP_KEY so the index is useless without the application secret.
     */
    public static function blindIndex(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    /**
     * Get the notification settings for the user.
     */
    public function notificationSettings()
    {
        return $this->hasOne(UserNotificationSetting::class);
    }

    /**
     * Get the payment holds for the user.
     */
    public function paymentHolds()
    {
        return $this->hasMany(PaymentHold::class);
    }

    /**
     * Get the transfers for the user.
     */
    public function transfers()
    {
        return $this->hasMany(Transfer::class);
    }

    /**
     * Get the Stripe customer for the user.
     */
    public function stripeCustomer()
    {
        return $this->hasOne(StripeCustomer::class);
    }

    /**
     * Get all bank accounts for the user.
     */
    public function bankAccounts()
    {
        return $this->hasMany(UserBankAccount::class);
    }

    /**
     * Get the primary bank account for the user.
     */
    public function primaryBankAccount()
    {
        return $this->hasOne(UserBankAccount::class)->where('is_primary', true);
    }
}
