<?php

namespace App\Console\Commands;

use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormSubmission;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * One-off tidy-up for embedded form submissions converted before the
 * conversion moved its documents (see EmbedFormService::copyDocuments).
 *
 * Those conversions copied each upload to funding_applications/{borrower}/{id}/
 * and left the original in embed_forms/{partner}/{submission}/, so every file
 * was on disk twice, the copy in a folder nothing else uses. This:
 *
 *   1. moves each such copy to funding-application/{borrower}/FA-{id}/ - the
 *      folder the borrower's and partner's own uploads use;
 *   2. points the submission's attachment at the moved file and deletes the
 *      embed form original - only when its application copy exists, so an
 *      upload since deleted from the application keeps its one file;
 *   3. removes the folders left empty.
 *
 * A file is deleted only after its replacement is in place and recorded, and a
 * re-run picks up where a previous one stopped. Run with --dry-run first.
 */
class RelocateConvertedEmbedFormDocuments extends Command
{
    protected $signature = 'embed-form:relocate-converted-documents {--dry-run : Report what would change without changing anything}';

    protected $description = 'Move documents of converted embedded form submissions into funding-application/{borrower}/FA-{id}/ and delete the embedded form duplicates';

    private const OLD_ROOT = 'funding_applications';

    private const EMBED_ROOT = 'embed_forms';

    private bool $dryRun = false;

    /** @var array<string, int> */
    private array $counts = [
        'moved' => 0,
        'already_moved' => 0,
        'missing' => 0,
        'duplicates_deleted' => 0,
        'kept_only_copy' => 0,
    ];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('public');

        if ($this->dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        $this->moveApplicationCopies($disk);
        $this->deleteEmbedDuplicates($disk);

        if (!$this->dryRun && $disk->exists(self::OLD_ROOT) && $disk->allFiles(self::OLD_ROOT) === []) {
            $disk->deleteDirectory(self::OLD_ROOT);
        }

        $this->table(['', 'files'], [
            [$this->dryRun ? 'Would move into funding-application/' : 'Moved into funding-application/', $this->counts['moved']],
            ['Already moved by an earlier run', $this->counts['already_moved']],
            ['Missing on disk (left as is)', $this->counts['missing']],
            [$this->dryRun ? 'Embedded duplicates to delete' : 'Embedded duplicates deleted', $this->counts['duplicates_deleted']],
            ['Embedded uploads kept (no application copy)', $this->counts['kept_only_copy']],
        ]);

        return self::SUCCESS;
    }

    /** Step 1: funding_applications/{borrower}/{id}/x -> funding-application/{borrower}/FA-{id}/x */
    private function moveApplicationCopies(Filesystem $disk): void
    {
        FundingApplicationDocument::query()
            ->where('path', 'like', self::OLD_ROOT . '/%')
            ->where(fn ($q) => $q->where('disk', 'public')->orWhereNull('disk'))
            ->orderBy('id')
            ->each(function (FundingApplicationDocument $document) use ($disk) {
                $old = $document->path;
                $new = "funding-application/{$document->borrower_id}/FA-{$document->funding_application_id}/" . basename($old);

                if (!$disk->exists($old)) {
                    if ($disk->exists($new)) {
                        // An earlier run copied it but stopped before recording it.
                        $this->counts['already_moved']++;
                        $this->dryRun || $document->update(['path' => $new]);
                    } else {
                        $this->counts['missing']++;
                        $this->line("  missing: document #{$document->id} {$old}");
                    }

                    return;
                }

                $this->counts['moved']++;
                if ($this->dryRun) {
                    return;
                }

                if (!$disk->exists($new) && !$disk->copy($old, $new)) {
                    $this->error("  could not copy document #{$document->id} {$old}");
                    $this->counts['moved']--;

                    return;
                }

                $document->update(['path' => $new]);
                $disk->delete($old);
            });
    }

    /** Step 2: the embed form original of each moved upload is a duplicate. */
    private function deleteEmbedDuplicates(Filesystem $disk): void
    {
        EmbedFormSubmission::query()
            ->whereNotNull('converted_funding_application_id')
            ->orderBy('id')
            ->each(function (EmbedFormSubmission $submission) use ($disk) {
                $attachments = EmbedFormAttachment::where('submission_id', $submission->id)->orderBy('id')->get();
                $pending = $attachments->filter(fn ($a) => str_starts_with((string) $a->file_path, self::EMBED_ROOT . '/'));

                if ($pending->isEmpty()) {
                    return;
                }

                $documents = FundingApplicationDocument::where('funding_application_id', $submission->converted_funding_application_id)
                    ->orderBy('id')
                    ->get();

                // A document an attachment already points at (an earlier run) is taken.
                $claimed = $attachments->pluck('file_path')->flip();

                foreach ($pending as $attachment) {
                    $match = $documents->first(fn ($d) => !isset($claimed[$d->path])
                        && $d->original_name === $attachment->original_name
                        && ($d->size === null || $attachment->file_size === null || (int) $d->size === (int) $attachment->file_size)
                        && $disk->exists($d->path));

                    if (!$match) {
                        $this->counts['kept_only_copy']++;
                        continue;
                    }

                    $claimed[$match->path] = true;
                    $this->counts['duplicates_deleted']++;

                    if ($this->dryRun) {
                        continue;
                    }

                    $original = $attachment->file_path;
                    $attachment->update(['file_path' => $match->path]);
                    $disk->delete($original);
                }

                $folder = self::EMBED_ROOT . "/{$submission->partner_id}/{$submission->id}";
                if (!$this->dryRun && $disk->exists($folder) && $disk->allFiles($folder) === []) {
                    $disk->deleteDirectory($folder);
                }
            });
    }
}
