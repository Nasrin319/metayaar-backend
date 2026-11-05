<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminLoginResource;
use App\Models\AdminLogin;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Who tried to sign in to the admin panel, and from where.
 */
class AdminLoginController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminLoginResource::collection(
            AdminLogin::with('admin:id,name')->latest('created_at')->orderByDesc('id')->paginate(50)
        );
    }
}
