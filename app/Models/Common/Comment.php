<?php

namespace App\Models\Common;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\FundingRequest\FundingRequest;

/**
 * A comment is an ActivityLog entry (log_name = ActivityLog::TYPE_ACTIVITY)
 * — there is no dedicated comments table or model, and no separate
 * visibility/ownership logic: CommentService reads and writes ActivityLog
 * directly, reusing its scopeVisibleTo()/isEditableBy()/isHostUser() rather
 * than duplicating them.
 *
 * This class only holds the allowlist of models a comment may attach to,
 * deliberately fixed rather than any class name, so a comment can't be
 * attached to an arbitrary/sensitive model via a crafted entity_type.
 */
class Comment
{
    public const ALLOWED_ENTITY_TYPES = [
        'funding_request' => FundingRequest::class,
        'funding_application' => FundingApplication::class,
        'borrower' => Borrower::class,
    ];
}
