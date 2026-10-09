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
}
