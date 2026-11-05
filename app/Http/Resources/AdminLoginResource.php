<?php

namespace App\Http\Resources;

use App\Models\AdminLogin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdminLogin
 */
class AdminLoginResource extends JsonResource
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
            'email' => $this->email,
            'name' => $this->admin?->name,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'succeeded' => $this->succeeded,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
