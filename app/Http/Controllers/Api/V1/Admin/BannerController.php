<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\BannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Occasion banners in the admin panel, including ones not showing right now.
 */
class BannerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return BannerResource::collection(Banner::ordered()->get());
    }

    public function store(BannerRequest $request): BannerResource
    {
        $banner = new Banner($request->bannerAttributes());
        $banner->image_path = $request->file('image')->store('banners', 'public');
        $banner->save();

        return new BannerResource($banner);
    }

    public function update(BannerRequest $request, Banner $banner): BannerResource
    {
        $banner->update($request->bannerAttributes());

        if ($request->hasFile('image')) {
            $banner->replaceImage($request->file('image'));
        }

        return new BannerResource($banner);
    }

    public function destroy(Banner $banner): Response
    {
        $banner->delete();

        return response()->noContent();
    }
}
