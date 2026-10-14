<?php

namespace App\Services\Global\Resource;

use App\Models\Resource\Resource;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Builder;

class ResourceCommonService
{
    /**
     * Get resources grouped by type.
     * 
     * @return array
     */
    public function getGroupedResources(): array
    {
        $resources = $this->listable()->with([
            'providers' => function ($q) {
                $q->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
            }
        ])->orderBy('sort_order', 'asc')->orderBy('id', 'asc')->get();

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
        // Only a resource the caller's library lists downloads.
        return $this->listable()->findOrFail($id)->download();
    }

    /**
     * The resources the caller's library lists: everything for the host team,
     * the published ones meant for their portal for partners and borrowers.
     */
    private function listable(): Builder
    {
        $query = Resource::query();
        $user = auth()->user();

        if ($user->user_type === User::PARTNER) {
            $query->where('status', true);
            $query->where('is_partner', true);
        } elseif ($user->user_type === User::BORROWER) {
            $query->where('status', true);
            $query->where('is_borrower', true);
        }

        return $query;
    }

    /**
     * Get a resource by ID.
     */
    public function getById(int $id): Resource
    {
        $query = Resource::query();
        $user = auth()->user();

        if ($user->user_type === User::PARTNER) {
            $query->where('is_partner', true);
        } elseif ($user->user_type === User::BORROWER) {
            $query->where('is_borrower', true);
        }

        return $query->where('status', true)->with([
            'providers' => function ($q) {
                $q->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
            }
        ])->findOrFail($id);
    }
}
