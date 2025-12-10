<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerDocType extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'borrower_doc_types';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
