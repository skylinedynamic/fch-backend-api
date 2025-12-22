<?php

namespace App\Services\FCH\Common\TierTask;

use App\Models\CreditBuilder\TierTask;

class TierTaskService
{
    /**
     * List all tier tasks with id and title.
     * Can be filtered by tier_id.
     */
    public function list(array $filters = [])
    {
        $query = TierTask::select('id', 'tier_id', 'title')->orderBy('order', 'asc');

        if (!empty($filters['tier_id'])) {
            $query->where('tier_id', $filters['tier_id']);
        }

        return $query->get();
    }
}
