<?php

namespace App\Models\CreditBuilder;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorAccount extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];
}
