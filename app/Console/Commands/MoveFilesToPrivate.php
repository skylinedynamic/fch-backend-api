<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Support\Files\Disk;

/**
 * BUG-016: copy a folder from the public disk to its private disk
 * (config/files.php private_folders). Paths stay the same, so the records that
 * point at the files need no change.
 *
 * Nothing is lost: a file counts as moved only once its private copy matches
 * the original byte for byte; a different file already at the private path is
 * never overwritten; the public original is deleted only with --delete-source
 * (run it once the move has been checked). Re-running changes nothing.
 */
class MoveFilesToPrivate extends Command
{
    protected $signature = 'files:move-to-private
        {folder : A folder listed in config/files.php private_folders}
        {--dry-run : Report what would happen without copying or deleting}
        {--delete-source : Delete each public original once its private copy matches}';

    protected $description = 'Copy a folder from the public disk to its private disk (BUG-016)';

    public function handle(): int
    {
        $folder = trim((string) $this->argument('folder'), '/');
        $folders = config('files.private_folders', []);

        if (!array_key_exists($folder, $folders)) {
            $this->error(__('files.move.unknown_folder', ['folder' => $folder, 'folders' => implode(', ', array_keys($folders))]));

            return self::FAILURE;
        }

        $source = Storage::disk(Disk::PUBLIC);
        $target = Storage::disk($folders[$folder]);
        if ($source->path('') === $target->path('')) {
            $this->warn(__('files.move.same_disk', ['folder' => $folder, 'disk' => $folders[$folder]]));

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');
        if ($dryRun) {
            $this->info(__('files.move.dry_run'));
        }

        $count = ['copied' => 0, 'present' => 0, 'deleted' => 0, 'failed' => 0];

        foreach ($source->allFiles($folder) as $path) {
            try {
                $contents = $source->get($path);

                if ($target->exists($path)) {
                    if ($target->get($path) !== $contents) {
                        $this->error(__('files.move.conflict', ['path' => $path]));
                        $count['failed']++;
                        continue;
                    }
                    $this->line(__('files.move.already_there', ['path' => $path]));
                    $count['present']++;
                } elseif ($dryRun) {
                    $this->line(__('files.move.would_copy', ['path' => $path]));
                    $count['copied']++;
                } else {
                    $target->put($path, $contents);
                    if (!$target->exists($path) || $target->get($path) !== $contents) {
                        $this->error(__('files.move.mismatch', ['path' => $path]));
                        $count['failed']++;
                        continue;
                    }
                    $this->line(__('files.move.copied', ['path' => $path]));
                    $count['copied']++;
                }

                if ($deleteSource) {
                    if ($dryRun) {
                        $this->line(__('files.move.would_delete', ['path' => $path]));
                    } else {
                        $source->delete($path);
                        $this->line(__('files.move.deleted', ['path' => $path]));
                    }
                    $count['deleted']++;
                }
            } catch (Throwable $e) {
                $this->error(__('files.move.failed', ['path' => $path, 'error' => $e->getMessage()]));
                $count['failed']++;
            }
        }

        if ($deleteSource && !$dryRun && $source->exists($folder) && $source->allFiles($folder) === []) {
            $source->deleteDirectory($folder);
        }

        $this->info(__('files.move.done', ['folder' => $folder] + $count));

        return $count['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
