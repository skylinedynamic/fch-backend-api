<?php

namespace Tests\Feature\EmbedForm;

use App\Models\Borrower\BorrowerDocType;
use App\Models\EmbedForm\EmbedFormConfig;
use App\Models\Partner\Partner;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Defect (SCRUM-435 S435-BUG-04): the form's labels were not tied to their
 * fields, so a screen reader announced an unnamed text box. Every field on all
 * 8 steps, and on a co-borrower card, must carry a name.
 *
 * This reads the markup the server sends. What the script adds in the browser
 * (select2's box, the date picker's box) is checked by fch-website-react's
 * e2e/apply-form-accessibility.spec.ts.
 */
class EmbedFormFieldLabelsTest extends TestCase
{
    use RefreshDatabase;

    private function pageHtml(): string
    {
        // The upload step has a field per document type.
        BorrowerDocType::create(['name' => 'Bank Statements', 'sort_order' => 1]);

        $partner = Partner::create([
            'is_host' => true,
            'status' => Partner::STATUS_ACTIVE,
            'partner_type' => Partner::PARTNER_TYPE_CERTIFIED,
            'company_name' => 'Host Co',
            'full_name' => 'Host Co',
            'email' => 'host@example.test',
        ]);

        $config = EmbedFormConfig::create([
            'partner_id' => $partner->id,
            'public_key' => EmbedFormConfig::generatePublicKey(),
            'form_title' => 'Funding Application',
            'is_active' => true,
        ]);

        return $this->get('/form/' . $config->public_key)->assertOk()->getContent();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new DOMXPath($document);
    }

    /** The fields a person can reach under $root: not hidden, not the honeypot. */
    private function fields(DOMXPath $xpath, string $root): array
    {
        $query = "{$root}//input[not(@type='hidden') and not(contains(@class,'honeypot'))]"
            . " | {$root}//select | {$root}//textarea";

        return iterator_to_array($xpath->query($query));
    }

    private function describe(DOMElement $field): string
    {
        return $field->tagName . ' ' . ($field->getAttribute('name') ?: $field->getAttribute('id') ?: '(unnamed)');
    }

    private function assertEveryFieldIsNamed(DOMXPath $xpath, string $root): void
    {
        $fields = $this->fields($xpath, $root);
        $this->assertNotEmpty($fields, "No fields found under {$root}");

        foreach ($fields as $field) {
            $id = $field->getAttribute('id');
            $named = $field->hasAttribute('aria-label')
                || ($id !== '' && $xpath->query("{$root}//label[@for='{$id}']")->length > 0)
                || $xpath->query('ancestor::label', $field)->length > 0;

            $this->assertTrue($named, $this->describe($field) . ' has no label');
        }
    }

    private function assertEveryLabelFindsItsField(DOMXPath $xpath, string $root): void
    {
        foreach ($xpath->query("{$root}//label") as $label) {
            $for = $label->getAttribute('for');
            $text = trim($label->textContent);

            if ($for === '') {
                $this->assertGreaterThan(0, $xpath->query('.//input', $label)->length, "Label \"{$text}\" has no for");
                continue;
            }

            $this->assertSame(1, $xpath->query("{$root}//*[@id='{$for}']")->length, "Label \"{$text}\" points at a missing id: {$for}");
        }
    }

    #[Test]
    public function every_field_on_the_form_has_a_label(): void
    {
        $this->assertEveryFieldIsNamed($this->xpath($this->pageHtml()), "//form[@id='loanAppForm']");
    }

    #[Test]
    public function every_label_points_at_a_field_that_exists(): void
    {
        $this->assertEveryLabelFindsItsField($this->xpath($this->pageHtml()), "//form[@id='loanAppForm']");
    }

    #[Test]
    public function no_two_fields_share_an_id(): void
    {
        $xpath = $this->xpath($this->pageHtml());
        $ids = array_map(
            fn (DOMElement $element) => $element->getAttribute('id'),
            iterator_to_array($xpath->query("//form[@id='loanAppForm']//*[@id]")),
        );

        $this->assertSame([], array_values(array_diff_assoc($ids, array_unique($ids))));
    }

    #[Test]
    public function a_label_above_two_controls_names_the_one_the_applicant_fills_in(): void
    {
        $xpath = $this->xpath($this->pageHtml());
        $labelFor = fn (string $text) => $xpath
            ->query("//form[@id='loanAppForm']//label[starts-with(normalize-space(.), '{$text}')]")
            ->item(0)?->getAttribute('for');

        // A dropdown sits first in both input groups; the visible label belongs
        // to the box being typed in, and the dropdown has a name of its own.
        $this->assertSame('business_phone_no', $labelFor('Business Phone'));
        $this->assertSame('requested_amount', $labelFor('Requested Amount'));
        $this->assertSame('business_country_select', $labelFor('Business phone country'));
    }

    #[Test]
    public function each_document_upload_is_named_by_its_document_type(): void
    {
        $xpath = $this->xpath($this->pageHtml());
        $id = $xpath->query("//input[@type='file']")->item(0)->getAttribute('id');

        $this->assertSame('Bank Statements', trim($xpath->query("//label[@for='{$id}']")->item(0)->textContent));
    }

    #[Test]
    public function the_phone_country_name_is_for_screen_readers_only(): void
    {
        $label = $this->xpath($this->pageHtml())
            ->query("//label[@for='business_country_select']")
            ->item(0);

        $this->assertStringContainsString('visually-hidden', $label->getAttribute('class'));
    }

    #[Test]
    public function every_yes_no_question_names_its_answers(): void
    {
        $xpath = $this->xpath($this->pageHtml());
        $groups = iterator_to_array($xpath->query("//*[contains(@class,'answer-group')]"));

        // Existing business debt, plus the 9 federal compliance questions.
        $this->assertCount(10, $groups);

        foreach ($groups as $group) {
            $field = $group->getAttribute('data-req-group');
            $this->assertSame('radiogroup', $group->getAttribute('role'), "{$field} is not a radio group");
            $this->assertSame('true', $group->getAttribute('aria-required'), "{$field} is not marked required");

            $question = $xpath->query("//*[@id='{$group->getAttribute('aria-labelledby')}']")->item(0);
            $this->assertNotNull($question, "{$field} points at no question");
            $this->assertStringContainsString('?', $question->textContent, "{$field} is not named by its question");
        }
    }

    #[Test]
    public function a_co_borrower_card_names_every_field(): void
    {
        $matched = preg_match('/wrapper\.innerHTML = `(.*?)`;/s', $this->pageHtml(), $m);
        $this->assertSame(1, $matched, 'The co-borrower template was not found');

        $xpath = $this->xpath('<div id="card">' . $m[1] . '</div>');

        $this->assertEveryFieldIsNamed($xpath, "//div[@id='card']");
        $this->assertEveryLabelFindsItsField($xpath, "//div[@id='card']");

        // Keyed by the card's index, or a second card would repeat the first's ids.
        foreach ($this->fields($xpath, "//div[@id='card']") as $field) {
            $this->assertStringStartsWith('co_${index}_', $field->getAttribute('id'), $this->describe($field));
        }
    }

    #[Test]
    public function the_script_names_what_it_draws_in_place_of_a_field(): void
    {
        $page = $this->pageHtml();

        $this->assertStringContainsString('nameSelect2(this);', $page);
        $this->assertStringContainsString('nameDatePicker(el);', $page);
    }
}
