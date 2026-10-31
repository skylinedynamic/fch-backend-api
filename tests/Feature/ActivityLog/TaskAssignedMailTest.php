<?php

namespace Tests\Feature\ActivityLog;

use App\Mail\ActivityLog\TaskAssignedMail;
use App\Models\Partner\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The email a staff member gets when a Task is assigned to them: who, on
 * what, by when, how urgent, where it stands, and a link to the record.
 */
class TaskAssignedMailTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'FCH Host',
            'full_name' => 'FCH Host',
            'email' => 'host-' . uniqid() . '@example.test',
        ]);
        $this->partner = Partner::create([
            'is_host' => false,
            'status' => Partner::STATUS_ACTIVE,
            'company_name' => 'Four Corner Funding',
            'full_name' => 'Four Corner Funding',
            'email' => 'partner-' . uniqid() . '@example.test',
        ]);
    }

    private function mail(array $overrides = []): TaskAssignedMail
    {
        return new TaskAssignedMail(array_merge([
            'assignee_name' => 'Avery Staff',
            'assigner_name' => 'Host Admin',
            'subject' => 'Collect bank statements',
            'description' => 'Ask the client for the last 3 months',
            'record' => 'client Johnathan Smithfield',
            'due_date' => 'Nov 7, 2026',
            'priority' => 'high',
            'priority_label' => 'High',
            'status' => 'in_progress',
            'status_label' => 'In Progress',
            'path' => '/clients/5',
        ], $overrides), $this->partner->id);
    }

    #[Test]
    public function it_shows_the_task_details(): void
    {
        $html = $this->mail()->render();

        $this->assertStringContainsString('A task has been assigned to you', $html);
        $this->assertStringContainsString('Host Admin assigned you a task on client Johnathan Smithfield.', $html);
        $this->assertStringContainsString('Collect bank statements', $html);
        $this->assertStringContainsString('Avery Staff', $html);
        $this->assertStringContainsString('>AS<', $html, 'The assignee\'s initials as the avatar.');
        $this->assertStringContainsString('Nov 7, 2026', $html);
        $this->assertStringContainsString('Ask the client for the last 3 months', $html);
        $this->assertStringContainsString('View Task', $html);
    }

    #[Test]
    public function priority_and_status_are_coloured_badges(): void
    {
        $html = $this->mail()->render();

        // High: danger tone; In Progress: info tone
        $this->assertMatchesRegularExpression('/color: #f06548; background-color: #fdeeeb;">High</', $html);
        $this->assertMatchesRegularExpression('/color: #299cdb; background-color: #e9f5fb;">In Progress</', $html);

        $low = $this->mail(['priority' => 'low', 'priority_label' => 'Low', 'status' => 'complete', 'status_label' => 'Complete'])->render();
        $this->assertMatchesRegularExpression('/color: #0ab39c; background-color: #e6f7f5;">Low</', $low);
        $this->assertMatchesRegularExpression('/color: #0ab39c; background-color: #e6f7f5;">Complete</', $low);
    }

    #[Test]
    public function the_button_links_to_the_record_in_the_portal(): void
    {
        $html = $this->mail()->render();

        $this->assertMatchesRegularExpression('#href="https?://[^"]*/clients/5"#', $html);
    }

    #[Test]
    public function the_subject_names_the_task(): void
    {
        $this->mail()->assertHasSubject('New task assigned: Collect bank statements');
    }

    #[Test]
    public function the_description_is_escaped(): void
    {
        $html = $this->mail(['description' => '<script>alert(1)</script>'])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    #[Test]
    public function missing_details_are_left_out(): void
    {
        $html = $this->mail(['due_date' => null, 'description' => null])->render();

        $this->assertStringNotContainsString('Due Date', $html);
        $this->assertStringNotContainsString('>Details<', $html);
    }

    #[Test]
    public function with_changes_it_tells_the_other_person_what_was_updated(): void
    {
        $mail = $this->mail([
            'updated_by' => 'Host Admin',
            'changes' => ['Date: Nov 9, 2026 2:00 PM', 'Priority: Low'],
        ]);
        $html = $mail->render();

        $this->assertSame('Task updated: Collect bank statements', $mail->envelope()->subject);
        $this->assertStringContainsString('A task was updated', $html);
        $this->assertStringContainsString('Host Admin updated a task on client Johnathan Smithfield.', $html);
        $this->assertStringContainsString('What changed', $html);
        $this->assertStringContainsString('Date: Nov 9, 2026 2:00 PM', $html);
        $this->assertStringContainsString('Priority: Low', $html);
        $this->assertStringNotContainsString('A task has been assigned to you', $html);
        $this->assertStringContainsString('View Task', $html);
    }
}
