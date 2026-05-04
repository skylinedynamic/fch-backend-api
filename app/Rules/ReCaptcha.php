<?php

namespace App\Rules;

use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
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
        // Get from DB first, then fallback to config/env
        $dbSecret = AppConfiguration::get('recaptcha_secret_key', null, Partner::class, 1);
        $secretKey = !empty($dbSecret) ? $dbSecret : config('captcha.secret');
        
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