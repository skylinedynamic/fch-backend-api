<?php

namespace App\Http\Controllers\Api\V1\Global\General;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Database\Seeders\LocationHelper;

class LocationController extends Controller
{
    use LocationHelper;

    /**
     * Get all locations (Countries, States, Cities).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        return response()->json($this->locationData);
    }
}
