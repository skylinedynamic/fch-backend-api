<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BorrowerDocumentsChecklist extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'borrower_documents_checklists';

    protected $guarded = [];

    protected $casts = [
        'deleted_at' => 'datetime',
        'funding_application_id' => 'integer',
        'borrower_id' => 'integer',
        'partner_id' => 'integer',
    ];

    public function borrower()
    {
        return $this->belongsTo(Borrower::class);
    }

    public function partner()
    {
        return $this->belongsTo(\App\Models\Partner\Partner::class);
    }

    public function docType()
    {
        return $this->belongsTo(BorrowerDocType::class, 'borrower_doc_types_id');
    }
}
