<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'role' => $this->role,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'profile' => $this->profile,
            'is_verified' => $this->is_verified,
            'status' => $this->status,
            'two_factor_enabled' => $this->two_factor_enabled,
            'timezone' => $this->timezone,
            'language' => $this->language,
            'device_id' => $this->device_id,
            'device_type' => $this->device_type,
            'email_verified_at' => $this->email_verified_at,
            'last_active_at' => $this->last_active_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
