<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DemoRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_name',
        'customer_name',
        'start_date',
        'end_date',
        'phone_number',
        'email',
        'assign_to',
        'status',
    ];
}
