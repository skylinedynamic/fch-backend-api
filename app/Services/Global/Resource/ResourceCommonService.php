<?php

namespace App\Services\Global\Resource;

use App\Models\Resource\Resource;
use Illuminate\Database\Eloquent\Collection;

class ResourceCommonService
{
    /**
     * Get resources grouped by type.
     * 
     * @return array
     */
    public function getGroupedResources(): array
    {
        $query = Resource::query();

        $query->where('status', true)->where('is_borrower', true);

        $resources = $query->oldest('id')->get();

        $types = Resource::getTypes();
        $grouped = array_fill_keys($types, []);

        foreach ($resources as $resource) {
            $grouped[$resource->resource_type][] = $resource;
        }

        return $grouped;
    }

    /**
     * Handle the download of a resource file.
     * 
     * @param int $id
     * @return mixed
     */
    public function downloadResource(int $id)
    {
        $resource = Resource::findOrFail($id);
        return $resource->download();
    }

    /**
     * Get a resource by ID.
     */
    public function getById(int $id): Resource
    {
        return Resource::findOrFail($id);
    }
}
