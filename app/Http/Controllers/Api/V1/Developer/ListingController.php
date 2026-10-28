<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\ListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ListingController extends Controller
{
    /**
     * The signed-in developer's listings, optionally filtered by type and status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::enum(ListingType::class)],
            'status' => ['nullable', Rule::enum(ListingStatus::class)],
        ]);

        $listings = $request->user()->listings()
            ->when($request->input('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->input('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest()
            ->get();

        return ListingResource::collection($listings);
    }

    /**
     * Create a draft listing.
     */
    public function store(ListingRequest $request): JsonResponse
    {
        $listing = $request->user()->listings()->create($request->validated());

        return (new ListingResource($listing))->response()->setStatusCode(201);
    }

    public function show(Listing $listing): ListingResource
    {
        Gate::authorize('view', $listing);

        return new ListingResource($listing);
    }

    public function update(ListingRequest $request, Listing $listing): ListingResource
    {
        Gate::authorize('update', $listing);

        $listing->update($request->validated());

        return new ListingResource($listing);
    }

    /**
     * Send the listing to the moderation queue together with the developer's submission checklist.
     */
    public function submit(Request $request, Listing $listing): ListingResource
    {
        Gate::authorize('submit', $listing);

        $answers = $request->validate([
            'answers' => ['nullable', 'array'],
            'answers.*' => ['boolean'],
        ])['answers'] ?? [];

        if ($listing->type === ListingType::Apk && ! $listing->package_name) {
            throw ValidationException::withMessages(['package_name' => ['Set the package name before submitting for review.']]);
        }

        if ($listing->type === ListingType::Apk && ! $listing->hasApk()) {
            throw ValidationException::withMessages(['apk' => ['Upload the APK file before submitting for review.']]);
        }

        $listing->submitForReview($answers);

        return new ListingResource($listing);
    }

    /**
     * Copy the listing into a new draft, under another package name when it ships an APK.
     */
    public function duplicate(Request $request, Listing $listing): JsonResponse
    {
        Gate::authorize('view', $listing);

        $data = $request->validate([
            'package_name' => [
                Rule::requiredIf($listing->type === ListingType::Apk),
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-zA-Z][\w]*(\.[a-zA-Z][\w]*)+$/',
            ],
        ]);

        $copy = $listing->duplicate($data['package_name'] ?? null);

        return (new ListingResource($copy))->response()->setStatusCode(201);
    }

    public function destroy(Listing $listing): JsonResponse
    {
        Gate::authorize('delete', $listing);

        $listing->delete();

        return response()->json(null, 204);
    }
}
