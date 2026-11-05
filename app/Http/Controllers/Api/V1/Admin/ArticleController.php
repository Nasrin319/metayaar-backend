<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ArticleRequest;
use App\Http\Resources\ArticleResource;
use App\Models\Article;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Articles in the admin panel, including drafts.
 */
class ArticleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ArticleResource::collection(Article::latest()->orderByDesc('id')->paginate(30));
    }

    public function show(Article $article): ArticleResource
    {
        return new ArticleResource($article);
    }

    public function store(ArticleRequest $request): ArticleResource
    {
        return $this->save(new Article, $request);
    }

    public function update(ArticleRequest $request, Article $article): ArticleResource
    {
        return $this->save($article, $request);
    }

    public function destroy(Article $article): Response
    {
        $article->delete();

        return response()->noContent();
    }

    private function save(Article $article, ArticleRequest $request): ArticleResource
    {
        $article->fill($request->safe()->only(['title', 'slug', 'excerpt', 'body']));
        // the first publish date stays while it remains published
        $article->published_at = $request->boolean('is_published') ? ($article->published_at ?? now()) : null;
        $article->save();

        if ($request->hasFile('image')) {
            $article->replaceImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $article->replaceImage(null);
        }

        return new ArticleResource($article);
    }
}
