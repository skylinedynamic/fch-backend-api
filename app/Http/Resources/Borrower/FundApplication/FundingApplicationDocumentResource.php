<?php

namespace App\Http\Resources\Borrower\FundApplication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FundingApplicationDocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'funding_application_id' => $this->funding_application_id,
            'borrower_id' => $this->borrower_id,
            'borrower_doc_type_id' => $this->borrower_doc_type_id,
            'name' => $this->name,
            'path' => $this->path,
            'url' => $this->url,
            'original_name' => $this->original_name,
            'extension' => $this->extension,
            'mime' => $this->mime,
            'size' => $this->size,
            'created_at' => $this->created_at,
        ];
    }
}
