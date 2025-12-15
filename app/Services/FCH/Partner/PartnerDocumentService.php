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
     * Store Partner Documents
     */
    public function store(array $data)
    {
        $partnerId = $data['partner_id'];
        $documents = [];
       
       
        // Handle 'file_path' as array or string
        if (!empty($data['file_path'])) {
     
            if (is_array($data['file_path'])) {
                    
                foreach ($data['file_path'] as $file) {
                   
                    $documents[] = $this->createDocument($partnerId, $file, $data);
                }
                return collect($documents);
            }
      
            // single string
            return $this->createDocument($partnerId, $data['file_path'], $data);
        }

        return null;
    }

    // /**
    //  * Update Partner Document
    //  */
    // public function update(PartnerDocument $document, array $data): PartnerDocument
    // {
    //     // Handle uploaded file or provided path
    //     if (!empty($data['file_path'])) {
    //         $fileEntry = $data['file_path'];

    //         // Uploaded file
    //         if ($fileEntry instanceof UploadedFile) {
    //             // delete old partner-stored file if it exists
    //             if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
    //                 $this->deleteFile($document->file_path, 'public');
    //             }

    //             $upload = $this->uploadFile($fileEntry, "partners/{$document->partner_id}/documents", 'public');
    //             $document->file_path = $upload['path'];
    //             $document->size = $this->formatSize($fileEntry->getSize());
    //             $document->document_name = basename($upload['path']);
    //         } elseif (is_string($fileEntry) && trim($fileEntry) !== '') {
    //             $original = ltrim($fileEntry, '/');
    //             $basename = basename($original);
    //             $destPath = "partners/{$document->partner_id}/documents/{$basename}";

    //             // Attempt to copy original into partner folder if exists
    //             if (Storage::disk('public')->exists($original)) {
    //                 try {
    //                     Storage::disk('public')->copy($original, $destPath);
    //                     $document->file_path = $destPath;
    //                     try {
    //                         $document->size = $this->formatSize(Storage::disk('public')->size($destPath));
    //                     } catch (\Exception $e) {
    //                         Log::warning('Unable to determine size for copied file', ['path' => $destPath]);
    //                     }
    //                 } catch (\Exception $e) {
    //                     Log::warning('Failed to copy provided document into partner folder', ['from' => $original, 'to' => $destPath, 'error' => $e->getMessage()]);
    //                     // fallback: store normalized dest path even if copy failed
    //                     $document->file_path = $destPath;
    //                 }
    //             } else {
    //                 // original doesn't exist; still set normalized partner path
    //                 $document->file_path = $destPath;
    //             }

    //             $document->document_name = $document->document_name ?: $basename;
    //         }
    //     }

    //     if (isset($data['document_type'])) {
    //         $document->document_type = $data['document_type'];
    //     }

    //     if (isset($data['share_link'])) {
    //         $document->share_link = $data['share_link'];
    //     }

    //     $document->save();

    //     Log::info('Partner document updated', ['id' => $document->id]);

    //     return $document;
    // }

    /**
     * Create partner document
     */
    private function createDocument(int $partnerId, $file, array $data): PartnerDocument
    {
        $filename = basename($file);
        $size = null;
        $partnerPath = $this->normalizePath($partnerId, $file);
        
        if (Storage::disk('public')->exists($partnerPath)) {
             try {
                $size = $this->formatSize(Storage::disk('public')->size($partnerPath));
             } catch (\Exception $e) {
                // Ignore size error
             }
        } 
        elseif (Storage::disk('public')->exists(ltrim($file, '/'))) {
             try {
                $size = $this->formatSize(Storage::disk('public')->size(ltrim($file, '/')));
             } catch (\Exception $e) {
             }
        }

        $dbPath = str_replace("partners/{$partnerId}/", "", $partnerPath);

        $createData = [
            'partner_id'    => $partnerId,
            'document_name' => $filename,
            'share_link'    => $data['share_link'] ?? null,
            'file_path'     => $dbPath,
            'size'          => $size,
            'document_type' => $data['document_type'] ?? null,
        ];

        return PartnerDocument::create($createData);
    }

    /**
     * Delete a partner document.
     */
    public function delete(PartnerDocument $document): bool
    {
        try {
            if ($document->getRawOriginal('file_path')) {
                $this->deleteFile($document->getRawOriginal('file_path'), 'public');
            }
            return $document->delete();
        } catch (\Exception $e) {
            Log::error('❌ Partner document deletion failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Normalize file path
     */
    private function normalizePath(int $partnerId, $file): string
    {
        if ($file instanceof UploadedFile) {
            return "partners/{$partnerId}/documents/" . $file->getClientOriginalName();
        }

        return "partners/{$partnerId}/" . ltrim($file, '/');
    }

    /**
     * Format file size.
     */
    private function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }
}
