<?php

namespace App\Http\Resources\Global\Document;

use App\Models\Borrower\BorrowerDocType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BorrowerDocTypeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            // One of the four categories a client must upload (REQUIRED_NAMES).
            'is_required' => BorrowerDocType::isRequiredName((string) $this->name),
        ];
    }
}
