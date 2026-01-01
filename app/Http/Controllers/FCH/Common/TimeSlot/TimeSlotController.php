<?php

namespace App\Http\Controllers\FCH\Common\TimeSlot;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FCH\Common\TimeSlot\TimeSlot;

class TimeSlotController extends Controller
{

    /**
     * Fetch all time slots without pagination.
     */
    public function fetchAll()
    {
        $slots = TimeSlot::where('is_active', true)->get();
        return response()->json([
            'status' => true,
            'message' => 'Time slots retrieved successfully.',
            'data' => $slots
        ]);
    }
}
