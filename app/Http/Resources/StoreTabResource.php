<?php

namespace App\Http\Resources;

use App\Models\StoreTab;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoreTab
 */
class StoreTabResource extends JsonResource
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
            'label' => $this->label,
            'slug' => $this->slug,
            'rule' => $this->rule,
            'value' => $this->value,
            'group' => $this->group,
            'position' => $this->position,
            'is_active' => $this->is_active,
        ];
    }
}
