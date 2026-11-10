<?php

namespace App\Rules;

use App\Models\Common\AppConfiguration;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReCaptcha implements ValidationRule
{
    /**
     * @param string|null $action  For a reCAPTCHA v3 token: the action the page ran it
     *                             with. When set, Google's action must match and its
     *                             score reach $minScore - v3 always says "success".
     * @param bool $skipWhenUnconfigured  Let the value through when no secret is set
     *                             anywhere, instead of failing - for a public form
     *                             that must keep working before reCAPTCHA is set up.
     */
    public function __construct(
        private ?string $action = null,
        private float $minScore = 0.5,
        private bool $skipWhenUnconfigured = false,
    ) {
    }

    /**
     * The secret saved in Site Settings (module website, the host's row) - the
     * only source. It used to be read as module Partner for partner id 1, where
     * Site Settings never saves it (findings O29), and then fell back to
     * RECAPTCHA_SECRET_KEY in .env, a second copy that was also committed to
     * .env.example (O57). Null when Site Settings has none: reCAPTCHA is off.
     */
    public static function secret(): ?string
    {
        $saved = AppConfiguration::platform('recaptcha_secret_key', AppConfiguration::MODULE_WEBSITE);

        return !empty($saved) ? (string) $saved : null;
    }

    /**
     * Whether reCAPTCHA is set up: Site Settings holds both the secret and the
     * site key. With only one it is off - the website makes a token only with
     * the site key, and only the secret can check it, so demanding a token from
     * a half-filled Site Settings would turn every lead away.
     */
    public static function isConfigured(): bool
    {
        $siteKey = AppConfiguration::platform('recaptcha_site_key', AppConfiguration::MODULE_WEBSITE);

        return self::secret() !== null && !empty($siteKey);
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $secretKey = self::secret();

        if ($this->skipWhenUnconfigured && !self::isConfigured()) {
            Log::warning('reCAPTCHA not checked: Site Settings does not hold both the site key and the secret.');
            return;
        }

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

        $result = $response->json() ?? [];

        if (!($result['success'] ?? false)) {
            $fail(__('auth.recaptcha_verification_failed'));
            return;
        }

        if ($this->action !== null
            && (($result['action'] ?? null) !== $this->action || (float) ($result['score'] ?? 0) < $this->minScore)) {
            $fail(__('auth.recaptcha_verification_failed'));
        }
    }
}
