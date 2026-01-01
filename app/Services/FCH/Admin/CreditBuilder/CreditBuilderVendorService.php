<?php

namespace App\Services\FCH\Admin\CreditBuilder;

use App\Models\CreditBuilder\CreditBuilderVendor;

class CreditBuilderVendorService
{
    /**
     * Get all vendors with pagination.
     *
     * @param int $limit
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function getAll($limit = 10)
    {
        return CreditBuilderVendor::latest()->paginate($limit);
    }

    /**
     * Store a new vendor.
     *
     * @param array $data
     * @return CreditBuilderVendor
     */
    public function create(array $data)
    {
        return CreditBuilderVendor::create($data);
    }

    /**
     * Get a specific vendor by ID.
     *
     * @param int $id
     * @return CreditBuilderVendor
     */
    public function show($id)
    {
        return CreditBuilderVendor::findOrFail($id);
    }

    /**
     * Update a vendor.
     *
     * @param int $id
     * @param array $data
     * @return CreditBuilderVendor
     */
    public function update($id, array $data)
    {
        $vendor = CreditBuilderVendor::findOrFail($id);
        $vendor->update($data);
        return $vendor;
    }

    /**
     * Delete a vendor.
     *
     * @param int $id
     * @return bool
     */
    public function delete($id)
    {
        $vendor = CreditBuilderVendor::findOrFail($id);
        return $vendor->delete();
    }
}
