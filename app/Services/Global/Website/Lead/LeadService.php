<?php

namespace App\Services\Global\Website\Lead;

use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class LeadService
{
    /**
     * Store a new lead from the website.
     */
    public function store(array $data): Lead
    {
        DB::beginTransaction();
        try {
            $leadIds = $this->generateLeadIds();
            $partnerId = Partner::where('is_host', true)->first()?->id;
            $leadData = [
                'partner_id' => $partnerId,
                'lead_id' => $leadIds['lead_id'],
                'tasks_no' => $leadIds['tasks_no'],
                'contact_person' => $data['contact_person'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'] ?? null,
                'company_name' => $data['company_name'] ?? null,
                'domain' => $data['domain'] ?? null,
                'state' => $data['state'] ?? null,
                'requirement_details' => $data['requirement_details'] ?? $data['requirements_details'] ?? null,
                'status' => 'New Lead',
                'lead_type' => 'Borrower', // Default type for website leads
                'lead_source' => 'Website',
            ];

            $lead = Lead::create($leadData);

            DB::commit();
            return $lead->fresh();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Website Lead store failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Generate Unique Lead ID and Task No
     * (Following LeadService logic)
     */
    private function generateLeadIds(): array
    {
        $datePart = now()->format('Y-md');
        $count = Lead::withTrashed()->whereDate('created_at', now()->toDateString())->count() + 1;

        return [
            'lead_id' => sprintf('LD-%s-%03d', $datePart, $count),
            'tasks_no' => sprintf('LD-TASK-%s-%03d', str_replace('-', '', $datePart), $count),
        ];
    }
}
