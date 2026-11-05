<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Published articles on the website.
 */
class ArticleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return ArticleResource::collection(
            Article::published()->latest('published_at')->orderByDesc('id')->paginate($request->integer('per_page', 12))
        );
    }

    /**
     * Drafts are not found.
     */
    public function show(Article $article): ArticleResource
    {
        abort_unless($article->published_at, 404);

        return new ArticleResource($article);
    }
}
