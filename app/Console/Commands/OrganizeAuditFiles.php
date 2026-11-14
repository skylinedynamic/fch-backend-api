<?php

namespace App\Console\Commands;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Services\Audit\FundingApplicationAuditTrail;
use App\Support\Files\Disk;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: the copies kept flat in the audit folder (files/{sha256}.pdf) move
 * under their application and the version signed,
 * applications/{id}/v{n}/{application|certificate}-{sha256}.pdf, and the
 * paths in each application's signature records follow.
 *
 * Which application, version and kind a copy is comes from the trail's
 * signed_copy_kept events and from the signature records. The events
 * themselves are never touched - their hashes cover file_path - so every
 * chain stays intact; the trail finds a moved copy by its hash under its
 * application (FundingApplicationAuditTrail::storedFile()).
 *
 * A copy is written only from a flat file whose bytes still match its hash,
 * and checked once written. A flat file goes only when every copy made of it
 * is in place; one that does not match, or that nothing names, is reported
 * and kept. Re-running picks up where it stopped. Run with --dry-run first,
 * after audit:move-signed-copies (which still writes flat), and before the
 * audit disk gets S3 Object Lock (a locked file cannot be deleted).
 */
class OrganizeAuditFiles extends Command
{
    protected $signature = 'audit:organize-files {--dry-run : Report what would move without moving it}';

    protected $description = 'Move the audit folder\'s flat signed copies under their application and version';

    /** Signature record key => the kind of copy it names. */
    private const RECORD_KEYS = [
        'signed_application_path' => FundingApplicationAuditEvent::FILE_APPLICATION_PDF,
        'certificate_path' => FundingApplicationAuditEvent::FILE_SIGNATURE_CERTIFICATE,
    ];
    private const RECORD_COLUMNS = ['signature_data', 'previous_signature_data'];
    private const VERSION_KEY = 'application_version';
    private const FILE_TYPE_KEY = 'file_type';

    private Filesystem $audit;
    private FundingApplicationAuditTrail $trail;
    private bool $dryRun;

    /** @var array<string, true> flat files copied so far */
    private array $placed = [];
    /** @var array<string, true> flat files with a copy that failed: kept */
    private array $failed = [];
    /** @var list<string> organized paths written (or, in a dry run, that would be) */
    private array $targets = [];

    public function handle(FundingApplicationAuditTrail $trail): int
    {
        $this->trail = $trail;
        $this->audit = Storage::disk(Disk::UPLOADS);
        $this->dryRun = (bool) $this->option('dry-run');

        if ($this->dryRun) {
            $this->warn(__('audit.organize.dry_run'));
        }

        $this->organizeEventCopies();
        $this->organizeRecords();
        $this->organizeImportedCopies();
        $removed = $this->removeFlatCopies();

        $this->info(__($this->dryRun ? 'audit.organize.done_dry_run' : 'audit.organize.done', [
            'copied' => count(array_unique($this->targets)),
            'removed' => $removed,
            'failed' => count($this->failed),
        ]));

        return self::SUCCESS;
    }

    /** Every copy a signed_copy_kept event names flat. */
    private function organizeEventCopies(): void
    {
        $events = FundingApplicationAuditEvent::where('event', FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT)
            ->where('file_path', 'like', FundingApplicationAuditTrail::FILES_FOLDER . '/%')
            ->orderBy('id')
            ->cursor();

        foreach ($events as $event) {
            $target = $this->trail->keptCopyPath(
                (int) $event->funding_application_id,
                $event->context[self::VERSION_KEY] ?? null,
                $event->context[self::FILE_TYPE_KEY] ?? null,
                (string) $event->file_hash,
                pathinfo((string) $event->file_path, PATHINFO_EXTENSION)
            );

            $this->place((string) $event->file_path, $target, (string) $event->file_hash);
        }
    }

    /** Every flat path in a signature record: copied under its application, and the record pointed there. */
    private function organizeRecords(): void
    {
        // Without a slash: the records are JSON, which may store "/" as "\/".
        // Each path is checked exactly below.
        $flat = '%' . FundingApplicationAuditTrail::FOLDER . '%';
        $rows = DB::table('funding_applications')
            ->where(fn ($q) => $q->where('signature_data', 'like', $flat)->orWhere('previous_signature_data', 'like', $flat))
            ->orderBy('id')
            ->get(['id', ...self::RECORD_COLUMNS]);

        foreach ($rows as $row) {
            $update = [];

            foreach (self::RECORD_COLUMNS as $column) {
                $record = json_decode((string) $row->{$column}, true);
                if (!is_array($record)) {
                    continue;
                }

                $changed = false;
                foreach (self::RECORD_KEYS as $key => $fileType) {
                    $path = $record[$key] ?? null;
                    if (!is_string($path) || !str_starts_with($path, FundingApplicationAuditTrail::FILES_FOLDER . '/')) {
                        continue;
                    }

                    $hash = pathinfo($path, PATHINFO_FILENAME);
                    $target = $this->trail->keptCopyPath((int) $row->id, $record[self::VERSION_KEY] ?? null, $fileType, $hash, pathinfo($path, PATHINFO_EXTENSION));

                    if ($this->place($path, $target, $hash)) {
                        $record[$key] = $target;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $update[$column] = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
            }

            // Written directly: moving a file is not a change to the application.
            if ($update !== [] && !$this->dryRun) {
                DB::table('funding_applications')->where('id', $row->id)->update($update);
            }
        }
    }

    /**
     * An imported event (the old download history) names its copy by hash
     * only. A flat copy with that hash goes under its application, with the
     * event's version - unless the application already has that copy (put
     * there from its signature record, whose version is the one signed).
     */
    private function organizeImportedCopies(): void
    {
        $events = FundingApplicationAuditEvent::where('event', FundingApplicationAuditEvent::EVENT_SIGNED_COPY_KEPT)
            ->whereNull('file_path')
            ->whereNotNull('file_hash')
            ->orderBy('id')
            ->cursor();

        foreach ($events as $event) {
            $applicationId = (int) $event->funding_application_id;
            $hash = (string) $event->file_hash;
            $flat = FundingApplicationAuditTrail::FILES_FOLDER . "/{$hash}.pdf";

            if (!$this->audit->exists($flat) || $this->applicationHas($applicationId, $hash)) {
                continue;
            }

            $target = $this->trail->keptCopyPath($applicationId, $event->context[self::VERSION_KEY] ?? null, $event->context[self::FILE_TYPE_KEY] ?? null, $hash);
            $this->place($flat, $target, $hash);
        }
    }

    /** Whether a copy with this hash is (or, in a dry run, would be) under the application already. */
    private function applicationHas(int $applicationId, string $hash): bool
    {
        $prefix = FundingApplicationAuditTrail::APPLICATIONS_FOLDER . "/{$applicationId}/";

        foreach ([...$this->targets, ...$this->audit->allFiles($prefix)] as $path) {
            if (str_starts_with($path, $prefix) && str_contains(basename($path), "-{$hash}.")) {
                return true;
            }
        }

        return false;
    }

    /**
     * A copy of a flat file at its organized path. True once the copy is
     * there and matches (or, in a dry run, would be). A flat file already
     * removed by an earlier run is fine when its copy is in place.
     */
    private function place(string $flat, string $target, string $hash): bool
    {
        if (!$this->audit->exists($flat)) {
            if ($this->audit->exists($target) && hash_equals($hash, hash('sha256', (string) $this->audit->get($target)))) {
                return true;
            }
            $this->error(__('audit.organize.missing', ['path' => $flat]));

            return false;
        }

        $contents = (string) $this->audit->get($flat);
        if (!hash_equals($hash, hash('sha256', $contents))) {
            $this->failed[$flat] = true;
            $this->error(__('audit.organize.mismatch', ['path' => $flat]));

            return false;
        }

        if (!$this->dryRun) {
            if ($this->audit->exists($target)) {
                if ($this->audit->get($target) !== $contents) {
                    $this->failed[$flat] = true;
                    $this->error(__('audit.organize.conflict', ['path' => $target]));

                    return false;
                }
            } elseif (!$this->audit->put($target, $contents) || $this->audit->get($target) !== $contents) {
                $this->failed[$flat] = true;
                $this->error(__('audit.copy_mismatch') . ' ' . $target);

                return false;
            }
        }

        $this->placed[$flat] = true;
        $this->targets[] = $target;
        $this->line(__($this->dryRun ? 'audit.organize.would_move' : 'audit.organize.moved', ['path' => $flat, 'to' => $target]));

        return true;
    }

    /** The flat files every copy of which is in place. Anything else is reported and kept. */
    private function removeFlatCopies(): int
    {
        $removed = 0;

        foreach ($this->audit->files(FundingApplicationAuditTrail::FILES_FOLDER) as $flat) {
            if (isset($this->failed[$flat])) {
                continue;
            }
            if (!isset($this->placed[$flat])) {
                $this->warn(__('audit.organize.left', ['path' => $flat]));
                continue;
            }
            if (!$this->dryRun) {
                $this->audit->delete($flat);
            }
            $removed++;
        }

        return $removed;
    }
}
