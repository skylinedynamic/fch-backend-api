<?php

namespace App\Services\FCH\Partner;

use App\Models\Partner\PartnerDocument;
use App\Traits\UploadsFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\StoredFileName;
use App\Support\Files\Disk;

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
                    $doc = $this->createDocument($partnerId, $file, $data);
                    $documents[] = $doc;
                }

                // Accurate Code: Store in attachments format — same as email attachments
                // so the frontend renders them as downloadable file chips automatically
                $attachmentFiles = collect($documents)->map(fn($d) => [
                    'name' => $d->document_name,
                    // Accurate Code: Full URL so the frontend can call the download API directly
                    'path' => url('api/v1/admin/partners/documents/download/' . $d->id),
                    'id'   => $d->id,
                ])->values()->toArray();

                $count = count($documents);
                $readableDesc = $count === 1 
                    ? "Document was uploaded to the partner profile." 
                    : "Documents were uploaded to the partner profile.";

                $partner = \App\Models\Partner\Partner::find($partnerId);
                app(\App\Services\FCH\Common\ActivityLog\ActivityLogService::class)->logModelEvent(
                    $partner,
                    \App\Models\ActivityLog\ActivityLog::LOG_TYPE_DOCUMENT_UPLOAD,
                    [
                        'attachments' => [
                            'count' => count($attachmentFiles),
                            'files' => $attachmentFiles,
                        ],
                    ],
                    'Partner Document Uploaded',
                    $readableDesc
                );

                return collect($documents);
            }
      
            // single string
            $doc = $this->createDocument($partnerId, $data['file_path'], $data);
            
            // Accurate Code: Store in attachments format — same as email attachments
            $attachmentFiles = [[
                'name' => $doc->document_name,
                // Accurate Code: Full URL so the frontend can call the download API directly
                'path' => url('api/v1/admin/partners/documents/download/' . $doc->id),
                'id'   => $doc->id,
            ]];

            $partner = \App\Models\Partner\Partner::find($partnerId);
            app(\App\Services\FCH\Common\ActivityLog\ActivityLogService::class)->logModelEvent(
                $partner,
                \App\Models\ActivityLog\ActivityLog::LOG_TYPE_DOCUMENT_UPLOAD,
                [
                    'attachments' => [
                        'count' => 1,
                        'files' => $attachmentFiles,
                    ],
                ],
                'Partner Document Uploaded',
                "Document {$doc->document_name} was uploaded to the partner profile."
            );

            return $doc;
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
    //             if ($document->file_path && Storage::disk('uploads')->exists($document->file_path)) {
    //                 $this->deleteFile($document->file_path, 'uploads');
    //             }

    //             $upload = $this->uploadFile($fileEntry, "partners/{$document->partner_id}/documents", 'uploads');
    //             $document->file_path = $upload['path'];
    //             $document->size = $this->formatSize($fileEntry->getSize());
    //             $document->document_name = basename($upload['path']);
    //         } elseif (is_string($fileEntry) && trim($fileEntry) !== '') {
    //             $original = ltrim($fileEntry, '/');
    //             $basename = basename($original);
    //             $destPath = "partners/{$document->partner_id}/documents/{$basename}";

    //             // Attempt to copy original into partner folder if exists
    //             if (Storage::disk('uploads')->exists($original)) {
    //                 try {
    //                     Storage::disk('uploads')->copy($original, $destPath);
    //                     $document->file_path = $destPath;
    //                     try {
    //                         $document->size = $this->formatSize(Storage::disk('uploads')->size($destPath));
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
        // Accurate Code: Use human-readable document name if provided, else use the raw basename
        $rawFilename = basename($file instanceof \Illuminate\Http\UploadedFile ? $file->getClientOriginalName() : $file);
        $filename    = !empty($data['document_name']) ? $data['document_name'] : $rawFilename;
        $size = null;
        $partnerPath = $this->normalizePath($partnerId, $file);
        
        if (Storage::disk(Disk::UPLOADS)->exists($partnerPath)) {
             try {
                $size = $this->formatSize(Storage::disk(Disk::UPLOADS)->size($partnerPath));
             } catch (\Exception $e) {
                // Ignore size error
             }
        } 
        elseif (Storage::disk(Disk::UPLOADS)->exists(ltrim($file, '/'))) {
             try {
                $size = $this->formatSize(Storage::disk(Disk::UPLOADS)->size(ltrim($file, '/')));
             } catch (\Exception $e) {
             }
        }

        $dbPath = str_replace("partners/{$partnerId}/", "", $partnerPath);

        // BUG-016: a filed document leaves partners/{id}/ (the partner's public
        // branding and pictures) for its own private folder.
        $uploads = Storage::disk(Disk::UPLOADS);
        if ($uploads->exists($partnerPath)) {
            $privatePath = PartnerDocument::FOLDER . "/{$partnerId}/" . StoredFileName::make($rawFilename);
            if ($uploads->move($partnerPath, $privatePath)) {
                $dbPath = $privatePath;
            }
        }

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
            $partnerId = $document->partner_id;
            $docName = $document->document_name;

            if ($document->getRawOriginal('file_path')) {
                $this->deleteFile($document->getRawOriginal('file_path'), Disk::UPLOADS);
            }
            
            $deleted = $document->delete();

            if ($deleted) {
                // Accurate Code: Log the delete action
                $partner = \App\Models\Partner\Partner::find($partnerId);
                app(\App\Services\FCH\Common\ActivityLog\ActivityLogService::class)->logModelEvent(
                    $partner,
                    \App\Models\ActivityLog\ActivityLog::LOG_TYPE_DOCUMENT_UPLOAD,
                    [],
                    'Partner Document Removed',
                    "Document {$docName} was removed from the partner profile."
                );
            }

            return $deleted;
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
