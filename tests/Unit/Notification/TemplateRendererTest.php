<?php

namespace Tests\Unit\Notification;

use App\Services\FCH\Common\Notification\NotificationTypeDefinition;
use App\Services\FCH\Common\Notification\TemplateRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Notification templates come from config or the notification_types table,
 * so they are filled by plain placeholder substitution - never evaluated as
 * Blade/PHP - and their link must stay inside the portal.
 */
#[CoversClass(TemplateRenderer::class)]
class TemplateRendererTest extends TestCase
{
    private function type(array $overrides = []): NotificationTypeDefinition
    {
        return NotificationTypeDefinition::fromArray('test.type', array_merge([
            'name' => 'Test',
            'category' => 'funding',
            'icon' => 'ri-hand-coin-line',
            'channels' => ['database', 'broadcast'],
            'title' => 'Funding request {{status}}',
            'body' => 'Request #{{ id }} is now {{status}}.',
            'url' => '/funding-requests/{{id}}',
        ], $overrides));
    }

    #[Test]
    public function it_fills_placeholders_in_title_body_and_url(): void
    {
        $rendered = (new TemplateRenderer())->render($this->type(), ['id' => 42, 'status' => 'Approved']);

        $this->assertSame('Funding request Approved', $rendered->title);
        $this->assertSame('Request #42 is now Approved.', $rendered->body);
        $this->assertSame('/funding-requests/42', $rendered->url);
        $this->assertSame('funding', $rendered->category);
        $this->assertSame('ri-hand-coin-line', $rendered->icon);
    }

    #[Test]
    public function unknown_placeholders_render_as_empty_text(): void
    {
        $rendered = (new TemplateRenderer())->render($this->type(['title' => 'Hi {{missing}}!']), []);

        $this->assertSame('Hi !', $rendered->title);
    }

    #[Test]
    public function template_code_is_never_evaluated(): void
    {
        $rendered = (new TemplateRenderer())->render(
            $this->type(['body' => '{{ phpinfo() }} @php echo 1; @endphp {{status}}']),
            ['status' => 'Done']
        );

        $this->assertSame(' @php echo 1; @endphp Done', $rendered->body);
    }

    #[Test]
    public function only_internal_paths_are_kept_as_links(): void
    {
        $renderer = new TemplateRenderer();

        $this->assertNull($renderer->render($this->type(['url' => 'https://evil.test/x']), [])->url);
        $this->assertNull($renderer->render($this->type(['url' => '//evil.test/x']), [])->url);
        $this->assertNull($renderer->render($this->type(['url' => 'javascript:alert(1)']), [])->url);
        $this->assertNull($renderer->render($this->type(['url' => null]), [])->url);
        $this->assertSame('/dashboard', $renderer->render($this->type(['url' => '/dashboard']), [])->url);
    }

    #[Test]
    public function a_link_missing_its_last_value_opens_the_parent_page(): void
    {
        $renderer = new TemplateRenderer();

        $this->assertSame('/funding-requests', $renderer->render($this->type(), [])->url);
        $this->assertSame('/funding-requests', $renderer->render($this->type(), ['id' => null])->url);
        $this->assertSame('/', $renderer->render($this->type(['url' => '/']), [])->url);
    }

    #[Test]
    public function placeholder_values_cannot_break_out_of_the_link_path(): void
    {
        $rendered = (new TemplateRenderer())->render($this->type(), ['id' => '1/../../admin?x=1', 'status' => 'x']);

        $this->assertSame('/funding-requests/1%2F..%2F..%2Fadmin%3Fx%3D1', $rendered->url);
    }

    #[Test]
    public function the_sms_text_is_filled_from_the_types_own_sms_template(): void
    {
        $rendered = (new TemplateRenderer())->render(
            $this->type(['sms' => 'Request #{{id}}: {{status}}']),
            ['id' => 42, 'status' => 'Approved']
        );

        $this->assertSame('Request #42: Approved', $rendered->sms);
    }

    #[Test]
    public function without_an_sms_template_the_sms_text_is_the_title_and_body(): void
    {
        $rendered = (new TemplateRenderer())->render($this->type(), ['id' => 42, 'status' => 'Approved']);

        $this->assertSame('Funding request Approved: Request #42 is now Approved.', $rendered->sms);
    }

    #[Test]
    public function the_sms_text_is_never_part_of_the_stored_bell_payload(): void
    {
        $rendered = (new TemplateRenderer())->render($this->type(['sms' => 'x']), []);

        $this->assertArrayNotHasKey('sms', $rendered->toArray());
    }
}
