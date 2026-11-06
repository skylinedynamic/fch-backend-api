<?php

namespace App\Http\Resources\FCH\EmbedForm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;

/** @mixin \App\Models\EmbedForm\EmbedFormAttachment */
class EmbedFormAttachmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'submission_id' => $this->submission_id,
            'partner_id' => $this->partner_id,
            'field_name' => $this->field_name,
            // The document type the applicant filed this under. Pre-rewrite rows
            // have no type and fall back to field_name for their label.
            'borrower_doc_type_id' => $this->borrower_doc_type_id,
            'document_type' => $this->docType?->name ?? $this->field_name,
            'original_name' => $this->original_name,
            'file_path' => $this->file_path,
            'file_url' => $this->file_path ? Storage::disk(Disk::UPLOADS)->url($this->file_path) : null,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'download_url' => url("api/v1/common/embed-forms/attachments/{$this->id}/download"),
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }
}
