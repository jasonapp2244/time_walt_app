<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'role',
        'full_name',
        'email',
        'phone',
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
        ];
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
}
