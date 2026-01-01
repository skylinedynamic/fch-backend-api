<?php

namespace App\Http\Controllers\Api\V1\FCH\Common\TimeSlot;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\FCH\Common\TimeSlot\TimeSlot;

class TimeSlotController extends Controller
{

    /**
     * Fetch Time Slots
     */
    public function fetchAll()
    {
        $slots = TimeSlot::where('is_active', true)->get();
        return response()->json([
            'status' => true,
            'message' => __('timeslot.retrieved'),
            'data' => $slots
        ]);
    }
}
