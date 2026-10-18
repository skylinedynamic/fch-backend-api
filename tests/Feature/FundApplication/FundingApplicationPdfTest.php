<?php

namespace Tests\Feature\FundApplication;

use App\Models\Borrower\Borrower;
use App\Models\Borrower\FundingApplication;
use App\Models\Common\AppConfiguration;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\Application\FundingApplicationDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The funding application PDF and the electronic signature certificate.
 *
 * Both carry the host admin's logo and company name, whichever partner the
 * application belongs to - white-label included. The application PDF shows the
 * full SSN, the requested amount in its currency and the additional funding
 * types.
 */
class FundingApplicationPdfTest extends TestCase
{
    use RefreshDatabase;

    private static int $versionSeed = 700000;

    private Partner $host;

    protected function setUp(): void
    {
        parent::setUp();

        // Branding is memoised per process; each test gets its own version band.
        Cache::forever('branding:version', self::$versionSeed += 1000);

        $this->host = $this->partner(['is_host' => true, 'partner_type' => Partner::PARTNER_TYPE_CERTIFIED], 'FCH Host Lending');
    }

    private function partner(array $attributes, string $siteName): Partner
    {
        $partner = Partner::create(array_merge([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => $siteName,
            'full_name' => $siteName,
            'email' => uniqid('partner-') . '@example.test',
        ], $attributes));

        AppConfiguration::create([
            'partner_id' => $partner->id,
            'module' => AppConfiguration::MODULE_BRANDING,
            'key' => 'site_name',
            'value' => $siteName,
        ]);

        return $partner;
    }

    private function application(Partner $partner, array $attributes = [], array $borrower = []): FundingApplication
    {
        $borrowerId = Borrower::create(array_merge([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Odessa',
            'last_name' => 'Mccall',
            'email' => uniqid('odessa-') . '@example.test',
        ], $borrower))->id;

        $id = DB::table('funding_applications')->insertGetId(array_merge([
            'application_number' => 'FUND-PDF-' . uniqid(),
            'borrower_id' => $borrowerId,
            'partner_id' => $partner->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return FundingApplication::findOrFail($id);
    }

    private function pdfHtml(FundingApplication $application): string
    {
        return view('pdf.funding-application', array_merge(
            ['application' => $application],
            FundingApplicationDocumentService::getBrandingData($application)
        ))->render();
    }

    // -- Branding -------------------------------------------------------------

    #[Test]
    public function a_white_label_partners_documents_carry_the_hosts_name_and_logo(): void
    {
        $whiteLabel = $this->partner(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL], 'Acme White Label');
        $hostBranding = FundingApplicationDocumentService::getBrandingData($this->application($this->host));

        $branding = FundingApplicationDocumentService::getBrandingData($this->application($whiteLabel));

        $this->assertSame('FCH Host Lending', $branding['companyName']);
        $this->assertNotSame('', $branding['logoBase64']);
        $this->assertSame($hostBranding['logoBase64'], $branding['logoBase64']);
    }

    #[Test]
    public function a_certified_partners_documents_carry_the_hosts_name_and_logo(): void
    {
        $certified = $this->partner(['partner_type' => Partner::PARTNER_TYPE_CERTIFIED], 'Certified Co');
        $hostBranding = FundingApplicationDocumentService::getBrandingData($this->application($this->host));

        $branding = FundingApplicationDocumentService::getBrandingData($this->application($certified));

        $this->assertSame('FCH Host Lending', $branding['companyName']);
        $this->assertSame($hostBranding['logoBase64'], $branding['logoBase64']);
    }

    #[Test]
    public function the_pdf_names_the_host_not_the_white_label_partner(): void
    {
        $whiteLabel = $this->partner(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL], 'Acme White Label');

        $html = $this->pdfHtml($this->application($whiteLabel));

        $this->assertStringContainsString('FCH Host Lending', $html);
        $this->assertStringNotContainsString('Acme White Label', $html);
    }

    #[Test]
    public function the_signature_certificate_names_the_host_not_the_white_label_partner(): void
    {
        $whiteLabel = $this->partner(['partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL], 'Acme White Label');
        $application = $this->application($whiteLabel);
        $branding = FundingApplicationDocumentService::getBrandingData($application);
        $signature = ['signature_name' => 'Odessa Mccall', 'signed_at' => now()->toIso8601String()];

        $html = view('pdf.signature-certificate', array_merge(
            $branding,
            \App\Services\FCH\Common\Application\SignatureCertificateService::getTemplateData($application, $branding, $signature)
        ))->render();

        $this->assertStringContainsString('FCH Host Lending', $html);
        $this->assertStringNotContainsString('Acme White Label', $html);
    }

    #[Test]
    public function labels_and_values_share_one_font(): void
    {
        // dompdf has no 500 weight: a "medium" value fell back to a serif face,
        // so labels and values came out in two different fonts.
        $application = $this->application($this->host);
        $branding = FundingApplicationDocumentService::getBrandingData($application);
        $certificate = view('pdf.signature-certificate', array_merge(
            $branding,
            \App\Services\FCH\Common\Application\SignatureCertificateService::getTemplateData($application, $branding, ['signature_name' => 'Odessa Mccall'])
        ))->render();

        foreach ([$this->pdfHtml($application), $certificate] as $html) {
            $this->assertStringContainsString("font-family: 'DejaVu Sans', sans-serif;", $html);
            $this->assertStringNotContainsString('font-weight: 500', $html);
            $this->assertStringNotContainsString('Times New Roman', $html);
            $this->assertStringNotContainsString('Helvetica', $html);
        }
    }

    #[Test]
    public function dates_are_shown_without_a_time_except_the_moment_of_signing(): void
    {
        // Every date is a date - except on the certificate, which gives the
        // exact moment of signing with its timezone.
        $application = $this->application($this->host, ['date_of_birth' => '1986-03-14']);
        $branding = FundingApplicationDocumentService::getBrandingData($application);
        $certificate = strip_tags(view('pdf.signature-certificate', array_merge(
            $branding,
            \App\Services\FCH\Common\Application\SignatureCertificateService::getTemplateData($application, $branding, [
                'signature_name' => 'Odessa Mccall',
                'signed_at' => '2026-10-16T09:41:07Z',
            ])
        ))->render());

        $applicationText = strip_tags($this->pdfHtml($application));
        $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $applicationText);
        $this->assertStringNotContainsString(' AM', $applicationText);
        $this->assertStringNotContainsString(' PM', $applicationText);

        // The certificate's only times are the signing moment.
        preg_match_all('/\d{1,2}:\d{2}:\d{2}/', $certificate, $times);
        $this->assertSame(['9:41:07'], array_values(array_unique($times[0])));
        $this->assertStringContainsString('Oct 16, 2026 9:41:07 AM UTC', $certificate);
    }

    // -- Application fields ---------------------------------------------------

    #[Test]
    public function internal_and_duplicate_fields_are_left_out(): void
    {
        $text = strip_tags($this->pdfHtml($this->application($this->host)));

        foreach ([
            'Years in Business', 'Minimum Time in Business', 'Minimum Revenue', 'Minimum Deposit',
            'Minimum Average Daily Balance', 'Uses', 'Funding Sequence Name', 'Terms',
            'Estimated Speed', 'Estimated Amount',
        ] as $label) {
            $this->assertDoesNotMatchRegularExpression('/\b' . preg_quote($label, '/') . '\b/', $text, "{$label} should not be shown");
        }
        // Shown once, under Financial Details - not again under Business Information.
        $this->assertSame(1, substr_count($text, 'Personal Credit Score'));
    }

    #[Test]
    public function time_in_business_is_worked_out_from_the_business_start_date(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-16');
        $application = $this->application($this->host, [], ['business_start_date' => '2019-10-02']);

        $text = strip_tags($this->pdfHtml($application));
        // Worked out while the clock is still frozen, as the PDF was - otherwise
        // the expected value moves on with the real date and the test only
        // passes on the day it was written.
        $expected = calculateTimeInBusiness('2019-10-02');
        \Illuminate\Support\Carbon::setTestNow();

        $this->assertStringContainsString('Time in Business', $text);
        $this->assertStringContainsString($expected, $text);
    }

    #[Test]
    public function the_entity_type_and_ownership_percentage_are_shown(): void
    {
        $text = strip_tags($this->pdfHtml($this->application($this->host, [
            'entity_type' => 'C-Corporation',
            'ownership' => 75,
        ])));

        $this->assertStringContainsString('Entity Type', $text);
        $this->assertStringContainsString('C-Corporation', $text);
        $this->assertStringContainsString('Ownership Percentage', $text);
        $this->assertStringContainsString('75%', $text);
    }

    #[Test]
    public function both_documents_carry_the_hosts_logo_as_a_watermark(): void
    {
        $application = $this->application($this->host);
        $branding = FundingApplicationDocumentService::getBrandingData($application);
        $certificate = view('pdf.signature-certificate', array_merge(
            $branding,
            \App\Services\FCH\Common\Application\SignatureCertificateService::getTemplateData($application, $branding, ['signature_name' => 'Odessa Mccall'])
        ))->render();

        foreach ([$this->pdfHtml($application), $certificate] as $html) {
            $this->assertStringContainsString('<div class="watermark">', $html);
            // Once in the header, once behind the page.
            $this->assertSame(2, substr_count($html, 'src="' . $branding['logoBase64'] . '"'));
        }
    }

    #[Test]
    public function the_certificate_no_longer_names_four_corner_funding_outright(): void
    {
        // The credit authorisation names the host from its branding, like the rest.
        $application = $this->application($this->host);
        $branding = FundingApplicationDocumentService::getBrandingData($application);

        $html = view('pdf.signature-certificate', array_merge(
            $branding,
            \App\Services\FCH\Common\Application\SignatureCertificateService::getTemplateData($application, $branding, ['signature_name' => 'Odessa Mccall'])
        ))->render();

        $this->assertStringContainsString('I authorize FCH Host Lending to obtain', $html);
    }

    #[Test]
    public function the_application_pdf_still_builds(): void
    {
        $application = $this->application($this->host, [
            'ssn' => '123456789',
            'requested_amount' => 150000,
            'currency' => 'EUR',
            'currency_symbol' => '€',
            'additional_funding_types' => json_encode(['SBA Loan']),
        ]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.funding-application', array_merge(
            ['application' => $application],
            FundingApplicationDocumentService::getBrandingData($application)
        ))->output();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    // -- Additional funding types ----------------------------------------------

    #[Test]
    public function the_additional_funding_types_are_written_out_in_the_pdf_itself(): void
    {
        // Read back from the rendered PDF, not the HTML: what the download holds.
        $application = $this->application($this->host, [
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => json_encode(['SBA Loan', 'Hard Money / Bridge Loan', 'Invoice Factoring']),
        ]);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.funding-application', array_merge(
            ['application' => $application],
            FundingApplicationDocumentService::getBrandingData($application)
        ))->output();
        $text = preg_replace('/\s+/', ' ', (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText());

        $this->assertStringContainsString('Additional Funding Types SBA Loan, Hard Money / Bridge Loan, Invoice Factoring', $text);
    }

    #[Test]
    public function no_additional_funding_types_reads_as_not_applicable(): void
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($this->pdfHtml($this->application($this->host, [
            'additional_funding_types' => null,
        ]))));

        $this->assertStringContainsString('Additional Funding Types N/A', $text);
    }

    // -- SSN ------------------------------------------------------------------

    #[Test]
    public function the_full_ssn_is_shown(): void
    {
        $html = $this->pdfHtml($this->application($this->host, ['ssn' => '123456789']));

        $this->assertStringContainsString('123-45-6789', $html);
        $this->assertStringNotContainsString('Last 4 SSN', $html);
    }

    #[Test]
    public function the_borrowers_full_ssn_is_used_when_the_application_has_none(): void
    {
        $html = $this->pdfHtml($this->application($this->host, [], ['ssn' => '987654321']));

        $this->assertStringContainsString('987-65-4321', $html);
    }

    #[Test]
    public function only_the_last_four_on_file_is_shown_as_the_last_four(): void
    {
        // Older accounts hold just the last four: there is no full SSN to show.
        $html = $this->pdfHtml($this->application($this->host, [], ['last_4_ssn' => '6789']));

        $this->assertStringContainsString('***-**-6789', $html);
    }

    // -- Funding request ------------------------------------------------------

    #[Test]
    public function the_requested_amount_is_shown_in_its_currency(): void
    {
        $html = $this->pdfHtml($this->application($this->host, [
            'requested_amount' => 150000,
            'currency' => 'GBP',
            'currency_symbol' => '£',
        ]));

        $this->assertStringContainsString('Requested Amount', $html);
        $this->assertStringContainsString('£150,000.00', $html);
    }

    #[Test]
    public function the_requested_amount_defaults_to_dollars(): void
    {
        $html = $this->pdfHtml($this->application($this->host, ['requested_amount' => 25000]));

        $this->assertStringContainsString('$25,000.00', $html);
    }

    #[Test]
    public function the_preferred_and_additional_funding_types_are_shown(): void
    {
        $html = $this->pdfHtml($this->application($this->host, [
            'preferred_funding_type' => 'Term Loan',
            'additional_funding_types' => json_encode(['SBA Loan', 'Line of Credit']),
        ]));

        $this->assertStringContainsString('Term Loan', $html);
        $this->assertStringContainsString('Additional Funding Types', $html);
        $this->assertStringContainsString('SBA Loan, Line of Credit', $html);
    }
}
