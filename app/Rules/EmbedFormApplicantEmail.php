<?php

namespace App\Rules;

use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An applicant's email may be brand new, or belong to an existing Borrower
 * (who is then given another funding application). An address already held by a
 * Partner, Employee or plain User is refused: that account is not a client.
 */
class EmbedFormApplicantEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $match = app(EmbedFormService::class)->classifyEmail((string) $value);

        if ($match['status'] === EmbedFormService::EMAIL_BLOCKED) {
            $fail(__('embed_form.email_already_registered'));
        }
    }
}
