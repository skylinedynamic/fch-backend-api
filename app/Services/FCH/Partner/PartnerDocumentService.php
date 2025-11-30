<?php

namespace App\Services\FCH\Partner;

use App\Models\Partner\PartnerDocument;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PartnerDocumentService
{
    use UploadsFiles;

    /**
     * Get all documents for a specific partner.
     */
    public function getDocumentsByPartnerId(int $partnerId)
    {
        return PartnerDocument::where('partner_id', $partnerId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Store a new partner document.
     */
    public function store(array $data): PartnerDocument
    {
        try {
            $file = $data['file_path'];
            $partnerId = $data['partner_id'];
            $documentName = $data['document_name'];

            // Upload file
            $upload = $this->uploadFile($file, "partners/{$partnerId}/documents", 'public');

            // Create record
            $document = PartnerDocument::create([
                'partner_id' => $partnerId,
                'document_name' => $documentName,
                'file_path' => $upload['path'],
                'size' => $this->formatSize($file->getSize()),
            ]);

            Log::info('📄 Partner document uploaded', ['id' => $document->id, 'partner_id' => $partnerId]);

            return $document;
        } catch (\Exception $e) {
            Log::error('❌ Partner document upload failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Update an existing partner document.
     */
    public function update(PartnerDocument $document, array $data): PartnerDocument
    {
        try {
            // Update document name if provided
            if (isset($data['document_name'])) {
                $document->document_name = $data['document_name'];
            }

            // Handle new file upload
            if (isset($data['file_path']) && $data['file_path'] instanceof UploadedFile) {
                // Delete old file
                if ($document->file_path) {
                    $this->deleteFile($document->file_path, 'public');
                }

                // Upload new file
                $file = $data['file_path'];
                $upload = $this->uploadFile($file, "partners/{$document->partner_id}/documents", 'public');

                $document->file_path = $upload['path'];
                $document->size = $this->formatSize($file->getSize());
            }

            $document->save();

            Log::info('📄 Partner document updated', ['id' => $document->id]);

            return $document;
        } catch (\Exception $e) {
            Log::error('❌ Partner document update failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Delete a partner document.
     */
    public function delete(PartnerDocument $document): bool
    {
        try {
            if ($document->file_path) {
                $this->deleteFile($document->file_path, 'public');
            }
            return $document->delete();
        } catch (\Exception $e) {
            Log::error('❌ Partner document deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Format file size.
     */
    private function formatSize($bytes)
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return $bytes . ' byte';
        } else {
            return '0 bytes';
        }
    }
}
