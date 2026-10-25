<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class BorrowerDocType extends Model
{
    use HasFactory, SoftDeletes;

    public const BANK_STATEMENTS = 'Business Bank Statements';

    /**
     * Categories a borrower may leave empty: everything else on the Documents
     * page is needed before they can apply for funding. Matched by name, in any
     * case, the way the borrower portal does.
     */
    public const OPTIONAL_NAMES = ['Others', 'Miscellaneous Documents'];

    /** The categories every borrower has to upload a document in. */
    public function scopeRequired($query)
    {
        return $query->whereNotIn(
            DB::raw('LOWER(name)'),
            array_map('strtolower', self::OPTIONAL_NAMES)
        );
    }

    protected $table = 'borrower_doc_types';

    protected $guarded = [];

    protected $casts = [
        'sort_order' => 'integer',
        'deleted_at' => 'datetime',
    ];
}
