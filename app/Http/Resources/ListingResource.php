<?php

namespace App\Http\Resources;

use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Listing
 */
class ListingResource extends JsonResource
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
            'title' => $this->title,
            'slug' => $this->slug,
            'type' => $this->type,
            'status' => $this->status,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'category' => $this->category,
            'price' => $this->price,
            'package_name' => $this->package_name,
            'external_url' => $this->external_url,
            'version_code' => $this->version_code,
            'version_name' => $this->version_name,
            'seated' => $this->seated,
            'comfort' => $this->comfort,
            'supported_devices' => $this->supported_devices ?? [],
            'tags' => $this->tags ?? [],
            'links' => $this->links ?? [],
            'trailer_url' => $this->trailer_url,
            'early_access' => $this->early_access,
            'apk' => $this->hasApk() ? [
                'original_name' => $this->apk_original_name,
                'size' => $this->apk_size,
                'sha256' => $this->apk_sha256,
                'uploaded_at' => $this->apk_uploaded_at,
            ] : null,
            'icon_url' => $this->iconUrl(),
            'icon_from_apk' => $this->icon_from_apk,
            'title_image_url' => $this->titleImageUrl(),
            'cover_url' => $this->coverUrl(),
            'screenshots' => $this->screenshotUrls(),
            'submitted_at' => $this->submitted_at,
            'submission_answers' => $this->submission_answers,
            'reviewed_at' => $this->reviewed_at,
            'rejection_reason' => $this->rejection_reason,
            'developer' => $this->whenLoaded('developer', fn () => [
                'id' => $this->developer->id,
                'name' => $this->developer->name,
                'email' => $this->developer->email,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
