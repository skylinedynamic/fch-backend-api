<?php

namespace App\Console\Commands;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Borrower\FundingApplication;
use App\Models\Borrower\FundingApplicationCoBorrower;
use App\Models\Borrower\FundingApplicationDocument;
use App\Models\EmbedForm\EmbedFormAttachment;
use App\Models\EmbedForm\EmbedFormCoBorrower;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Support\Files\Disk;

/**
 * One-off tidy-up for embedded form submissions converted before the
 * conversion did what it does now (see EmbedFormService::convertToBorrower).
 *
 * Those conversions copied each upload to funding_applications/{borrower}/{id}/
 * and left the original in embed_forms/{partner}/{submission}/, so every file
 * was on disk twice, the copy in a folder nothing else uses. They also left the
 * client's Documents tab and most of the borrower profile empty, and stored
 * phones as +15122345678. This:
 *
 *   1. moves each such copy to funding-application/{borrower}/FA-{id}/ - the
 *      folder the borrower's and partner's own uploads use;
 *   2. points the submission's attachment at the moved file and deletes the
 *      embed form original - only when its application copy exists, so an
 *      upload since deleted from the application keeps its one file;
 *      and removes the folders left empty;
 *   3. copies each converted upload into the client's documents,
 *      borrowers/{borrower}/, with its borrower_documents row;
 *   4. fills the borrower profile's blanks from the submission - never
 *      overwriting what is there;
 *   5. rewrites the embedded form's phones as (512) 234-5678 and a converted
 *      borrower's country code US as +1.
 *
 * A file is deleted only after its replacement is in place and recorded, and a
 * re-run picks up where a previous one stopped and adds nothing twice. Run with
 * --dry-run first.
 */
class RelocateConvertedEmbedFormDocuments extends Command
{
    protected $signature = 'embed-form:relocate-converted-documents {--dry-run : Report what would change without changing anything}';

    protected $description = 'Bring embedded form submissions converted earlier up to date: documents into funding-application/{borrower}/FA-{id}/ and the client\'s documents, embedded duplicates deleted, profile blanks filled, phones reformatted';

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
        'client_documents' => 0,
        'client_documents_already' => 0,
        'profiles' => 0,
        'phones' => 0,
        'country_codes' => 0,
    ];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk(Disk::UPLOADS);

        if ($this->dryRun) {
            $this->warn('Dry run: nothing will be changed.');
        }

        $this->moveApplicationCopies($disk);
        $this->deleteEmbedDuplicates($disk);

        if (!$this->dryRun && $disk->exists(self::OLD_ROOT) && $disk->allFiles(self::OLD_ROOT) === []) {
            $disk->deleteDirectory(self::OLD_ROOT);
        }

        $this->addToClientDocuments($disk);
        $this->completeProfiles();
        $this->formatPhones();

        $would = fn (string $done, string $dry) => $this->dryRun ? $dry : $done;
        $this->table(['', 'count'], [
            [$would('Moved into funding-application/', 'Would move into funding-application/'), $this->counts['moved']],
            ['Already moved by an earlier run', $this->counts['already_moved']],
            ['Missing on disk (left as is)', $this->counts['missing']],
            [$would('Embedded duplicates deleted', 'Embedded duplicates to delete'), $this->counts['duplicates_deleted']],
            ['Embedded uploads kept (no application copy)', $this->counts['kept_only_copy']],
            [$would('Added to the clients\' documents', 'Would add to the clients\' documents'), $this->counts['client_documents']],
            ['Already in the clients\' documents', $this->counts['client_documents_already']],
            [$would('Borrower profiles filled in', 'Borrower profiles to fill in'), $this->counts['profiles']],
            [$would('Phone numbers reformatted', 'Phone numbers to reformat'), $this->counts['phones']],
            [$would('Borrower country codes US -> +1', 'Borrower country codes to change US -> +1'), $this->counts['country_codes']],
        ]);

        return self::SUCCESS;
    }

    /**
     * Step 3: each converted upload also in the client's own documents
     * (Clients > Details > Documents), as conversions now do.
     *
     * Only the application documents that came from the form - matched to the
     * submission's uploads as step 2 matches them - not ones uploaded to the
     * application since. A client document already there, including one
     * deleted since, is not added again.
     */
    private function addToClientDocuments(Filesystem $disk): void
    {
        $service = app(EmbedFormService::class);

        $this->convertedSubmissions()->each(function (EmbedFormSubmission $submission) use ($disk, $service) {
            $documents = FundingApplicationDocument::where('funding_application_id', $submission->converted_funding_application_id)
                ->orderBy('id')
                ->get();
            if ($documents->isEmpty()) {
                return;
            }

            $attachments = EmbedFormAttachment::where('submission_id', $submission->id)->orderBy('id')->get();
            $existing = BorrowerDocument::withTrashed()
                ->where('borrower_id', $submission->converted_borrower_id)
                ->where('application_id', $submission->converted_funding_application_id)
                ->pluck('file_name')
                ->countBy()
                ->all();
            $claimed = [];

            foreach ($attachments as $attachment) {
                $document = $documents->first(fn ($d) => !isset($claimed[$d->id]) && $d->path === $attachment->file_path)
                    ?? $documents->first(fn ($d) => !isset($claimed[$d->id])
                        && $d->original_name === $attachment->original_name
                        && ($d->size === null || $attachment->file_size === null || (int) $d->size === (int) $attachment->file_size));

                if (!$document || !$disk->exists($document->path)) {
                    continue;
                }
                $claimed[$document->id] = true;

                if (($existing[$document->original_name] ?? 0) > 0) {
                    $existing[$document->original_name]--;
                    $this->counts['client_documents_already']++;
                    continue;
                }

                $this->counts['client_documents']++;
                if ($this->dryRun) {
                    continue;
                }

                try {
                    $service->addToClientDocuments(
                        $document->path,
                        $document->original_name,
                        $attachment->borrower_doc_type_id ?? $document->borrower_doc_type_id,
                        (int) $submission->converted_funding_application_id,
                        (int) $submission->converted_borrower_id
                    );
                } catch (\Throwable $e) {
                    $this->counts['client_documents']--;
                    $this->error("  could not add document #{$document->id} to the client's documents: {$e->getMessage()}");
                }
            }
        });
    }

    /** Step 4: the borrower profile's blanks, filled from the submission's answers. */
    private function completeProfiles(): void
    {
        $service = app(EmbedFormService::class);

        EmbedFormSubmission::query()
            ->whereNotNull('converted_borrower_id')
            ->orderBy('id')
            ->each(function (EmbedFormSubmission $submission) use ($service) {
                if ($service->completeConvertedBorrowerProfile($submission, $this->dryRun) !== []) {
                    $this->counts['profiles']++;
                }
            });
    }

    /**
     * Step 5: phones in the form the portals store, (512) 234-5678, instead of
     * the public form's +15122345678; and a converted borrower's country code
     * +1 rather than US, as SMS dials country code + phone. Only the rows the
     * embedded form wrote: submissions, their co-borrowers, and the borrowers,
     * applications and co-borrowers conversions created.
     */
    private function formatPhones(): void
    {
        $borrowerIds = EmbedFormSubmission::whereNotNull('converted_borrower_id')->pluck('converted_borrower_id');
        $applicationIds = EmbedFormSubmission::whereNotNull('converted_funding_application_id')->pluck('converted_funding_application_id');

        $this->formatPhoneColumns(EmbedFormSubmission::query(), ['phone', 'business_phone_no']);
        $this->formatPhoneColumns(EmbedFormCoBorrower::query(), ['mobile_phone', 'home_phone']);
        $this->formatPhoneColumns(Borrower::whereIn('id', $borrowerIds), ['phone_number', 'business_phone']);
        $this->formatPhoneColumns(FundingApplication::whereIn('id', $applicationIds), ['phone_number', 'business_phone_no']);
        $this->formatPhoneColumns(FundingApplicationCoBorrower::whereIn('funding_application_id', $applicationIds), ['mobile_phone', 'home_phone']);

        $usCode = Borrower::whereIn('id', $borrowerIds)->where('country_code', 'US');
        $this->counts['country_codes'] = (clone $usCode)->count();
        if (!$this->dryRun) {
            $usCode->toBase()->update(['country_code' => '+1']);
        }
    }

    /**
     * A plain update, not a model save: the number is the same, only written
     * the way the portals write it, so it is not an edit worth an activity entry.
     */
    private function formatPhoneColumns(Builder $query, array $columns): void
    {
        $table = $query->getModel()->getTable();

        $query->toBase()->select(array_merge(['id'], $columns))->orderBy('id')->each(function ($row) use ($columns, $table) {
            $changes = [];
            foreach ($columns as $column) {
                $value = $row->{$column};
                if ($value === null || trim($value) === '') {
                    continue;
                }

                $formatted = formatPhoneDisplay(trim($value));
                if ($formatted !== $value) {
                    $changes[$column] = $formatted;
                }
            }

            if ($changes === []) {
                return;
            }

            $this->counts['phones'] += count($changes);
            if (!$this->dryRun) {
                DB::table($table)->where('id', $row->id)->update($changes);
            }
        });
    }

    private function convertedSubmissions(): Builder
    {
        return EmbedFormSubmission::query()
            ->whereNotNull('converted_borrower_id')
            ->whereNotNull('converted_funding_application_id')
            ->orderBy('id');
    }

    /** Step 1: funding_applications/{borrower}/{id}/x -> funding-application/{borrower}/FA-{id}/x */
    private function moveApplicationCopies(Filesystem $disk): void
    {
        FundingApplicationDocument::query()
            ->where('path', 'like', self::OLD_ROOT . '/%')
            ->where(fn ($q) => $q->whereIn('disk', [Disk::PUBLIC, Disk::UPLOADS])->orWhereNull('disk'))
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
