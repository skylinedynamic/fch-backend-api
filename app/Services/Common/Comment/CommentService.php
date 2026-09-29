<?php

namespace App\Services\Common\Comment;

use App\Models\ActivityLog\ActivityLog;
use App\Models\Borrower\Borrower;
use App\Models\Common\Comment;
use App\Traits\PaginatesApi;
use Illuminate\Support\Facades\Auth;

class CommentService
{
    use PaginatesApi;

    public function list(string $entityTypeKey, int $entityId, array $filters = [])
    {
        $entityClass = Comment::ALLOWED_ENTITY_TYPES[$entityTypeKey] ?? null;

        if (! $entityClass) {
            throw new \Exception('Unsupported entity type.', 422);
        }

        $user = Auth::user();

        $query = ActivityLog::with('causer')
            ->where('log_name', ActivityLog::TYPE_ACTIVITY)
            ->where('subject_type', $entityClass)
            ->where('subject_id', $entityId)
            ->visibleTo($user)
            ->orderBy('id', 'desc');

        $limit = $filters['limit'] ?? 15;
        $page = $filters['page'] ?? 1;

        return $query->paginate($limit, ['*'], 'page', $page);
    }

    public function store(array $data): ActivityLog
    {
        $entityClass = Comment::ALLOWED_ENTITY_TYPES[$data['entity_type']] ?? null;

        if (! $entityClass) {
            throw new \Exception('Unsupported entity type.', 422);
        }

        $entity = $entityClass::find($data['entity_id']);

        if (! $entity) {
            throw new \Exception('Record not found.', 404);
        }

        $user = Auth::user();

        // A Borrower entity IS the borrower — it has no borrower_id column of
        // its own, unlike FundingRequest/FundingApplication which reference
        // one. Using $entity->borrower_id for that case would silently
        // resolve to null (Eloquent returns null for an undefined attribute
        // rather than erroring), leaving the comment's borrower_id unset.
        $borrowerId = $entity instanceof Borrower
            ? $entity->id
            : ($entity->borrower_id ?? null);

        $activity = new ActivityLog([
            'log_name' => ActivityLog::TYPE_ACTIVITY,
            'description' => $data['body'],
            'subject_type' => $entityClass,
            'subject_id' => $data['entity_id'],
            'causer_type' => get_class($user),
            'causer_id' => $user->getKey(),
            'visibility' => $data['visibility'] ?? ActivityLog::VISIBILITY_INTERNAL,
            'partner_id' => $entity->partner_id ?? $user->partner_id ?? null,
            'borrower_id' => $borrowerId,
        ]);
        $activity->save();

        return $activity->load('causer');
    }

    public function update(int $id, array $data): ActivityLog
    {
        $activity = ActivityLog::findOrFail($id);

        if (! $activity->isEditableBy(Auth::user())) {
            throw new \Exception('You can only edit comments you created.', 403);
        }

        $activity->update(['description' => $data['body']]);

        return $activity->fresh('causer');
    }

    public function delete(int $id): void
    {
        $activity = ActivityLog::findOrFail($id);

        if (! $activity->isEditableBy(Auth::user()) && ! ActivityLog::isHostUser(Auth::user())) {
            throw new \Exception('You can only delete comments you created.', 403);
        }

        $activity->delete();
    }
}
