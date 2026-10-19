<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use App\Rules\EmbedFormApplicantEmail;
use App\Services\FCH\Common\EmbedForm\EmbedFormService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Which emails the embedded form accepts and which can be converted.
 *
 * A free address, or one that already belongs to a Borrower, is fine; one held
 * by a Partner, Employee or plain User is refused.
 */
class EmbedFormEmailEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string $email, ?string $entityType, string $userType): void
    {
        DB::table('users')->insert([
            'name' => 'Existing',
            'email' => $email,
            'password' => bcrypt('secret'),
            'user_type' => $userType,
            'entity_type' => $entityType,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function unknown_email_is_available(): void
    {
        $match = app(EmbedFormService::class)->classifyEmail('new@example.test');

        $this->assertSame(EmbedFormService::EMAIL_AVAILABLE, $match['status']);
    }

    #[Test]
    public function staff_and_plain_user_emails_are_blocked(): void
    {
        $this->userWith('partner@example.test', Partner::class, User::PARTNER);
        $this->userWith('plain@example.test', null, User::USER);

        $service = app(EmbedFormService::class);

        $this->assertSame(EmbedFormService::EMAIL_BLOCKED, $service->classifyEmail('partner@example.test')['status']);
        $this->assertSame(EmbedFormService::EMAIL_BLOCKED, $service->classifyEmail('plain@example.test')['status']);
    }

    #[Test]
    public function borrower_user_email_is_recognised_not_blocked(): void
    {
        $this->userWith('borrower@example.test', Borrower::class, User::BORROWER);

        $this->assertSame(
            EmbedFormService::EMAIL_BORROWER,
            app(EmbedFormService::class)->classifyEmail('borrower@example.test')['status']
        );
    }

    #[Test]
    public function validation_rule_only_rejects_blocked_emails(): void
    {
        $this->userWith('partner@example.test', Partner::class, User::PARTNER);
        $this->userWith('borrower@example.test', Borrower::class, User::BORROWER);

        $check = fn (string $email) => Validator::make(['email' => $email], ['email' => [new EmbedFormApplicantEmail()]])->passes();

        $this->assertFalse($check('partner@example.test'));
        $this->assertTrue($check('borrower@example.test'));
        $this->assertTrue($check('fresh@example.test'));
    }
    // -- A borrower's email works only on their own partner's form ------------

    private function partner(string $name): Partner
    {
        return Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => $name,
            'full_name' => $name,
            'email' => strtolower(str_replace(' ', '', $name)) . '@example.test',
        ]);
    }

    private function borrowerOf(Partner $partner, string $email): Borrower
    {
        return Borrower::create([
            'partner_id' => $partner->id,
            'status' => Borrower::STATUS_ACTIVE,
            'first_name' => 'Ava',
            'last_name' => 'Ortiz',
            'email' => $email,
        ]);
    }

    private function passesFor(Partner $formPartner, string $email): bool
    {
        return Validator::make(['email' => $email], ['email' => [new EmbedFormApplicantEmail($formPartner->id)]])->passes();
    }

    #[Test]
    public function a_borrower_of_the_forms_partner_may_use_the_form(): void
    {
        $partner = $this->partner('Acme Lending');
        $this->borrowerOf($partner, 'ava@example.test');

        $this->assertTrue($this->passesFor($partner, 'ava@example.test'));
    }

    #[Test]
    public function a_borrower_of_another_partner_may_not(): void
    {
        $this->borrowerOf($this->partner('Other Lending'), 'ava@example.test');
        $formPartner = $this->partner('Acme Lending');

        $validator = Validator::make(['email' => 'ava@example.test'], ['email' => [new EmbedFormApplicantEmail($formPartner->id)]]);

        $this->assertTrue($validator->fails());
        $this->assertSame(__('embed_form.email_registered_with_other_partner'), $validator->errors()->first('email'));
    }

    #[Test]
    public function a_new_email_is_still_free_to_use(): void
    {
        $this->assertTrue($this->passesFor($this->partner('Acme Lending'), 'brand-new@example.test'));
    }

    #[Test]
    public function the_public_form_refuses_another_partners_borrower_at_the_ownership_step(): void
    {
        $this->borrowerOf($this->partner('Other Lending'), 'ava@example.test');
        $formPartner = $this->partner('Acme Lending');
        $config = \App\Models\EmbedForm\EmbedFormConfig::create([
            'partner_id' => $formPartner->id,
            'public_key' => \App\Models\EmbedForm\EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        $this->postJson("/api/v1/embed/validate-step/{$config->public_key}/2", ['email' => 'ava@example.test'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email', __('embed_form.email_registered_with_other_partner'));
    }
    #[Test]
    public function each_request_checks_against_its_own_forms_partner(): void
    {
        // A long-lived worker (Octane) serves many requests: the form looked up
        // for one must never be reused for the next.
        $acme = $this->partner('Acme Lending');
        $other = $this->partner('Other Lending');
        $this->borrowerOf($acme, 'ava@example.test');
        $form = fn (Partner $partner) => \App\Models\EmbedForm\EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => \App\Models\EmbedForm\EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);
        $acmeForm = $form($acme);
        $otherForm = $form($other);

        $this->postJson("/api/v1/embed/validate-step/{$acmeForm->public_key}/2", ['email' => 'ava@example.test'])
            ->assertJsonMissingPath('errors.email');
        $this->postJson("/api/v1/embed/validate-step/{$otherForm->public_key}/2", ['email' => 'ava@example.test'])
            ->assertJsonPath('errors.email', __('embed_form.email_registered_with_other_partner'));
    }
}
