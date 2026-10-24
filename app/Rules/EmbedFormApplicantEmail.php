<?php

namespace App\Rules;

use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An applicant's email may be brand new, or belong to an existing Borrower of
 * the form's own partner (who is then given another funding application). An
 * address held by a Partner, Employee or plain User is refused - that account is
 * not a client - and so is one belonging to another partner's borrower: that
 * client is not this partner's to take an application from.
 *
 * The same partner check runs again at conversion (EmbedFormService::
 * convertToBorrower), for submissions taken before it existed.
 */
class EmbedFormApplicantEmail implements ValidationRule
{
    /**
     * @param  int|null  $partnerId  the form's partner; null skips the partner check
     */
    public function __construct(private ?int $partnerId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $service = app(EmbedFormService::class);
        $match = $service->classifyEmail((string) $value);

        if ($match['status'] === EmbedFormService::EMAIL_BLOCKED) {
            $fail(__('embed_form.email_already_registered'));

            return;
        }

        if ($this->partnerId !== null && $service->belongsToAnotherPartner($match['borrower'], $this->partnerId)) {
            $fail(__('embed_form.email_registered_with_other_partner'));
        }
    }
}
