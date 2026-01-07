<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationSettingsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'password_alert' => $this->password_alert,
            'transaction_alert' => $this->transaction_alert,
            'push_notification_alert' => $this->push_notification_alert,
            'email_alert' => $this->email_alert,
            'lock_alert' => $this->lock_alert,
            'unlock_alert' => $this->unlock_alert,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

