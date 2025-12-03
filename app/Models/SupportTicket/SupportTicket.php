<?php

namespace App\Models\SupportTicket;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupportTicket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'partner_id',
        'title',
        'priority',
        'client_name',
        'assigned_to',
        'due_date',
        'sla',
    ];
}
