<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\Download;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A logged download. Developers see who downloaded by name only; staff also see the email, IP and
 * user agent.
 *
 * @mixin Download
 */
class DownloadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $request->user() instanceof Admin;

        return [
            'id' => $this->id,
            'source' => $this->source,
            'version_name' => $this->version_name,
            'created_at' => $this->created_at,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                ...($staff ? ['email' => $this->user->email] : []),
            ] : null,
            'listing' => $this->whenLoaded('listing', fn () => [
                'id' => $this->listing->id,
                'title' => $this->listing->title,
                'slug' => $this->listing->slug,
            ]),
            $this->mergeWhen($staff, [
                'ip_address' => $this->ip_address,
                'user_agent' => $this->user_agent,
            ]),
        ];
    }
}
