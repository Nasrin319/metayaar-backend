<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published listing as players see it in the store. Leaves out review and file details.
 *
 * @mixin Listing
 */
class StoreListingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'category' => $this->category,
            'price' => $this->price,
            'external_url' => $this->external_url,
            'version_name' => $this->version_name,
            'seated' => $this->seated,
            'comfort' => $this->comfort,
            'supported_devices' => $this->supported_devices ?? [],
            'tags' => $this->tags ?? [],
            'links' => $this->links ?? [],
            'trailer_url' => $this->trailer_url,
            'early_access' => $this->early_access,
            'apk_size' => $this->apk_size,
            'icon_url' => $this->iconUrl(),
            'title_image_url' => $this->titleImageUrl(),
            'cover_url' => $this->coverUrl(),
            'screenshots' => $this->screenshotUrls(),
            'developer' => $this->whenLoaded('developer', fn () => ['name' => $this->developer->name]),
            'published_at' => $this->reviewed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
