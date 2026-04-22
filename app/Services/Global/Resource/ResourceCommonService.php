<?php

namespace App\Services\Global\Resource;

use App\Models\Resource\Resource;
use Illuminate\Database\Eloquent\Collection;

class ResourceCommonService
{
    /**
     * Get resources grouped by type.
     * 
     * @param bool $onlyActive If true, only returns resources with status=1
     * @return array
     */
    public function getGroupedResources(bool $onlyActive = true): array
    {
        $query = Resource::query();
        
        if ($onlyActive) {
            $query->where('status', true);
        }

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
