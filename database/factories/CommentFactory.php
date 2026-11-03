<?php

namespace Database\Factories;

use App\Enums\CommentStatus;
use App\Models\Comment;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'user_id' => User::factory(),
            'body' => fake()->paragraph(),
            'status' => CommentStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommentStatus::Approved,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommentStatus::Rejected,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * The app developer's reply to a comment, on the same app.
     */
    public function replyTo(Comment $comment): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_id' => $comment->listing_id,
            'user_id' => $comment->listing->user_id,
            'parent_id' => $comment->id,
        ]);
    }
}
