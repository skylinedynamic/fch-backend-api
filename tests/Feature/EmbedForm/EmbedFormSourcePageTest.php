<?php

namespace Tests\Feature\EmbedForm;

use App\Http\Requests\Api\V1\Global\Website\EmbedForm\SubmitEmbedFormRequest;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\EmbedForm\EmbedFormSubmission;
use App\Models\Partner\Partner;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defect (SCRUM-435 S435-BUG-05): origin_url held the submit request's Referer.
 * The form sends that request itself, so it named the form's own address for
 * every application, and one from the website's /apply page looked the same as
 * one from the direct link. It now holds the page the form was framed on.
 */
class EmbedFormSourcePageTest extends TestCase
{
    use RefreshDatabase;

    private EmbedFormConfig $config;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Notification::fake();

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $this->config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);
    }

    /** What storeSubmission() saves for these validated values and this Referer. */
    private function stored(array $data, string $referer = 'https://example.test/'): EmbedFormSubmission
    {
        $request = Request::create('/api/v1/embed/submit/key', 'POST', server: ['HTTP_REFERER' => $referer]);

        return app(EmbedFormService::class)->storeSubmission($this->config, $data + [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ], $request);
    }

    /** The value the request hands on after its own clean-up and rules. */
    private function validatedOriginPage(mixed $value): array
    {
        $request = SubmitEmbedFormRequest::create('/', 'POST', ['origin_page' => $value]);
        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->invoke($request);

        $rules = array_intersect_key($request->rules(), ['origin_page' => true]);
        $this->assertArrayHasKey('origin_page', $rules);

        return Validator::make($request->only('origin_page'), $rules)->validate();
    }

    #[Test]
    public function the_page_the_form_was_framed_on_is_stored_as_the_source(): void
    {
        $submission = $this->stored(['origin_page' => 'https://example.test/apply']);

        $this->assertSame('https://example.test/apply', $submission->origin_url);
    }

    #[Test]
    public function a_direct_link_submission_has_no_source_rather_than_the_forms_own_address(): void
    {
        $this->assertNull($this->stored([])->origin_url);
    }

    #[Test]
    public function an_apply_page_submission_can_be_told_apart_from_a_direct_link_one(): void
    {
        $fromApply = $this->stored(['origin_page' => 'https://example.test/apply']);
        $direct = $this->stored([]);

        $this->assertNotSame($fromApply->origin_url, $direct->origin_url);
    }

    #[Test]
    public function the_request_keeps_only_the_address_of_the_page(): void
    {
        $this->assertSame(
            ['origin_page' => 'https://example.test/apply'],
            $this->validatedOriginPage('https://example.test/apply?email=a%40b.test'),
        );
    }

    #[Test]
    public function a_bad_source_is_dropped_and_never_refuses_the_application(): void
    {
        foreach (['javascript:alert(1)', 'not a url', 'https://example.test/' . str_repeat('a', 5000)] as $value) {
            $this->assertNull($this->validatedOriginPage($value)['origin_page'] ?? null);
        }
    }

    #[Test]
    public function the_form_sends_the_framing_page_with_its_submission_only_when_framed(): void
    {
        $page = $this->get('/form/' . $this->config->public_key)->assertOk()->getContent();

        preg_match('/function framingPage\(\) \{(.*?)\n        \}/s', $page, $m);
        $this->assertNotEmpty($m, 'framingPage() not found');
        $this->assertStringContainsString('window.parent === window', $m[1]);
        $this->assertStringContainsString('document.referrer', $m[1]);

        preg_match("/addEventListener\('submit', async function \(e\) \{(.*?)const res = await fetch/s", $page, $handler);
        $this->assertStringContainsString("formData.set('origin_page', source)", $handler[1]);
    }
}
