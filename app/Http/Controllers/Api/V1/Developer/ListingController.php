<?php

namespace App\Http\Controllers\Api\V1\Developer;

use App\Enums\ListingStatus;
use App\Enums\ListingType;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Developer\ListingRequest;
use App\Http\Resources\ListingResource;
use App\Models\Listing;
use App\Notifications\ListingAwaitingReview;
use App\Support\StaffNotifications;
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
            ->withCount('downloads')
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

        // an exe must never be offered as an APK, or an APK as a Windows program
        if ($listing->hasApk() && ($listing->type === ListingType::Windows) !== ($request->enum('type', ListingType::class) === ListingType::Windows)) {
            throw ValidationException::withMessages(['type' => ['این اپ فایل دارد و نمی‌توان نوع آن را بین ویندوز و هدست عوض کرد. یک اپ جدید بسازید.']]);
        }

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
            throw ValidationException::withMessages(['package_name' => ['پیش از ارسال برای بررسی، نام بسته (package name) را وارد کنید.']]);
        }

        if ($listing->type === ListingType::Apk && ! $listing->hasApk()) {
            throw ValidationException::withMessages(['apk' => ['پیش از ارسال برای بررسی، فایل APK را بارگذاری کنید.']]);
        }

        if ($listing->type === ListingType::Windows && ! $listing->hasApk()) {
            throw ValidationException::withMessages(['apk' => ['پیش از ارسال برای بررسی، فایل exe یا zip را بارگذاری کنید.']]);
        }

        if ($listing->price > 0 && ! $request->user()->payoutAccount()->exists()) {
            throw ValidationException::withMessages(['payout_account' => ['برای فروش اپ پولی، ابتدا اطلاعات حساب بانکی (شماره شبا) را در بخش «اطلاعات تسویه» ثبت کنید.']]);
        }

        $resubmitted = $listing->status === ListingStatus::Rejected;
        $listing->submitForReview($answers);
        StaffNotifications::send(Permission::ReviewListings, new ListingAwaitingReview($listing, $resubmitted));

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
        // also removes the notifications about its comments, which carry the listing_id too
        StaffNotifications::forget('listing_id', [$listing->id]);

        return response()->json(null, 204);
    }
}
