<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreTabRequest;
use App\Http\Resources\StoreTabResource;
use App\Models\StoreTab;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The filter tabs on the store's apps page, including hidden ones.
 */
class StoreTabController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StoreTabResource::collection(StoreTab::ordered()->get());
    }

    public function store(StoreTabRequest $request): StoreTabResource
    {
        return new StoreTabResource(StoreTab::create($request->validated()));
    }

    public function update(StoreTabRequest $request, StoreTab $storeTab): StoreTabResource
    {
        $storeTab->update($request->validated());

        return new StoreTabResource($storeTab);
    }

    public function destroy(StoreTab $storeTab): Response
    {
        $storeTab->delete();

        return response()->noContent();
    }
}
