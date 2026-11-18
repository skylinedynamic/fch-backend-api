<?php

namespace App\Services\Audit;

use App\Models\Audit\FundingApplicationAuditEvent;
use App\Models\Borrower\FundingApplication;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;
use RuntimeException;
use App\Support\Files\Disk;
use App\Support\Files\Folder;

/**
 * Writes and checks the funding application audit trail.
 *
 * Each event is chained to the one before it on the same application: its
 * hash covers the previous hash and every recorded column, so editing or
 * removing a row breaks the chain from there on (verify()).
 *
 * Recording never fails the change it records: an event that cannot be
 * written is logged, and the change goes ahead.
 */
class FundingApplicationAuditTrail
{
    public const MAX_PER_PAGE = 100;

    /** The audit folder on the uploads disk: private (config/files.php private_folders). */
    public const FOLDER = Folder::AUDIT;
    /**
     * Signed copies, under their application and the version signed:
     * applications/{id}/v{n}/{application|certificate}-{sha256}.pdf.
     */
    public const APPLICATIONS_FOLDER = self::FOLDER . '/applications';
    /** The version folder of a copy recorded without a version. */
    public const UNVERSIONED_FOLDER = 'unversioned';
    /**
     * Kept files, one per content hash: files/{sha256}.{ext}. Where every
     * signed copy was kept before APPLICATIONS_FOLDER; those stay here.
     */
    public const FILES_FOLDER = self::FOLDER . '/files';
    /** The daily seals: seals/{Y-m-d}.json. */
    public const SEALS_FOLDER = self::FOLDER . '/seals';

    /** @var array<int, list<string>> each application's kept copies, by filesOf() */
    private array $applicationFiles = [];

    /** The columns a hash covers, in this order. */
    private const HASHED = [
        'funding_application_id', 'partner_id', 'borrower_id', 'event',
        'actor_user_id', 'actor_name', 'actor_type', 'ip_address', 'user_agent',
        'changes', 'context', 'file_hash', 'file_path', 'occurred_at', 'is_legacy',
    ];

    /**
     * Record an event on an application, as the signed-in user.
     *
     * @param  list<array{field: string, old: mixed, new: mixed}>|null  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(
        FundingApplication|int $application,
        string $event,
        ?array $changes = null,
        array $context = [],
        ?string $fileHash = null,
        ?string $filePath = null,
    ): ?FundingApplicationAuditEvent {
        try {
            $application = $application instanceof FundingApplication
                ? $application
                : FundingApplication::withTrashed()->find($application);

            if (!$application) {
                return null;
            }

            $user = Auth::user();
            $request = app()->runningInConsole() && !app()->runningUnitTests() ? null : request();

            return $this->append([
                'funding_application_id' => $application->id,
                'partner_id' => $application->partner_id,
                'borrower_id' => $application->borrower_id,
                'event' => $event,
                'actor_user_id' => $user?->id,
                'actor_name' => $user ? (trim((string) $user->name) ?: null) : __('audit.system'),
                'actor_type' => $user?->user_type,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) ?: null : null,
                'changes' => $changes ?: null,
                'context' => $context ?: null,
                'file_hash' => $fileHash,
                'file_path' => $filePath,
                'occurred_at' => now('UTC'),
                'is_legacy' => false,
            ]);
        } catch (Throwable $e) {
            Log::error(__('audit.log_record_failed'), [
                'funding_application_id' => $application instanceof FundingApplication ? $application->id : $application,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Append a fully formed event, chained to the last one on its
     * application. Used by record() and by the import of the old download
     * history.
     */
    public function append(array $attributes): FundingApplicationAuditEvent
    {
        return DB::transaction(function () use ($attributes) {
            $previous = FundingApplicationAuditEvent::where('funding_application_id', $attributes['funding_application_id'])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first(['id', 'hash']);

            $attributes['occurred_at'] = $this->utc($attributes['occurred_at'] ?? now('UTC'));
            $attributes['is_legacy'] = (bool) ($attributes['is_legacy'] ?? false);
            $attributes['prev_hash'] = $previous?->hash;
            $attributes['hash'] = $this->hash($attributes, $attributes['prev_hash']);

            return FundingApplicationAuditEvent::create($attributes);
        });
    }

    /**
     * Walk an application's chain. The first event whose hash does not match
     * what its columns and the previous hash give - or whose prev_hash is not
     * the previous event's hash - is where it broke.
     *
     * @return array{valid: bool, broken_at: int|null, checked: int}
     */
    public function verify(int $applicationId): array
    {
        $previousHash = null;
        $checked = 0;

        foreach (FundingApplicationAuditEvent::where('funding_application_id', $applicationId)->orderBy('id')->cursor() as $event) {
            $attributes = $event->getAttributes();
            $attributes['changes'] = $event->changes;
            $attributes['context'] = $event->context;

            if ($event->prev_hash !== $previousHash || !hash_equals($this->hash($attributes, $previousHash), (string) $event->hash)) {
                return ['valid' => false, 'broken_at' => $event->id, 'checked' => $checked];
            }

            $previousHash = $event->hash;
            $checked++;
        }

        return ['valid' => true, 'broken_at' => null, 'checked' => $checked];
    }

    /**
     * The fields that changed on a model, before and after, as strings -
     * masked where they are masked, without bookkeeping columns.
     *
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    public function changesOf(Model $model, array $except = []): array
    {
        $ignored = array_merge(config('audit.ignored_fields', []), $except);
        $changes = [];

        foreach (array_keys($model->getChanges()) as $field) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            $old = $this->value($model->getOriginal($field), $field);
            $new = $this->value($model->getAttribute($field), $field);

            if ($old !== $new) {
                $changes[] = ['field' => $field, 'old' => $old, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * A new model's filled-in fields, as changes from nothing.
     *
     * @return list<array{field: string, old: null, new: string}>
     */
    public function initialValues(Model $model, array $except = []): array
    {
        $ignored = array_merge(config('audit.ignored_fields', []), $except);
        $changes = [];

        foreach ($model->getAttributes() as $field => $raw) {
            $new = in_array($field, $ignored, true) ? null : $this->value($model->getAttribute($field), $field);
            if ($new !== null) {
                $changes[] = ['field' => $field, 'old' => null, 'new' => $new];
            }
        }

        return $changes;
    }

    /**
     * The SHA-256 of a file, without keeping a copy: a document is recorded by
     * its hash only. Null when the file is not there or cannot be read.
     */
    public function fingerprint(?string $disk, ?string $path): ?string
    {
        try {
            $source = Storage::disk($disk ?: Disk::UPLOADS);
            if (!$path || !$source->exists($path)) {
                return null;
            }

            $stream = $source->readStream($path);
            if (!is_resource($stream)) {
                return null;
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);

            return hash_final($context);
        } catch (Throwable $e) {
            Log::error(__('audit.log_hash_failed'), ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Keep these bytes in the audit folder as {sha256}.{extension}, once. The
     * same content is never written twice, and an existing copy is never
     * overwritten. Throws when it cannot be written.
     *
     * @return array{hash: string, path: string}
     */
    public function keepContents(string $contents, ?string $extension = null): array
    {
        $hash = hash('sha256', $contents);
        $extension = strtolower((string) $extension);
        $kept = self::FILES_FOLDER . "/{$hash}" . ($extension !== '' ? ".{$extension}" : '');

        $audit = Storage::disk(Disk::UPLOADS);
        if (!$audit->exists($kept) && !$audit->put($kept, $contents)) {
            throw new RuntimeException(__('audit.write_failed', ['path' => $kept]));
        }

        return ['hash' => $hash, 'path' => $kept];
    }

    /**
     * Keep a signed copy under its application and the version signed,
     * applications/{id}/v{n}/{application|certificate}-{sha256}.{ext}, once.
     * The hash is in the name, so an existing copy is never overwritten and
     * changed bytes can never pass for it. Throws when it cannot be written.
     *
     * @return array{hash: string, path: string}
     */
    public function keepSignedCopy(string $contents, int $applicationId, string|int|null $version, string $fileType, string $extension = 'pdf'): array
    {
        $hash = hash('sha256', $contents);
        $kept = $this->keptCopyPath($applicationId, $version, $fileType, $hash, $extension);

        $audit = Storage::disk(Disk::UPLOADS);
        if (!$audit->exists($kept) && !$audit->put($kept, $contents)) {
            throw new RuntimeException(__('audit.write_failed', ['path' => $kept]));
        }
        unset($this->applicationFiles[$applicationId]);

        return ['hash' => $hash, 'path' => $kept];
    }

    /** Where keepSignedCopy() keeps a copy. */
    public function keptCopyPath(int $applicationId, string|int|null $version, ?string $fileType, string $hash, string $extension = 'pdf'): string
    {
        $version = preg_replace('/[^A-Za-z0-9._-]/', '', (string) $version);
        $versionFolder = $version !== '' ? "v{$version}" : self::UNVERSIONED_FOLDER;
        $name = FundingApplicationAuditEvent::FILE_NAMES[$fileType] ?? FundingApplicationAuditEvent::FILE_NAME_OTHER;
        $extension = strtolower($extension);

        return self::APPLICATIONS_FOLDER . "/{$applicationId}/{$versionFolder}/{$name}-{$hash}" . ($extension !== '' ? ".{$extension}" : '');
    }

    /**
     * Where an event's file is in the audit folder, or null when the folder
     * does not hold it. In this order:
     * 1. the event's own path;
     * 2. for an event that names its file by hash only (a download, an
     *    imported event), the copy with that hash under the event's own
     *    application - never another application's;
     * 3. the copy kept flat as files/{sha256}.pdf, before APPLICATIONS_FOLDER.
     */
    public function storedFile(FundingApplicationAuditEvent $event): ?string
    {
        // A document is recorded by its hash only; the trail keeps no copy of it.
        if (in_array($event->event, FundingApplicationAuditEvent::DOCUMENT_EVENTS, true)) {
            return null;
        }

        $disk = Storage::disk(Disk::UPLOADS);
        if ($event->file_path && $disk->exists($event->file_path)) {
            return $event->file_path;
        }

        $hash = (string) $event->file_hash;
        if ($hash === '') {
            return null;
        }

        foreach ($this->filesOf((int) $event->funding_application_id) as $path) {
            if (str_contains(basename($path), "-{$hash}.")) {
                return $path;
            }
        }

        $flat = self::FILES_FOLDER . "/{$hash}.pdf";

        return $disk->exists($flat) ? $flat : null;
    }

    /**
     * The copies kept under an application, read once per request (the trail
     * is bound scoped) - a trail page asks for many events' files.
     *
     * @return list<string>
     */
    private function filesOf(int $applicationId): array
    {
        return $this->applicationFiles[$applicationId]
            ??= Storage::disk(Disk::UPLOADS)->allFiles(self::APPLICATIONS_FOLDER . "/{$applicationId}");
    }

    private function value(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            $value = $value->format('Y-m-d');
        } elseif (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $value = (string) $value;
        }

        if (in_array($field, config('audit.masked_fields', []), true)) {
            return $this->mask($value);
        }

        return $value;
    }

    private function mask(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value);
        $last = substr($digits !== '' ? $digits : $value, -4);

        return strlen($digits) === 9 ? "***-**-{$last}" : '****' . $last;
    }

    private function utc(mixed $value): string
    {
        return Carbon::parse($value, 'UTC')->utc()->format('Y-m-d H:i:s');
    }

    private function hash(array $attributes, ?string $previousHash): string
    {
        $payload = [];
        foreach (self::HASHED as $column) {
            $value = $attributes[$column] ?? null;
            if ($column === 'occurred_at' && $value !== null) {
                $value = $this->utc($value);
            } elseif ($column === 'is_legacy') {
                $value = (bool) $value;
            } elseif (in_array($column, ['funding_application_id', 'partner_id', 'borrower_id', 'actor_user_id'], true) && $value !== null) {
                $value = (int) $value;
            } elseif (is_string($value) && in_array($column, ['changes', 'context'], true)) {
                $value = json_decode($value, true);
            }
            $payload[$column] = is_array($value) ? $this->canonical($value) : $value;
        }

        return hash('sha256', ($previousHash ?? '') . '|' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** Keys sorted at every level: MySQL's JSON column does not keep them in the order written. */
    private function canonical(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? $this->canonical($item) : $item, $value);
    }
}
