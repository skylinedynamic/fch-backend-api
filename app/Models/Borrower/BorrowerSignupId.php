<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowerSignupId extends Model
{
    use HasFactory;

    protected $table = 'signup_ids';

    public $timestamps = false;

    protected $guarded = [];
}
