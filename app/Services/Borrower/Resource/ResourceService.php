<?php

namespace App\Services\Borrower\Resource;

use App\Models\Resource\Resource;
use Illuminate\Database\Eloquent\Collection;

class ResourceService
{
    /**
     * Get resources by type
     *
     * @return Collection
     */
    public function getResources(): Collection
    {
        $query = Resource::where('status', true);

        return $query->oldest('id')->get();
    }

    /**
     * Get all resources grouped by type
     *
     * @return array
     */
    public function getAllResourcesGrouped(): array
    {
        $resources = Resource::where('status', true)->oldest('id')->get();

        return $resources->groupBy('resource_type')->toArray();
    }
}
