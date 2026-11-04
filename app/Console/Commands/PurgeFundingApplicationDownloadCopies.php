<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

/**
 * Delete the download copies the old audit history kept: one per download,
 * at a guessable public path, each overwritten by the next download -
 * funding-applications/{id}/downloads/ and the mistaken
 * storage/funding-applications/. Nothing else: the copies kept as signed
 * (funding-applications/{id}/signed/) are never touched. Run with --dry-run
 * first.
 */
class PurgeFundingApplicationDownloadCopies extends Command
{
    protected $signature = 'funding-applications:purge-download-copies {--dry-run : List what would be deleted without deleting it}';

    protected $description = 'Delete the per-download copies of funding application PDFs/ZIPs (never the signed copies)';

    public function handle(): int
    {
        $disk = Storage::disk(Disk::PUBLIC);
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn(__('audit.purge.dry_run'));
        }

        $oldStorage = Folder::OLD_STORAGE . '/' . Folder::OLD_FUNDING_APPLICATIONS;
        $folders = array_merge(
            array_values(array_filter(
                array_map(fn ($dir) => $dir . '/' . Folder::DOWNLOADS, $disk->directories(Folder::OLD_FUNDING_APPLICATIONS)),
                fn ($dir) => $disk->exists($dir)
            )),
            $disk->exists($oldStorage) ? [$oldStorage] : []
        );

        $count = 0;
        foreach ($folders as $folder) {
            foreach ($disk->allFiles($folder) as $file) {
                // Belt and braces: a signed copy is never a download copy.
                if (str_contains($file, '/' . Folder::SIGNED . '/')) {
                    continue;
                }
                $count++;
                $this->line(__($dryRun ? 'audit.purge.would_delete' : 'audit.purge.deleted', ['path' => $file]));
                if (!$dryRun) {
                    $disk->delete($file);
                }
            }

            if (!$dryRun && $disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }

        if (!$dryRun && $disk->exists(Folder::OLD_STORAGE) && $disk->allFiles(Folder::OLD_STORAGE) === []) {
            $disk->deleteDirectory(Folder::OLD_STORAGE);
        }

        $this->info(__($dryRun ? 'audit.purge.would_be_done' : 'audit.purge.done', ['count' => $count]));

        return self::SUCCESS;
    }
}
