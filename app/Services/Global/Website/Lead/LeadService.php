<?php

namespace App\Services\Global\Website\Lead;

use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\Support\Facades\Mail;

class LeadService
{
    /**
     * Store a new lead from the website.
     */
    public function store(array $data, bool $isPreQualification = false, $preQualificationData = null): Lead
    {
        DB::beginTransaction();
        try {
            $leadIds = $this->generateLeadIds();
            $hostPartner = Partner::where('is_host', true)->first();
            $partnerId = $hostPartner?->id;
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
                'lead_source' => Lead::LEAD_SOURCE_WEBSITE,
            ];

            $lead = Lead::create($leadData);

            // Send Email to Host Partner (is_host == 1)
            if ($hostPartner && ($hostPartner->email || $hostPartner->contact_person_email)) {
                try {
                    $details = [
                        'type' => 'website',
                        'is_pre_qualification' => $isPreQualification,
                        'prequalification' => $preQualificationData
                    ];
                    try {
                        Mail::to($hostPartner->email ?: $hostPartner->contact_person_email)
                            ->send(new \App\Mail\Lead\LeadNotificationMail($lead, (object) $details));
                    } catch (\Exception $e) {
                        Log::error('Failed to send lead email notification to host', ['error' => $e->getMessage()]);
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to send lead email notification to host', ['error' => $e->getMessage()]);
                }
            }

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
