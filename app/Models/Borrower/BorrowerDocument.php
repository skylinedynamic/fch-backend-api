<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerDocument extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'document_type_id',
        'borrower_id',
        'application_id',
        'document',
        'created_by',
        'deleted_by',
    ];
}
