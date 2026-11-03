<?php

namespace App\Http\Resources;

use App\Enums\StaffRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Role;

/**
 * @mixin Role
 */
class StaffRoleResource extends JsonResource
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
            'permissions' => $this->permissions->pluck('name')->sort()->values(),
            'admins_count' => $this->whenCounted('users'),
            // the admin role always holds every permission and can't be changed
            'locked' => (bool) StaffRole::tryFrom($this->name)?->isLocked(),
        ];
    }
}
