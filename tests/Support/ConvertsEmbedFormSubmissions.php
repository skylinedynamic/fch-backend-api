<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Marks a funding application as converted from an embed form submission
 * (embedded_form_submissions.converted_funding_application_id), as
 * EmbedFormService::convert() leaves it.
 */
trait ConvertsEmbedFormSubmissions
{
    protected function markConvertedFromEmbedForm(int $applicationId): void
    {
        $application = DB::table('funding_applications')->where('id', $applicationId)->first();

        DB::table('embedded_form_submissions')->insert([
            'submission_uid' => (string) Str::uuid(),
            'partner_id' => $application->partner_id,
            'first_name' => 'Jimmy',
            'last_name' => 'Thornton',
            'email' => 'embed-'.uniqid().'@example.com',
            'status' => 'converted',
            'converted_borrower_id' => $application->borrower_id,
            'converted_funding_application_id' => $applicationId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
