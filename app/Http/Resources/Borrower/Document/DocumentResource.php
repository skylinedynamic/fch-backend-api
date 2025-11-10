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
            'user_id' => $this->user_id,
            'borrower_doc_type_id' => $this->borrower_doc_type_id,
            'name' => $this->name,
            'path' => $this->path,
            'disk' => $this->disk,
            'url' => $this->url,
            'original_name' => $this->original_name,
            'extension' => $this->extension,
            'mime' => $this->mime,
            'size' => $this->size,
        ];
    }
}