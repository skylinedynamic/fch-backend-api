<?php

namespace App\Console\Commands;

use App\Models\Borrower\BorrowerCreditProfile;
use App\Models\Borrower\BorrowerDocument;
use App\Models\Partner\PartnerDocument;
use App\Models\Resource\Resource;
use App\Models\Resource\ResourceProvider;
use App\Support\Files\StoragePath;
use App\Support\Files\StoredFileName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

/**
 * BUG-016: move files that get a folder of their own, so their records change
 * path (files:move-to-private handles folders whose paths stay the same).
 *
 *  partner-documents  partners/{id}/(documents/){name} -> partner-documents/{id}/{name}_{random}.{ext}
 *  credit-reports     borrowers/{id}/credit_report_*.pdf -> credit-reports/{id}/{uuid}.pdf,
 *                     with the credit profile's pdf_url
 *  resource-providers resources/{logo} -> resource-providers/{logo}_{random} (public): the
 *                     logos stay public once resources/ is private. A logo that is
 *                     also a resource's own file is copied, never deleted.
 *
 * A record moves only once the new copy matches byte for byte; a record whose
 * file is missing is reported and left alone; the old file is deleted only with
 * --delete-source; re-running changes nothing.
 */
class RelocateDocuments extends Command
{
    private const KINDS = [Folder::PARTNER_DOCUMENTS, Folder::CREDIT_REPORTS, Folder::RESOURCE_PROVIDERS];

    protected $signature = 'files:relocate
        {kind : partner-documents, credit-reports or resource-providers}
        {--dry-run : Report what would happen without changing anything}
        {--delete-source : Delete each old file once its new copy matches}';

    protected $description = 'Move partner documents and credit reports to folders of their own (BUG-016)';

    private bool $dryRun = false;
    private bool $deleteSource = false;
    private array $count = ['moved' => 0, 'missing' => 0, 'failed' => 0];

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        if (!in_array($kind, self::KINDS, true)) {
            $this->error(__('files.relocate.unknown_kind', ['kind' => $kind, 'kinds' => implode(', ', self::KINDS)]));

            return self::FAILURE;
        }

        $this->dryRun = (bool) $this->option('dry-run');
        $this->deleteSource = (bool) $this->option('delete-source');
        if ($this->dryRun) {
            $this->info(__('files.move.dry_run'));
        }

        match ($kind) {
            Folder::PARTNER_DOCUMENTS => $this->partnerDocuments(),
            Folder::CREDIT_REPORTS => $this->creditReports(),
            Folder::RESOURCE_PROVIDERS => $this->resourceProviderLogos(),
        };

        $this->info(__('files.relocate.done', ['kind' => $kind] + $this->count));

        return $this->count['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function partnerDocuments(): void
    {
        $folder = PartnerDocument::FOLDER . '/';

        PartnerDocument::withTrashed()->orderBy('id')->each(function (PartnerDocument $document) use ($folder) {
            $raw = (string) $document->getRawOriginal('file_path');
            if ($raw === '' || str_starts_with($raw, $folder)) {
                return;
            }

            $from = $this->firstExisting([
                Folder::PARTNERS . "/{$document->partner_id}/{$raw}",
                Folder::PARTNERS . "/{$document->partner_id}/" . Folder::DOCUMENTS . "/{$raw}",
            ]);
            $to = $folder . $document->partner_id . '/' . StoredFileName::make($document->document_name ?: basename($raw), pathinfo($raw, PATHINFO_EXTENSION));

            $this->relocate(__('files.relocate.what.partner_document', ['id' => $document->id]), $from, $to, function () use ($document, $to) {
                PartnerDocument::withTrashed()->whereKey($document->id)->update(['file_path' => $to]);
            });
        });
    }

    private function creditReports(): void
    {
        $folder = BorrowerDocument::CREDIT_REPORT_FOLDER . '/';
        $moved = [];

        // The report documents...
        BorrowerDocument::withTrashed()->where('is_credit_report', true)->orderBy('id')->each(function (BorrowerDocument $document) use ($folder, &$moved) {
            $raw = (string) $document->getRawOriginal('file_path');
            if ($raw === '' || str_starts_with($raw, $folder)) {
                return;
            }

            $from = $this->firstExisting([Folder::BORROWERS . "/{$document->borrower_id}/{$raw}"]);
            $to = $folder . $document->borrower_id . '/' . StoredFileName::anonymous('pdf');

            $this->relocate(__('files.relocate.what.credit_report', ['id' => $document->id]), $from, $to, function () use ($document, $from, $to, &$moved) {
                BorrowerDocument::withTrashed()->whereKey($document->id)->update(['file_path' => $to]);
                $moved[$from] = $to;
            });
        });

        // ...and every profile pointing at one by its URL, its document row or not.
        BorrowerCreditProfile::query()->whereNotNull('pdf_url')->orderBy('id')->each(function (BorrowerCreditProfile $profile) use ($folder, &$moved) {
            $from = StoragePath::fromUrl($profile->getRawOriginal('pdf_url'));
            if (!$from || str_starts_with($from, $folder)) {
                return;
            }

            if (isset($moved[$from])) {
                $this->dryRun || $profile->newQuery()->whereKey($profile->id)->update(['pdf_url' => $moved[$from]]);

                return;
            }

            $to = $folder . $profile->borrower_id . '/' . StoredFileName::anonymous('pdf');
            $this->relocate(__('files.relocate.what.credit_profile', ['id' => $profile->id]), $this->firstExisting([$from]), $to, function () use ($profile, $from, $to, &$moved) {
                $profile->newQuery()->whereKey($profile->id)->update(['pdf_url' => $to]);
                $moved[$from] = $to;
            }, $from);
        });
    }

    private function resourceProviderLogos(): void
    {
        $folder = ResourceProvider::LOGO_FOLDER . '/';
        $resourceFiles = Resource::withTrashed()->whereNotNull('file')->pluck('file')
            ->map(fn ($file) => StoragePath::fromUrl($file))->filter()->all();

        // One copy per logo, however many providers show it.
        ResourceProvider::query()->whereNotNull('logo')->where('logo', '!=', '')
            ->get(['id', 'logo'])
            ->groupBy(fn (ResourceProvider $provider) => (string) StoragePath::fromUrl($provider->logo))
            ->each(function ($providers, $from) use ($folder, $resourceFiles) {
                if ($from === '' || str_starts_with($from, $folder) || !Storage::disk(Disk::UPLOADS)->isPrivate($from)) {
                    return;
                }

                $to = $folder . StoredFileName::make(basename($from));
                $keepSource = in_array($from, $resourceFiles, true);
                $this->relocate(__('files.relocate.what.provider_logo', ['ids' => $providers->pluck('id')->implode(', #')]), $this->firstExisting([$from]), $to, function () use ($providers, $to) {
                    ResourceProvider::whereKey($providers->pluck('id'))->update(['logo' => $to]);
                }, $from, $keepSource);
            });
    }

    private function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (Storage::disk(Disk::UPLOADS)->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Copy, check, point the record at the copy, then (with --delete-source) drop the old file. */
    private function relocate(string $what, ?string $from, string $to, callable $pointRecord, ?string $expected = null, bool $keepSource = false): void
    {
        if (!$from) {
            $this->warn(__('files.relocate.missing', ['what' => $what, 'path' => $expected ?? '']));
            $this->count['missing']++;

            return;
        }

        if ($this->dryRun) {
            $this->line(__('files.relocate.would_move', ['what' => $what, 'from' => $from, 'to' => $to]));
            $this->count['moved']++;

            return;
        }

        $disk = Storage::disk(Disk::UPLOADS);
        try {
            $contents = $disk->get($from);
            $disk->put($to, $contents);
            if ($disk->get($to) !== $contents) {
                $disk->delete($to);
                $this->error(__('files.move.mismatch', ['path' => $from]));
                $this->count['failed']++;

                return;
            }

            DB::transaction($pointRecord);
            if ($this->deleteSource && !$keepSource) {
                $disk->delete($from);
            }

            $this->line(__('files.relocate.moved', ['what' => $what, 'from' => $from, 'to' => $to]));
            $this->count['moved']++;
        } catch (Throwable $e) {
            $this->error(__('files.move.failed', ['path' => $from, 'error' => $e->getMessage()]));
            $this->count['failed']++;
        }
    }
}
