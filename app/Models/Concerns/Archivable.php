<?php

namespace App\Models\Concerns;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Archiving for records that should leave the working lists without being deleted.
 *
 * The one rule that shapes everything here: there is NO global scope. A list opts
 * in to hiding archived rows by calling notArchived(); everything else - search,
 * reports, relations, counts - keeps seeing them, which is what "archived records
 * remain visible in search results" requires.
 *
 * That is also why this is not SoftDeletes. Both models using this trait already
 * soft-delete, so deleted_at means deleted, and its global scope would hide an
 * archived record from the very searches that must still find it.
 *
 * Archiving is presentation-only. It does not gate login, billing or permissions -
 * those key on `status`, which nothing here touches.
 */
trait Archivable
{
    /**
     * The values scopeArchiveFilter() understands, sent verbatim from the list
     * screens. Declared here, next to the only code that interprets them, so a
     * caller never has to guess the spelling of the "show everything" sentinel.
     */
    public const ARCHIVE_FILTER_ALL = 'all';

    /** Rows that have not been archived: the default working list. */
    public function scopeNotArchived($query)
    {
        return $query->whereNull($this->qualifyColumn('archived_at'));
    }

    /** Only archived rows. */
    public function scopeArchived($query)
    {
        return $query->whereNotNull($this->qualifyColumn('archived_at'));
    }

    /**
     * Apply the archive filter a list screen asked for.
     *
     *   null / ''  -> the working list: archived rows hidden
     *   truthy     -> only archived rows
     *   'all'      -> everything, archived included
     *
     * Kept here rather than repeated in each service so the two lists cannot drift.
     */
    public function scopeArchiveFilter($query, $archived)
    {
        if ($archived === self::ARCHIVE_FILTER_ALL) {
            return $query;
        }

        return filter_var($archived, FILTER_VALIDATE_BOOLEAN)
            ? $query->archived()
            : $query->notArchived();
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Archive this record, remembering who did it.
     *
     * Idempotent: archiving an already-archived record keeps the original
     * timestamp and actor rather than rewriting the history of who filed it away.
     */
    public function archive(?int $userId = null): bool
    {
        if ($this->archived_at) {
            return false;
        }

        return $this->forceFill([
            'archived_at' => now(),
            'archived_by' => $userId ?? Auth::id(),
        ])->save();
    }

    /** Return this record to the working list. */
    public function unarchive(): bool
    {
        if (!$this->archived_at) {
            return false;
        }

        return $this->forceFill([
            'archived_at' => null,
            'archived_by' => null,
        ])->save();
    }
}
