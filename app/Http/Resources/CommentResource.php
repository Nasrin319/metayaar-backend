<?php

namespace App\Http\Resources;

use App\Models\Admin;
use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment or reply. The store gets the author's name and picture; staff also get the email, the app
 * and the comment a reply answers.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
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
            'body' => $this->body,
            'status' => $this->status,
            'is_reply' => $this->isReply(),
            'created_at' => $this->created_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar_url' => $this->user->avatarUrl(),
                ...($staff ? ['email' => $this->user->email] : []),
            ]),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            $this->mergeWhen($staff, fn () => [
                'reviewed_at' => $this->reviewed_at,
                'rejection_reason' => $this->rejection_reason,
                'listing' => $this->whenLoaded('listing', fn () => $this->listing->only(['id', 'title', 'slug'])),
                'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                    'id' => $this->parent->id,
                    'body' => $this->parent->body,
                    'user' => $this->parent->user?->only(['id', 'name']),
                ] : null),
            ]),
        ];
    }
}
