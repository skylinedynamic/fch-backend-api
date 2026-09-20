<?php

namespace App\Http\Resources\FCH\Common\Application;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\PreQualificationGptResult;
use App\Models\Borrower\PreQualificationRequest;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\PreQualificationAiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use Carbon\Carbon;

class PreQualificationRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $timezone = $request->header('X-Timezone') ?? config('app.timezone');

        $borrower = ($this->resource instanceof \Illuminate\Database\Eloquent\Model && $this->resource->relationLoaded('borrower'))
            ? $this->borrower 
            : (!empty($this->borrower_id) ? Borrower::find($this->borrower_id) : (auth()->check() && auth()->user()->entity_id ? Borrower::find(auth()->user()->entity_id) : null));

        $startDate = $this->business_start_date ?? $borrower?->business_start_date ?? null;
        $yearsInBusiness = !empty($startDate) 
            ? calculateTimeInBusiness($startDate) 
            : ($this->years_in_business ?? ($borrower?->years_in_business ? $borrower->years_in_business . ' Years' : null));

        $latestInternalAue = PreQualificationGptResult::query()
            ->forApplication((int) $this->id)
            ->internal()
            ->latest('id')
            ->first();

        $latestClientAue = PreQualificationGptResult::query()
            ->forApplication((int) $this->id)
            ->client()
            ->latest('id')
            ->first();

        $aiResult = PreQualificationAiService::fromStoredRows(
            $latestInternalAue,
            $latestClientAue,
            $this->resource instanceof PreQualificationRequest ? $this->resource : null
        );

        // Access & Visibility Rules:
        // - Host Admin (is_host == 1): Can see both internal and client AUE.
        // - White-Label Partner (partner_type == Partner::PARTNER_TYPE_WHITE_LABEL): Can see Client_AUE ONLY.
        // - Certified Partner (partner_type == Partner::PARTNER_TYPE_CERTIFIED) or non-WLP: CANNOT see any AUE.
        $user = auth()->user();
        $partner = $user?->partner;
        $isHost = (bool) ($partner?->is_host ?? false);
        $isWhiteLabel = ($partner?->partner_type === Partner::PARTNER_TYPE_WHITE_LABEL);

        if (!$isHost) {
            if (!$isWhiteLabel) {
                // Certified partners / non-WLP partners must NEVER see any underwriting output
                $aiResult = null;
            } elseif ($aiResult) {
                // Strictly strip internal evaluation and internal-only metadata for WLP
                unset($aiResult['internal']);
                unset($aiResult['admin_status_note']);
                unset($aiResult['model']);
                unset($aiResult['latency_ms']);
            }
        }

        return [
            'id' => $this->id,
            'borrower_id' => $this->borrower_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'business_website' => $this->business_website,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'state_of_incorporation' => $this->state_of_incorporation,
            'business_name' => $this->business_name,
            'industry_type' => $this->industry_type,
            'business_structure' => $this->business_structure,
            'monthly_revenue' => currencyFormat($this->monthly_revenue),
            'annual_revenue' => currencyFormat($this->annual_revenue),
            'years_in_business' => $yearsInBusiness,
            'funding_requested_amount' => currencyFormat($this->funding_requested_amount),
            'funding_urgency' => $this->funding_urgency,
            'purpose_of_funding' => $this->purpose_of_funding,
            'purpose_note' => $this->purpose_note,
            'personal_credit_score' => $this->personal_credit_score,
            'estimated_business_credit_score' => $this->estimated_business_credit_score,
            'existing_business_debt' => $this->existing_business_debt,
            'debt_balance' => currencyFormat($this->debt_balance),
            'notes' => $this->notes,
            'status' => $this->status,
            'status_note' => $this->status_note,
            'status_class' => $this->status_class,
            // Validator findings carry internal_message / suggested_action written
            // for FCH staff, so they follow the same host-only rule as the Internal AUE.
            'validation_status' => ($isHost || $isWhiteLabel) ? $this->validation_status : null,
            'validation_summary' => $isHost ? $this->validation_summary : null,
            'validation_issues' => $isHost ? $this->validation_issues : null,
            'validated_at' => ($isHost && $this->validated_at) ? toTimezone($this->validated_at, null, 'Y-m-d h:i A') : null,
            'gpt_status' => ($isHost || $isWhiteLabel) ? $this->gpt_status : null,
            'gpt_generated_at' => (($isHost || $isWhiteLabel) && !empty($this->gpt_generated_at)) ? Carbon::parse($this->gpt_generated_at)->format('Y-m-d h:i A') : null,
            'ai_result' => $aiResult,
            'created_at' => toTimezone($this->created_at, null, 'Y-m-d H:i:s'),
            'updated_at' => toTimezone($this->updated_at, null, 'Y-m-d H:i:s'),
        ];
    }
}
