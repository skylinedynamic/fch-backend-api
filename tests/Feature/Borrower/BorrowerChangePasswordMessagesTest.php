<?php

namespace Tests\Feature\Borrower;

use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-139: a wrong current password on the client portal's Change Password
 * showed the raw key "borrower_validation.current_password_incorrect". None of
 * the password messages the borrower API uses were in lang/en/borrower_validation.php,
 * so every one came back as its key. They now read as messages, worded as the
 * admin portal's (lang/en/profile.php).
 */
class BorrowerChangePasswordMessagesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    private const URL = '/api/v1/borrower/auth-profile/update-password';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->makeHostPartner();
        $this->actingAsUser($this->makeBorrowerUser($this->makeWhiteLabelPartner(), [], ['password' => Hash::make('Old-pass1!')]));
    }

    #[Test]
    public function a_wrong_current_password_says_so_in_words(): void
    {
        $this->postJson(self::URL, [
            'current_password' => 'Wrong-pass1!',
            'new_password' => 'QaDummyPw#2026',
            'new_password_confirmation' => 'QaDummyPw#2026',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password' => 'Current password does not match our records.']);
    }

    #[Test]
    public function a_changed_password_says_so_in_words(): void
    {
        $this->postJson(self::URL, [
            'current_password' => 'Old-pass1!',
            'new_password' => 'QaDummyPw#2026',
            'new_password_confirmation' => 'QaDummyPw#2026',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Password updated successfully.');
    }

    public static function brokenRules(): array
    {
        return [
            'no current password' => [['current_password' => ''], 'current_password', 'Current password is required.'],
            'no new password' => [['new_password' => '', 'new_password_confirmation' => ''], 'new_password', 'New password is required.'],
            'too short' => [['new_password' => 'Aa1!', 'new_password_confirmation' => 'Aa1!'], 'new_password', 'New password must be at least 8 characters long.'],
            'one case only' => [['new_password' => 'qadummypw#2026', 'new_password_confirmation' => 'qadummypw#2026'], 'new_password', 'New password must include both uppercase and lowercase letters.'],
            'no number' => [['new_password' => 'QaDummyPw#', 'new_password_confirmation' => 'QaDummyPw#'], 'new_password', 'New password must include at least one number.'],
            'no symbol' => [['new_password' => 'QaDummyPw2026', 'new_password_confirmation' => 'QaDummyPw2026'], 'new_password', 'New password must include at least one special character.'],
            'confirmation differs' => [['new_password_confirmation' => 'Other#Pw2026'], 'new_password', 'New password confirmation does not match.'],
            'same as current' => [['new_password' => 'Old-pass1!', 'new_password_confirmation' => 'Old-pass1!'], 'new_password', 'New password must be different from the old password.'],
        ];
    }

    #[Test]
    #[DataProvider('brokenRules')]
    public function each_broken_rule_reads_as_a_message_not_a_key(array $override, string $field, string $message): void
    {
        $response = $this->postJson(self::URL, array_merge([
            'current_password' => 'Old-pass1!',
            'new_password' => 'QaDummyPw#2026',
            'new_password_confirmation' => 'QaDummyPw#2026',
        ], $override))->assertUnprocessable();

        $this->assertContains($message, (array) $response->json("errors.{$field}"));
        $this->assertStringNotContainsString('borrower_validation.', json_encode($response->json('errors')));
    }

    /** Every borrower_validation key the code asks for is there, so none comes back as its key. */
    #[Test]
    public function every_borrower_validation_key_used_in_the_code_is_translated(): void
    {
        $keys = array_keys(require lang_path('en/borrower_validation.php'));
        $missing = [];

        foreach (File::allFiles(app_path()) as $file) {
            preg_match_all("/__\\('borrower_validation\\.([a-z0-9_]+)'/", $file->getContents(), $matches);
            foreach (array_diff($matches[1], $keys) as $key) {
                $missing[$key] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $missing, 'Keys used but not in lang/en/borrower_validation.php');
    }
}
