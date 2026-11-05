<?php

namespace App\Models;

use App\Models\Concerns\HasImage;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * An article written in the admin panel. The body is HTML, cleaned by App\Support\ArticleHtml.
 */
#[Fillable(['title', 'slug', 'excerpt', 'body', 'published_at'])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory, HasImage;

    protected static function booted(): void
    {
        static::saving(function (Article $article): void {
            $article->slug = static::uniqueSlug($article->slug ?: $article->title, $article->id);
        });

        static::deleted(function (Article $article): void {
            preg_match_all('#/storage/(articles/body/[\w.-]+)#', $article->body, $images);
            Storage::disk('public')->delete($images[1]);
        });
    }

    /**
     * A URL-safe version of the text that keeps Persian letters, so links stay readable.
     */
    public static function slugify(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', Str::lower($text)), '-');
    }

    private static function uniqueSlug(string $source, ?int $ignoreId): string
    {
        $base = static::slugify($source) ?: 'article';
        $slug = $base;

        while (static::where('slug', $slug)->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    /**
     * Articles visitors can read.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }
}
