<?php

namespace App\Services\FCH\Admin\Lender;

use App\Models\Lender\Lender;

class LenderService
{
    /**
     * Get paginated lenders.
     */
    public function getLenders($perPage = 10, $sortField = 'id', $sortOrder = 'desc', $search = null)
    {
        $query = Lender::query()->search($search);

        $query->orderBy($sortField, $sortOrder);

        if ($perPage == -1 || $perPage == 'All') {
            return $query->get();
        }

        return $query->paginate($perPage);
    }

    /**
     * Get all active lenders (id and name only).
     */
    public function getAllActiveLenders()
    {
        return Lender::where('status', Lender::STATUS_ACTIVE)
            ->select('id', 'name', 'legal_name', 'status', 'product_categories', 'contact_phone')
            ->orderBy('name', 'asc')
            ->get();
    }

    /**
     * Store a new lender.
     */
    public function createLender(array $data)
    {
        return Lender::create($data);
    }

    /**
     * Update an existing lender.
     */
    public function updateLender(Lender $lender, array $data)
    {
        $lender->update($data);
        return $lender;
    }

    /**
     * Delete a lender.
     */
    public function deleteLender(Lender $lender)
    {
        return $lender->delete();
    }
}
