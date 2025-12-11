<?php

namespace App\Http\Resources\Borrower\Document;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'document_type_id' => $this->document_type_id,
            'file_name' => $this->file_name,
            'file_path' => $this->file_path,
            'url' => $this->url,
        ];
    }
}