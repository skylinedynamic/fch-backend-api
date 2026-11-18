<?php

namespace Database\Seeders;

use App\Models\Resource\Resource;
use App\Support\Files\Disk;
use App\Support\Files\Folder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * A published Legal & Registration resource with a real PDF, for both portals'
 * resource preview E2E (R3-BUG-003: the preview of a private resource file was
 * blank). The local database's resources name files that are not on disk, so
 * no resource there has a file to preview.
 *
 * Re-running is safe: it updates the same resource and file. With
 * E2E_RESOURCE_PDF_REMOVE=1 it removes both instead. Local/testing only.
 *
 *   php artisan db:seed --class=E2EResourcePdfSeeder
 */
class E2EResourcePdfSeeder extends Seeder
{
    public const TITLE = 'E2E Resource PDF';
    public const FILE = Folder::RESOURCES.'/e2e/e2e-resource.pdf';

    /** A one-page PDF that reads "E2E resource preview". */
    private const PDF = "%PDF-1.4\n"
        ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
        ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
        ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n"
        ."4 0 obj<</Length 52>>stream\nBT /F1 18 Tf 24 64 Td (E2E resource preview) Tj ET\nendstream endobj\n"
        ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
        ."trailer<</Root 1 0 R>>\n%%EOF\n";

    public function run(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('E2EResourcePdfSeeder only runs in local/testing.');
        }

        $disk = Storage::disk(Disk::UPLOADS);

        if (env('E2E_RESOURCE_PDF_REMOVE')) {
            Resource::withTrashed()->where('title', self::TITLE)->forceDelete();
            $disk->delete(self::FILE);
            $this->command?->info('Removed the E2E resource PDF');

            return;
        }

        $disk->put(self::FILE, self::PDF);

        $resource = Resource::withTrashed()->updateOrCreate(
            ['title' => self::TITLE],
            [
                'resource_type' => Resource::TYPE_LEGAL_REGISTRATION,
                'subtitle' => 'Preview check',
                'status' => true,
                'is_partner' => true,
                'is_borrower' => true,
                'file' => self::FILE,
                'icon' => 'ri-file-pdf-line',
                'sort_order' => 9999,
            ]
        );
        $resource->restore();

        $this->command?->info("E2E resource PDF is resource {$resource->id}");
    }
}
