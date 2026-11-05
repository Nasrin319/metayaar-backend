<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The banners the website's home page shows right now.
 */
class BannerController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return BannerResource::collection(Banner::live()->ordered()->get());
    }
}
