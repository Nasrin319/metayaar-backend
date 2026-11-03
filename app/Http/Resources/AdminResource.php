<?php

namespace App\Http\Resources;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Admin
 */
class AdminResource extends JsonResource
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
            'avatar_url' => $this->avatarUrl(),
            'roles' => $this->getRoleNames(),
            'permissions' => $this->grantedPermissions(),
            'is_super_admin' => $this->isSuperAdmin(),
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
        ];
    }
}
