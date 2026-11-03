<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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
        $disk = Storage::disk('public');
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn(__('audit.purge.dry_run'));
        }

        $folders = array_merge(
            array_values(array_filter(
                array_map(fn ($dir) => "{$dir}/downloads", $disk->directories('funding-applications')),
                fn ($dir) => $disk->exists($dir)
            )),
            $disk->exists('storage/funding-applications') ? ['storage/funding-applications'] : []
        );

        $count = 0;
        foreach ($folders as $folder) {
            foreach ($disk->allFiles($folder) as $file) {
                // Belt and braces: a signed copy is never a download copy.
                if (str_contains($file, '/signed/')) {
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

        if (!$dryRun && $disk->exists('storage') && $disk->allFiles('storage') === []) {
            $disk->deleteDirectory('storage');
        }

        $this->info(__('audit.purge.done', ['count' => $count, 'verb' => $dryRun ? 'would be deleted' : 'deleted']));

        return self::SUCCESS;
    }
}
