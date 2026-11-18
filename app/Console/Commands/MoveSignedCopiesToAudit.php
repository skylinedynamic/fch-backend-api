<?php

namespace App\Console\Commands;

use App\Services\Audit\FundingApplicationAuditTrail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

/**
 * One-off: the copies signed before the audit folder held them move from
 * funding-applications/{id}/signed/ to audit/files/{sha256}.pdf, and the
 * paths in each application's signature record (and the record kept from
 * before an edit) follow. Then funding-applications/ goes.
 *
 * A file is deleted only after its audit copy is written and matches it byte
 * for byte, and its record points there. A record whose file is missing is
 * reported and left alone; a file no record points to is reported and kept.
 * Re-running picks up where it stopped. Run with --dry-run first.
 *
 * It writes the copies flat; run audit:organize-files after it to move them
 * under their application and version.
 */
class MoveSignedCopiesToAudit extends Command
{
    protected $signature = 'audit:move-signed-copies {--dry-run : Report what would move without moving it}';

    protected $description = 'Move the signed application PDFs and certificates from funding-applications/ into the audit folder';

    private const OLD_ROOT = Folder::OLD_FUNDING_APPLICATIONS;
    private const PATH_KEYS = ['signed_application_path', 'certificate_path'];
    private const COLUMNS = ['signature_data', 'previous_signature_data'];

    public function handle(FundingApplicationAuditTrail $trail): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $source = Storage::disk(Disk::PUBLIC);
        $audit = Storage::disk(Disk::UPLOADS);
        $moved = $missing = 0;
        $done = [];

        if ($dryRun) {
            $this->warn(__('audit.move.dry_run'));
        }

        $rows = DB::table('funding_applications')
            ->where(fn ($q) => $q->where('signature_data', 'like', '%' . self::OLD_ROOT . '%')
                ->orWhere('previous_signature_data', 'like', '%' . self::OLD_ROOT . '%'))
            ->orderBy('id')
            ->get(['id', ...self::COLUMNS]);

        foreach ($rows as $row) {
            $update = [];

            foreach (self::COLUMNS as $column) {
                $record = json_decode((string) $row->{$column}, true);
                if (!is_array($record)) {
                    continue;
                }

                $changed = false;
                foreach (self::PATH_KEYS as $key) {
                    $path = $record[$key] ?? null;
                    if (!is_string($path) || !str_starts_with($path, self::OLD_ROOT . '/')) {
                        continue;
                    }

                    if (!$source->exists($path)) {
                        $missing++;
                        $this->error(__('audit.move.missing', ['id' => $row->id, 'path' => $path]));
                        continue;
                    }

                    if ($dryRun) {
                        $this->line(__('audit.move.would_move', ['path' => $path]));
                        $done[] = $path;
                        $moved++;
                        continue;
                    }

                    try {
                        $contents = $source->get($path);
                        $copy = $trail->keepContents($contents, pathinfo($path, PATHINFO_EXTENSION));
                        if ($audit->get($copy['path']) !== $contents) {
                            throw new RuntimeException(__('audit.copy_mismatch'));
                        }
                    } catch (Throwable $e) {
                        $this->error(__('audit.move.failed', ['path' => $path, 'error' => $e->getMessage()]));
                        continue;
                    }

                    $record[$key] = $copy['path'];
                    $done[] = $path;
                    $changed = true;
                    $moved++;
                    $this->line(__('audit.move.moved', ['path' => $path, 'to' => $copy['path']]));
                }

                if ($changed) {
                    $update[$column] = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            // The record points at the audit copy before the original goes. Written
            // directly: moving a file is not a change to the application.
            if ($update !== []) {
                DB::table('funding_applications')->where('id', $row->id)->update($update);
            }
        }

        // A signed copy no record points to any more (edited after signing) is
        // still the evidence of that signing: into the audit folder too.
        foreach ($source->exists(self::OLD_ROOT) ? $source->allFiles(self::OLD_ROOT) : [] as $left) {
            if (in_array($left, $done, true)) {
                continue;
            }
            if (!str_contains($left, '/' . Folder::SIGNED . '/')) {
                $this->warn(__('audit.move.left', ['path' => $left]));
                continue;
            }
            if ($dryRun) {
                $this->line(__('audit.move.would_move', ['path' => $left]));
                $moved++;
                continue;
            }
            try {
                $contents = $source->get($left);
                $copy = $trail->keepContents($contents, pathinfo($left, PATHINFO_EXTENSION));
                if ($audit->get($copy['path']) !== $contents) {
                    throw new RuntimeException(__('audit.copy_mismatch'));
                }
                $done[] = $left;
                $moved++;
                $this->line(__('audit.move.moved', ['path' => $left, 'to' => $copy['path']]));
            } catch (Throwable $e) {
                $this->error(__('audit.move.failed', ['path' => $left, 'error' => $e->getMessage()]));
            }
        }

        if (!$dryRun) {
            foreach (array_unique($done) as $path) {
                $source->delete($path);
            }
            $this->removeEmptyFolders($source);
        }

        $this->info(__('audit.move.done', ['moved' => $moved, 'missing' => $missing]));

        return self::SUCCESS;
    }

    /** Remove funding-applications/ and the folders under it that hold no file any more. */
    private function removeEmptyFolders(Filesystem $disk): void
    {
        if (!$disk->exists(self::OLD_ROOT)) {
            return;
        }

        $folders = $disk->allDirectories(self::OLD_ROOT);
        usort($folders, fn ($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ([...$folders, self::OLD_ROOT] as $folder) {
            if ($disk->allFiles($folder) === []) {
                $disk->deleteDirectory($folder);
            }
        }
    }
}
