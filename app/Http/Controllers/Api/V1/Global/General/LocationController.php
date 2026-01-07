<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Get all locations (Countries, States, Cities).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $locations = config('locations');
        return response()->json($locations);
    }
}
