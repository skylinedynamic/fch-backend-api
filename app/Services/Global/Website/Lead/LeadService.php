<?php

namespace App\Services\Global\Website\Lead;

use App\Services\FCH\Common\Notification\Notifier;
use App\Models\Lead\Lead;
use App\Models\Partner\Partner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use App\Mail\Lead\LeadNotificationMail;

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
                // The pre-qualification's business details (BUG-090); a plain
                // website enquiry has none.
                'country' => $data['country'] ?? null,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_type_note' => $data['entity_type_note'] ?? null,
                'industry_type' => $data['industry_type'] ?? null,
                'industry_type_note' => $data['industry_type_note'] ?? null,
                'requirement_details' => $data['requirement_details'] ?? $data['requirements_details'] ?? null,
                'status' => 'New Lead',
                'lead_type' => 'Borrower', // Default type for website leads
                'lead_source' => Lead::LEAD_SOURCE_WEBSITE,
            ];

            $lead = Lead::create($leadData);

            // Send Email to Host Partner (is_host == 1)
            // Catch \Throwable (not \Exception) so a mail failure - e.g. a \TypeError
            // from a misconfigured mailer - is logged and bypassed here rather than
            // reaching the outer catch below and rolling back the lead that was just created.
            if ($hostPartner) {
                try {
                    $details = [
                        'type' => 'website',
                        'is_pre_qualification' => $isPreQualification,
                        'prequalification' => $preQualificationData
                    ];
                    $email = $hostPartner->email ?: $hostPartner->contact_person_email;

                    // Inside the transaction: the bell and a queued email both
                    // wait for the commit, so a rolled-back lead announces nothing.
                    app(Notifier::class)->send('lead.created', $hostPartner, [
                        'id' => $lead->id,
                        'name' => $lead->contact_person ?: $lead->company_name,
                        'source' => strtolower((string) $lead->lead_source),
                    ], mail: $email ? (new LeadNotificationMail($lead, (object) $details))->to($email) : null);
                } catch (\Throwable $e) {
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
