<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures placed inside an article's text from the editor.
 */
class ArticleImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'upload' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $path = $request->file('upload')->store('articles/body', 'public');

        return response()->json(['url' => Storage::disk('public')->url($path)], 201);
    }
}
