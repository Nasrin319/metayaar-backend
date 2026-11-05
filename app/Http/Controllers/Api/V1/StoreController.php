<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreListingResource;
use App\Http\Resources\StoreTabResource;
use App\Models\Listing;
use App\Models\StoreTab;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The public store catalog: published listings only, no sign-in required.
 */
class StoreController extends Controller
{
    /**
     * Published listings, newest first unless sorted by price. A `tab` narrows them by that store tab's rule.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tab' => ['nullable', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:50'],
            'type' => ['nullable', Rule::enum(ListingType::class)],
            'free' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tab = $request->filled('tab')
            ? StoreTab::where('is_active', true)->where('slug', $request->input('tab'))->firstOrFail()
            : null;

        $listings = Listing::with('developer:id,name')
            ->where('status', ListingStatus::Published)
            ->where('is_private', false)
            ->when($tab, fn ($query) => $tab->rule->apply($query, $tab->value))
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
     * The active filter tabs of the apps page, in display order.
     */
    public function tabs(): AnonymousResourceCollection
    {
        return StoreTabResource::collection(StoreTab::where('is_active', true)->ordered()->get());
    }

    /**
     * A published listing's store page. Drafts, listings in review and private listings the viewer
     * wasn't invited to are not found. A bearer token, when sent, says who the viewer is.
     */
    public function show(Request $request, Listing $listing): StoreListingResource
    {
        $viewer = $request->user('sanctum');
        abort_unless($listing->isAvailableTo($viewer instanceof User ? $viewer : null), 404);

        return new StoreListingResource($listing->load('developer:id,name'));
    }

    /**
     * Private listings the signed-in user's verified email was invited to, newest first.
     */
    public function privateApps(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        if (! $user->email_verified_at) {
            return StoreListingResource::collection([]);
        }

        return StoreListingResource::collection(
            Listing::with('developer:id,name')
                ->where('status', ListingStatus::Published)
                ->where('is_private', true)
                ->whereHas('invites', fn ($query) => $query->where('email', mb_strtolower($user->email)))
                ->latest('reviewed_at')
                ->orderByDesc('id')
                ->get()
        );
    }
}
