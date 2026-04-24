<?php

namespace App\Services\Global\Resource;

use App\Models\Resource\Resource;
use App\Models\User\User;
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
        $user = auth()->user();

        $query->where('status', true);

        if ($user) {
            if ($user->user_type === User::ADMIN) {
                $query->where(function ($q) {
                    $q->where('is_borrower', true)->orWhere('is_partner', true);
                });
            } elseif ($user->user_type === User::PARTNER) {
                $query->where('is_partner', true);
            } elseif ($user->user_type === User::BORROWER) {
                $query->where('is_borrower', true);
            } else {
                $query->where('is_borrower', true);
            }
        } else {
            $query->where('is_borrower', true);
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
