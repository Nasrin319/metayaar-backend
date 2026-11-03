<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
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
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'mobile' => $this->mobile,
            'mobile_verified_at' => $this->mobile_verified_at,
            'disabled_at' => $this->disabled_at,
            'avatar_url' => $this->avatarUrl(),
            'account_type' => $this->account_type,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['name', 'national_id', 'phone'])),
            'roles' => $this->getRoleNames(),
            // the admin user list counts these, to tell whether the account can be deleted
            'listings_count' => $this->whenCounted('listings'),
            'purchases_count' => $this->whenCounted('purchases'),
            'created_at' => $this->created_at,
        ];
    }
}
