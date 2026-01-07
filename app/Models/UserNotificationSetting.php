<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'password_alert',
        'transaction_alert',
        'push_notification_alert',
        'email_alert',
        'lock_alert',
        'unlock_alert',
    ];

    protected function casts(): array
    {
        return [
            'password_alert' => 'boolean',
            'transaction_alert' => 'boolean',
            'push_notification_alert' => 'boolean',
            'email_alert' => 'boolean',
            'lock_alert' => 'boolean',
            'unlock_alert' => 'boolean',
        ];
    }

    /**
     * Get the user that owns the notification settings.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
