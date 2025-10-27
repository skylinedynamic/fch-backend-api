<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;

class ReCaptcha implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secretKey = config('captcha.secret');
        
        if (empty($secretKey)) {
            $fail(__('auth.recaptcha_secret_not_configured'));
            return;
        }

        if (empty($value)) {
            $fail(__('auth.recaptcha_required'));
            return;
        }

        // For testing with Google's test keys, always pass
        if ($secretKey === '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe') {
            return;
        }

        $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
            'secret' => $secretKey,
            'response' => $value,
            'remoteip' => request()->ip(),
        ]);

        $result = $response->json();

        if (!$result['success']) {
            $fail(__('auth.recaptcha_verification_failed'));
        }
    }
}