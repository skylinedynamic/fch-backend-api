<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\ActivityLogMail;
use App\Models\Borrower\Borrower;
use App\Models\Partner\Partner;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The email New Email sends follows the other emails (activity_log/
 * task_assigned): the greeting ("Hello <name>,"), one line naming the sender,
 * then the gray card with the subject, the message as written and the
 * attached files. It used to read as a system notice ("Hello! You have
 * received a new activity log update", "ACTIVITY DETAILS", "ACTIVITY CONTENT").
 */
#[CoversClass(ActivityLogMail::class)]
class ActivityLogMailDesignTest extends TestCase
{
    use RefreshDatabase;

    private function render(array $names = [], array $files = [], string $to = 'someone@example.test'): string
    {
        return (new ActivityLogMail(
            ['subject' => 'Your term sheet', 'body' => '<p>Please review the attached term sheet.</p>'],
            $names,
            $files,
        ))->to($to)->render();
    }

    /** The greeting the other emails open with: a 15px <h4>, "Hello <name>,". */
    private function assertGreeting(string $expected, string $html): void
    {
        $this->assertMatchesRegularExpression('/<h4[^>]*font-size: 15px[^>]*>\s*' . preg_quote(e($expected), '/') . '\s*<\/h4>/', $html);
    }

    #[Test]
    public function it_greets_a_client_by_first_name(): void
    {
        $partner = Partner::create([
            'company_name' => 'Acme Capital', 'full_name' => 'Acme Owner',
            'email' => 'acme-' . uniqid() . '@example.test', 'partner_type' => Partner::PARTNER_TYPE_WHITE_LABEL,
        ]);
        Borrower::create(['partner_id' => $partner->id, 'first_name' => 'Willie', 'last_name' => 'Client', 'email' => 'willie@example.test']);

        $this->assertGreeting(__('activity_log.mail.greeting_name', ['name' => 'Willie']), $this->render(to: 'willie@example.test'));
    }

    #[Test]
    public function it_greets_a_user_by_name_whatever_the_case_of_the_address(): void
    {
        User::create(['name' => 'Dana Staff', 'email' => 'dana@example.test', 'password' => bcrypt('x'), 'status' => true, 'user_type' => User::EMPLOYEE]);

        $this->assertGreeting(__('activity_log.mail.greeting_name', ['name' => 'Dana Staff']), $this->render(to: 'DANA@example.test'));
    }

    #[Test]
    public function an_address_with_no_one_behind_it_gets_a_plain_hello(): void
    {
        $this->assertGreeting(__('activity_log.mail.greeting'), $this->render(to: 'nobody@example.test'));
    }

    #[Test]
    public function the_greeting_is_followed_by_one_line_naming_the_sender(): void
    {
        $html = $this->render();

        // The sender's name is the mail's branded site name.
        $intro = str_replace('__SENDER__', '.+?', preg_quote(e(__('activity_log.mail.intro', ['sender' => '__SENDER__'])), '/'));
        $this->assertMatchesRegularExpression('/<\/h4>\s*<p[^>]*>\s*' . $intro . '\s*<\/p>/', $html);
    }

    #[Test]
    public function the_subject_and_message_sit_in_the_card_the_other_emails_use(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression('/class="activity-mail__card"[^>]*background-color: #f8fafc/', $html);
        $this->assertMatchesRegularExpression('/class="activity-mail__subject"[^>]*>\s*Your term sheet\s*</', $html);
        $this->assertStringContainsString('<p>Please review the attached term sheet.</p>', $html);
        $this->assertLessThan(strpos($html, 'Please review'), strpos($html, 'Your term sheet'));
    }

    #[Test]
    public function it_no_longer_reads_as_a_system_notice(): void
    {
        $html = $this->render();

        foreach (['You have received a new activity log update', 'ACTIVITY DETAILS', 'ACTIVITY CONTENT', 'SUBJECT:'] as $boilerplate) {
            $this->assertStringNotContainsString($boilerplate, $html);
        }
    }

    #[Test]
    public function attached_files_are_listed_under_the_message_with_their_count(): void
    {
        $html = $this->render(['term-sheet.pdf', 'bank statement.pdf'], [
            UploadedFile::fake()->createWithContent('term-sheet.pdf', 'a'),
            UploadedFile::fake()->createWithContent('bank statement.pdf', 'b'),
        ]);

        $this->assertStringContainsString(__('activity_log.mail.attachments', ['count' => 2]), $html);
        $this->assertStringContainsString('term-sheet.pdf', $html);
        $this->assertStringContainsString('bank statement.pdf', $html);
        $this->assertGreaterThan(strpos($html, 'Please review'), strpos($html, 'term-sheet.pdf'));
    }

    #[Test]
    public function without_files_there_is_no_attachments_block(): void
    {
        $this->assertStringNotContainsString('activity-mail__attachments', $this->render());
    }

    #[Test]
    public function it_ends_with_the_card_with_no_sign_off_after_it(): void
    {
        $html = $this->render();

        // The message is the sender's own; no "Support Team" / "Regards" line is added after it.
        $this->assertStringNotContainsString('activity-mail__signoff', $html);
        $this->assertStringNotContainsString('Support Team', $html);
        $this->assertDoesNotMatchRegularExpression('/(Thanks|Regards),<br>\s*<strong/', $html);
    }

    #[Test]
    public function its_words_come_from_the_lang_file(): void
    {
        foreach (['greeting', 'greeting_name', 'intro', 'message', 'attachments', 'attachments_note', 'default_subject'] as $key) {
            $this->assertNotSame("activity_log.mail.{$key}", __("activity_log.mail.{$key}"), "lang/en/activity_log.php is missing mail.{$key}");
        }
    }
}
