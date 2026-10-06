<?php

namespace App\Services\Borrower\PreQualification;

use App\Helpers\ApiResponse;
use App\Http\Resources\Borrower\PreQualification\PreQualificationResource;
use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationRequest;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use App\Traits\PaginatesApi;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Mail\PreQualificationSubmitted;
use Illuminate\Support\Facades\Mail;

class PreQualificationService
{
    use PaginatesApi;


    public function submit(array $data)
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        // Only an in-progress (On Hold) draft belonging to this borrower can be
        // submitted, which is also what stops the same request being submitted twice.
        $record = PreQualificationRequest::where('id', $data['id'] ?? null)
            ->where('borrower_id', $borrower->id)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->first();

        // Typed so the controller can answer 404 with the reason, rather than
        // reporting a caller-side state problem as a server error.
        if (!$record) {
            throw new ModelNotFoundException(__('borrower.pre_qualification_request_not_found'));
        }

        // $zapierResponse = $this->sendToZapier($record);

        $record->update([
            'status' => PreQualificationRequest::PENDING,
            'gpt_status' => PreQualificationRequest::GPT_PENDING,
            'gpt_generated_at' => null,
        ]);

        // The evaluation makes up to three OpenAI calls (roughly 1-2.5 minutes),
        // longer than the gateway in front of the API holds a request open. Run
        // inline, the borrower got a network error while the submit still
        // completed server-side, and a second click then failed as "not found".
        // So the response is sent first (status PENDING) and the evaluation runs
        // once it has been delivered; the result screen polls until it settles.
        $userId = Auth::id();
        app()->terminating(function () use ($record, $userId) {
            $this->runUnderwriting($record, $userId);

            // Send Email to Partner
            $record->load('partner');
            if ($record->partner && $record->partner->email) {
                try {
                    Mail::to($record->partner->email)->send(new PreQualificationSubmitted($record));
                } catch (\Throwable $e) {
                    Log::error('Failed to send pre-qualification email to partner', [
                        'record_id' => $record->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return ApiResponse::success(
            new PreQualificationResource($record->fresh()),
            __('borrower.pre_qualification_submitted_successfully')
        );
    }

    /**
     * Re-run the underwriting evaluation for a submitted request whose automated
     * review did not complete.
     *
     * The per-call retries inside the OpenAI client cover a transient blip; this
     * covers the case where those were exhausted (a long outage, an expired key,
     * exhausted credits) and the borrower wants to try again once it is resolved.
     * Deliberately limited to a FAILED evaluation: a completed one must not be
     * silently regenerated, and a request still being evaluated must not be run
     * twice concurrently.
     */
    public function retryUnderwriting(array $data)
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $record = PreQualificationRequest::where('id', $data['id'] ?? null)
            ->where('borrower_id', $borrower->id)
            ->where('gpt_status', PreQualificationRequest::GPT_FAILED)
            ->first();

        if (!$record) {
            throw new ModelNotFoundException(__('borrower.pre_qualification_retry_not_available'));
        }

        // Same as submit: answer first, evaluate after the response is delivered.
        // Being reviewed again, so it is no longer Failed; the outcome of this
        // run sets it from here.
        $record->applyUnderwritingOutcome(true);
        $record->gpt_status = PreQualificationRequest::GPT_PENDING;
        $record->gpt_generated_at = null;
        $record->save();

        $userId = Auth::id();
        app()->terminating(function () use ($record, $userId) {
            $this->runUnderwriting($record, $userId);
        });

        return ApiResponse::success(
            new PreQualificationResource($record->fresh()),
            __('borrower.pre_qualification_submitted_successfully')
        );
    }

    /**
     * Run the validator -> internal AUE -> client AUE pipeline for one request.
     *
     * Called after the HTTP response has been sent (see submit), so it takes the
     * acting user id explicitly rather than reading it from the request.
     *
     * A failure here must never fail the caller: the request itself is already
     * saved, so the evaluation is recorded as GPT_FAILED and the borrower is
     * offered a retry rather than being shown an error on a successful submit.
     */
    private function runUnderwriting(PreQualificationRequest $record, ?int $userId): void
    {
        // The evaluation may run for minutes after the response was sent; lift
        // the PHP execution limit so it cannot be cut off part-way and left PENDING.
        @set_time_limit(0);

        try {
            app(PreQualificationAiService::class)->evaluate($record, $userId);
        } catch (\Throwable $e) {
            $record->refresh();
            $record->applyUnderwritingOutcome(false);
            $record->gpt_status = PreQualificationRequest::GPT_FAILED;
            $record->save();

            Log::error('Pre-qualification AUE evaluation failed', [
                'pre_qualification_request_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendToZapier(PreQualificationRequest $record)
    {
        try {

            $zapierPayload = [
                'full_name' => 'Demo User',
                'phone' => '555-123-4567',
                'email' => 'demo@example.com',
                'business_name' => 'Demo Business Inc.',
                'website' => 'https://demo.example.com',
                'business_industry' => 'Technology',
                'state_of_incorporation' => 'CA',
                'business_structure' => 'LLC',
                'time_in_business' => '0-6 months',
                'monthly_revenue' => '50000',
                'annual_revenue' => '600000',
                'personal_credit_score_est' => '720',
                'business_credit_score_est' => '680',
                'existing_business_debt' => 'Yes',
                'balance' => '25000',
                'funding_request' => '100000',
                'purpose_of_funds' => 'Expansion',
                'funding_urgency' => 'Within 30 days',
                'borrower_notes' => 'Demo submission for testing purposes',
            ];

            // $zapierPayload = [
            //     'full_name' => $record->first_name . ' ' . $record->last_name,
            //     'phone' => $record->phone_number,
            //     'email' => $record->email,
            //     'business_name' => $record->business_name,
            //     'website' => $record->business_website,
            //     'business_industry' => $record->industry_type,
            //     'state_of_incorporation' => $record->state_of_incorporation,
            //     'business_structure' => $record->business_structure,
            //     'time_in_business' => $record->years_in_business,
            //     'monthly_revenue' => $record->monthly_revenue,
            //     'annual_revenue' => $record->annual_revenue,
            //     'personal_credit_score_est' => $record->personal_credit_score,
            //     'business_credit_score_est' => $record->estimated_business_credit_score,
            //     'existing_business_debt' => $record->existing_business_debt ? 'Yes' : 'No',
            //     'balance' => $record->debt_balance,
            //     'funding_request' => $record->funding_requested_amount,
            //     'purpose_of_funds' => $record->purpose_of_funding,
            //     'funding_urgency' => $record->funding_urgency,
            //     'borrower_notes' => $record->notes,
            // ];

            $response = Http::withoutVerifying()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post(
                    'https://hooks.zapier.com/hooks/catch/21994150/u4gwugj/',
                    $zapierPayload
                );

            Log::info('Zapier Webhook Response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $zapierPayload
            ]);

            return [
                'status' => $response->status(),
                'body' => $response->body(),
                'payload' => $zapierPayload
            ];
        } catch (\Exception $e) {
            Log::error('Zapier Webhook Failed', ['error' => $e->getMessage()]);
            throw new \Exception(__('borrower.zapier_webhook_failed'));
        }
    }


    public function prequalificationRequest()
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        $prequalificationRequest = PreQualificationRequest::where('borrower_id', $borrower->id)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->first();

        $mergedData = [];


        $mappedData = $this->mappingData();

        if (!$prequalificationRequest) {
            // Pre-fill the wizard from the borrower's profile WITHOUT persisting a row.
            // A read must not create data: this branch used to INSERT a draft on every
            // page load that found no ON_HOLD record, so the moment a borrower submitted
            // (their row becoming PENDING) the next load spawned an empty duplicate draft.
            // The row is now created when the borrower actually saves the first step.
            $mergedData = array_merge(['id' => null], $this->borrowerPrefill($borrower));
        } else {
            $mergedData['id'] = $prequalificationRequest->id;
            foreach ($prequalificationRequest->toArray() as $key => $value) {
                if (!$this->hasValue($value) && isset($mappedData[$key])) {
                    $mergedData[$key] = $borrower->{$mappedData[$key]};
                } else {
                    $mergedData[$key] = $value;
                }
            }
        }

        // Funding Needs must never be populated with previous/saved data on GET.
        // It must always start fresh and blank for the borrower.
        $mergedData['funding_requested_amount'] = null;
        $mergedData['funding_urgency'] = null;
        $mergedData['purpose_of_funding'] = null;
        $mergedData['purpose_note'] = null;
        $mergedData['personal_credit_score'] = null;
        $mergedData['estimated_business_credit_score'] = null;
        $mergedData['existing_business_debt'] = false;
        $mergedData['debt_balance'] = null;
        $mergedData['notes'] = null;

        $mergedData['country'] = $borrower->country;
        $mergedData['country_code'] = $borrower->country_code;

        $dataObject = (object) $mergedData;

        return ApiResponse::success(
            new PreQualificationResource($dataObject),
            __('borrower.pre_qualification_request_success')
        );
    }

    /**
     * Get a specific pre-qualification application by ID
     */
    public function getPreQualificationById($id)
    {
        $user = Auth::user();
        $borrower = Borrower::where('id', $user->entity_id)->first();

        if (!$borrower) {
            throw new \Exception(__('borrower.borrower_not_found'));
        }

        // Get pre-qualification request that belongs to this borrower
        $preQualification = PreQualificationRequest::where('id', $id)
            ->where('borrower_id', $borrower->id)
            ->where('status', '!=', PreQualificationRequest::ON_HOLD)
            ->first();

        return $preQualification;
    }

    private function hasValue($value): bool
    {
        return !empty($value) || $value === false;
    }


    public function personalInfo(array $data)
    {
        try {
            $user = Auth::user();
            $requestedId = isset($data['id']) ? (int) $data['id'] : null;
            unset($data['id']);
            $payload = array_merge($data, [
                'status' => PreQualificationRequest::ON_HOLD,
            ]);

            return $this->saveOnHoldStep($payload, (int) $user->entity_id, $user->partner_id, $requestedId);
        } catch (\Exception $e) {
            throw $e;
        }

    }

    public function businessInfo(array $data)
    {
        try {
            $user = Auth::user();
            $borrower = Borrower::find($user->entity_id);
            $requestedId = isset($data['id']) ? (int) $data['id'] : null;
            unset($data['id']);
            $payload = array_merge($data, [
                'status' => PreQualificationRequest::ON_HOLD,
            ]);

            // business_start_date is derived from the borrower's own record, never
            // borrower-entered on this step (years_in_business above is the only
            // display field the wizard collects) — only set it when the borrower
            // actually has one, so we never overwrite an existing value with null.
            if ($borrower?->business_start_date) {
                $payload['business_start_date'] = $borrower->business_start_date;
            }

            return $this->saveOnHoldStep($payload, (int) $user->entity_id, $user->partner_id, $requestedId);
        } catch (\Exception $e) {
            throw $e;
        }

    }

    public function fundingInfo(array $data)
    {
        try {
            $user = Auth::user();
            $borrower = Borrower::find($user->entity_id);
            $requestedId = isset($data['id']) ? (int) $data['id'] : null;
            unset($data['id']);
            $payload = array_merge($data, [
                'status' => PreQualificationRequest::ON_HOLD,
                // Provenance of the credit score is recorded server-side, never sent by
                // the client: downstream underwriting must not treat a self-reported
                // score as verified.
                'credit_score_source' => $this->creditScoreSourceFor($borrower),
            ]);

            return $this->saveOnHoldStep($payload, (int) $user->entity_id, $user->partner_id, $requestedId);
        } catch (\Exception $e) {
            throw $e;
        }

    }

    /**
     * Save one wizard step's fields onto the borrower's single in-progress
     * (ON_HOLD) draft, creating it only if none exists yet.
     *
     * When the caller supplies an id it is honoured, but only ever narrowed
     * against this borrower's own in-progress draft — it can never be used to
     * reach another borrower's request or one that was already submitted. An id
     * that matches nothing is an error rather than a silent insert, so a stale
     * id (e.g. one that has since been submitted) can no longer create a
     * duplicate row instead of updating the intended one.
     *
     * With no id, the borrower's single ON_HOLD draft is matched by
     * borrower_id + status — the same invariant the GET endpoint relies on.
     * Note that a raw updateOrCreate on a null id is what originally caused
     * duplicates: it becomes `WHERE id IS NULL`, matches nothing, and inserts.
     */
    private function saveOnHoldStep(array $payload, int $borrowerId, ?int $partnerId, ?int $requestedId = null): PreQualificationRequest
    {
        $record = PreQualificationRequest::where('borrower_id', $borrowerId)
            ->where('status', PreQualificationRequest::ON_HOLD)
            ->when($requestedId, fn ($query) => $query->where('id', $requestedId))
            ->first();

        if ($record) {
            $record->update($payload);

            return $record;
        }

        // An id was named but no in-progress draft of this borrower matches it.
        // Creating a new row here is what produced the duplicate the caller was
        // trying to update, so this reports the mismatch instead.
        if ($requestedId) {
            throw new ModelNotFoundException(__('borrower.pre_qualification_request_not_found'));
        }

        // Seed the new draft with the borrower's profile data before applying this
        // step's fields. The wizard pre-fills every step from that same profile data
        // and skips the API call for a step the borrower never edited, so without
        // this seed those untouched (but displayed) values would never be persisted.
        $borrower = Borrower::find($borrowerId);
        $payload = array_merge($borrower ? $this->borrowerPrefill($borrower) : [], $payload);
        $payload['borrower_id'] = $borrowerId;
        $payload['partner_id'] = $partnerId;

        return PreQualificationRequest::create($payload);
    }

    /**
     * Where the credit score on this pre-qualification came from. A borrower with a
     * credit profile on file has a score corroborated by a pulled report; otherwise
     * the figure is whatever they selected in the wizard.
     */
    /**
     * The provenance of pre_qualification_requests.personal_credit_score.
     *
     * That column only ever holds the band the borrower picks in the wizard
     * ("Good (670-739)"); it is explicitly nulled out of every prefill and is
     * never populated from a bureau pull. So its source is borrower-reported,
     * always.
     *
     * This used to answer with the borrower's credit profile instead — labelling
     * the band "Verified Credit Profile" whenever a fico_score row happened to
     * exist, which described a different number than the one being stored. The
     * Internal AUE ranks verified data above borrower-reported data, so the
     * mislabel promoted a self-selected band to verified evidence. The bureau
     * score still reaches underwriting, but as its own field from its own
     * source (see PreQualificationInternalAueService::buildBorrowerPayload).
     */
    private function creditScoreSourceFor(?Borrower $borrower): string
    {
        return 'Borrower-Reported';
    }

    /**
     * The borrower-profile-derived defaults used to pre-fill a new pre-qualification,
     * shared by the read endpoint and by draft creation so both stay in sync.
     */
    private function borrowerPrefill(Borrower $borrower): array
    {
        return [
            // Personal Info
            'first_name' => $borrower->first_name,
            'last_name' => $borrower->last_name,
            'email' => $borrower->email,
            'phone_number' => $borrower->phone_number,
            'business_website' => $borrower->business_website,
            'state_of_incorporation' => $borrower->state,

            // Business Info
            'business_name' => $borrower->business_name,
            'industry_type' => $borrower->business_industry_type,
            'business_structure' => $borrower->business_entity_type,
            'business_start_date' => $borrower->business_start_date,
            'years_in_business' => $borrower->business_start_date
                ? calculateTimeInBusiness($borrower->business_start_date)
                : ($borrower->years_in_business ? $borrower->years_in_business . ' Years' : null),
            'monthly_revenue' => $borrower->monthly_revenue,
            'annual_revenue' => $borrower->annual_revenue,

            // Financial Info
            'funding_requested_amount' => null,
            'funding_urgency' => null,
            'purpose_of_funding' => null,
            'purpose_note' => null,
            'personal_credit_score' => null,
            'estimated_business_credit_score' => null,
            'credit_score_source' => $this->creditScoreSourceFor($borrower),
            'existing_business_debt' => false,
            'debt_balance' => null,

            //others
            'notes' => null,
            'status' => PreQualificationRequest::ON_HOLD,
            'gpt_status' => null,
            'gpt_generated_at' => null,
            'borrower_id' => $borrower->id,
            'partner_id' => $borrower->partner_id,
        ];
    }

    private function mappingData()
    {
        return [
            // Personal Info
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'email' => 'email',
            'phone_number' => 'phone_number',
            'business_website' => 'business_website',
            'state_of_incorporation' => 'state',

            // Business Info
            'business_name' => 'business_name',
            'industry_type' => 'business_industry_type',
            'business_structure' => 'business_entity_type',
            'years_in_business' => 'years_in_business',
            'monthly_revenue' => 'monthly_revenue',
            'annual_revenue' => 'annual_revenue',
        ];
    }
}