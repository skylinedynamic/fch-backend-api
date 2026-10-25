<?php

namespace App\Services\Global\Website\PreQualification;

use App\Models\Borrower\PreQualificationRequest;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use App\Services\Global\Website\Lead\LeadService;
use App\Support\Lead\WebsiteBusinessOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PreQualificationService
{
    public function __construct(
        protected LeadService $leadService
    ) {}

    /**
     * Store all pre-qualification data (Personal + Business + Funding) in one request.
     * Also creates/updates the Lead record.
     */
    public function store(array $data): array
    {
        DB::beginTransaction();
        try {
            // 1. Create Lead - its entity and industry in the portal's own
            // options, and the country its US state of incorporation is in (BUG-090).
            [$entityType, $entityNote] = WebsiteBusinessOptions::entity($data['business_structure'] ?? null);
            [$industryType, $industryNote] = WebsiteBusinessOptions::industry($data['industry_type'] ?? null);
            $leadData = [
                'contact_person' => $data['first_name'] . ' ' . $data['last_name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'],
                'domain' => $data['business_website'] ?? null,
                'state' => $data['state_of_incorporation'] ?? null,
                'country' => Lead::DEFAULT_COUNTRY,
                'company_name' => $data['business_name'] ?? null,
                'industry_type' => $industryType,
                'industry_type_note' => $industryNote,
                'entity_type' => $entityType,
                'entity_type_note' => $entityNote,
                'requirement_details' => $data['notes'] ?? null,
            ];

            // Set lead_source explicitly for pre-qualification from website
            $leadData['lead_source'] = Lead::LEAD_SOURCE_WEBSITE;

            $lead = $this->leadService->store($leadData, true, (object)$data);

            // 2. Create Pre-Qualification Request
            $preQual = PreQualificationRequest::create([
                'lead_id' => $lead->id,
                'partner_id' => $lead->partner_id,

                // Personal Information
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone_number' => $data['phone_number'],
                'business_website' => $data['business_website'] ?? null,
                'state_of_incorporation' => $data['state_of_incorporation'] ?? null,

                // Business Information
                'business_name' => $data['business_name'],
                'industry_type' => $data['industry_type'],
                'business_structure' => $data['business_structure'],
                'years_in_business' => $data['years_in_business'],
                'monthly_revenue' => $data['monthly_revenue'],
                'annual_revenue' => $data['annual_revenue'],

                // Funding Requirements
                'funding_requested_amount' => $data['funding_requested_amount'],
                'funding_urgency' => $data['funding_urgency'],
                'purpose_of_funding' => $data['purpose_of_funding'],
                'personal_credit_score' => $data['personal_credit_score'],
                'estimated_business_credit_score' => $data['estimated_business_credit_score'],
                'existing_business_debt' => $data['existing_business_debt'],
                'debt_balance' => $data['debt_balance'] ?? null,
                'notes' => $data['notes'] ?? null,

                'status' => PreQualificationRequest::PENDING,
            ]);

            DB::commit();

            return [
                'lead' => $lead->fresh(),
                'pre_qualification' => $preQual->fresh(),
            ];
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Website PreQualification store failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
