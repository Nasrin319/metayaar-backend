<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreListingResource;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The public store catalog: published listings only, no sign-in required.
 */
class StoreController extends Controller
{
    /**
     * Published listings, newest first unless sorted by price.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::enum(ListingType::class)],
            'free' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $listings = Listing::with('developer:id,name')
            ->where('status', ListingStatus::Published)
            ->when($request->input('q'), fn ($query, $search) => $query->where(
                fn ($query) => $query->whereLike('title', "%{$search}%")->orWhereLike('tagline', "%{$search}%")
            ))
            ->when($request->input('category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->boolean('free'), fn ($query) => $query->where('price', 0))
            ->when(
                $request->input('sort'),
                fn ($query, $sort) => match ($sort) {
                    'price_asc' => $query->orderBy('price'),
                    'price_desc' => $query->orderByDesc('price'),
                    default => $query->latest('reviewed_at'),
                },
                fn ($query) => $query->latest('reviewed_at'),
            )
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 24))
            ->withQueryString();

        return StoreListingResource::collection($listings);
    }

    /**
     * A published listing's store page. Drafts and listings in review are not found.
     */
    public function show(Listing $listing): StoreListingResource
    {
        abort_unless($listing->status === ListingStatus::Published, 404);

        return new StoreListingResource($listing->load('developer:id,name'));
    }
}
