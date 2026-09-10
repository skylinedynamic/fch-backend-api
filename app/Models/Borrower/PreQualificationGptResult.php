<?php

namespace App\Models\Borrower;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One half of an AUE evaluation: either the internal underwriting output or the
 * borrower-safe client output, distinguished by "type".
 *
 * Both live in one table, so ALWAYS scope reads with client() or internal().
 * The internal payload contains the underwriting memo, risk flags and lender
 * routing and must never reach a borrower or partner.
 */
class PreQualificationGptResult extends Model
{
    protected $table = 'pre_qualification_gpt_results';

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_CLIENT = 'client';

    protected $fillable = [
        'pre_qualification_request_id',
        'type',
        'run_id',
        'prompt_version',
        'matrix_version',
        'model',
        // OpenAI's id for the call that produced this row, kept so it can be
        // traced back to that exact request in OpenAI's own logs.
        'openai_request_id',
        'reasoning_effort',
        'payload',
        'is_placeholder',
        'latency_ms',
        'created_by',
    ];

    protected $casts = [
        'payload' => 'array',
        'is_placeholder' => 'boolean',
    ];

    /** Borrower-safe output only. */
    public function scopeClient(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CLIENT);
    }

    /** FCH-internal output only — never expose to a borrower or partner. */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_INTERNAL);
    }

    public function scopeForApplication(Builder $query, int $applicationId): Builder
    {
        return $query->where('pre_qualification_request_id', $applicationId);
    }

    public function preQualificationRequest()
    {
        return $this->belongsTo(PreQualificationRequest::class, 'pre_qualification_request_id');
    }
}
