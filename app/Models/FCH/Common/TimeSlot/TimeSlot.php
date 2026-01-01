<?php

namespace App\Models\FCH\Common\TimeSlot;

use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    protected $fillable = [
        'start_time',
        'end_time',
        'label',
        'is_active',
    ];
}
