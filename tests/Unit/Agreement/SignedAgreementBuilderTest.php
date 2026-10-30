<?php

namespace Tests\Unit\Agreement;

use App\Exceptions\AgreementFieldsInvalidException;
use App\Services\FCH\Common\ManageAgreement\SignedAgreementBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The signed record is built on the server from the exact template version and
 * the values the signer entered, so the signer's browser can no longer decide
 * what the record says.
 */
#[CoversClass(SignedAgreementBuilder::class)]
class SignedAgreementBuilderTest extends TestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private SignedAgreementBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new SignedAgreementBuilder();
    }

    /** Shaped like the Agreement Builder's output: a name, a consent checkbox and a signature with its date. */
    private function template(bool $withIds = true, bool $required = true): string
    {
        $id = fn (string $key) => $withIds ? " data-field-id=\"{$key}\"" : '';
        $req = $required ? ' required' : '';

        return '<!DOCTYPE html><html><head><style>.doc{height:1122.5px;overflow:hidden}</style></head><body>'
            . '<form id="agreement-form"><div class="doc" id="doc-root">'
            . '<div class="p-text">Loan terms</div>'
            . '<div class="p-field"><div class="p-input-wrap"><label class="p-label">Full Name *:</label>'
            . '<input type="text" class="p-input"' . $id('b1') . $req . '/></div></div>'
            . '<div class="p-field"><div class="p-cb-group"><input type="checkbox" class="p-checkbox"' . $id('b2') . $req . '/>'
            . '<label class="p-cb-label">I agree</label></div></div>'
            . '<div class="row p-sig-grid"><div class="col-md-6"><div class="p-field">'
            . '<div class="p-sig-box p-sig-drop preview-dropzone" data-block-id="b3"><i class="ri-quill-pen-line p-sig-icon"></i>'
            . '<div class="p-sig-meta">SIGNATURE *</div><input type="text" class="p-input"' . $id('b3') . $req . ' value="" style="position:absolute;opacity:0"/></div>'
            . '</div></div><div class="col-md-6"><div class="p-field"><div class="p-sig-box">'
            . '<input type="text" class="form-control flatpickr p-sig-input"' . $id('b3:date') . $req . '/>'
            . '<div class="p-sig-meta p-sig-meta-date">Signature Date</div></div></div></div></div>'
            . '</div><button type="submit" id="hidden-submit" style="display:none"></button></form>'
            . '<input type="file" id="preview-upload" style="display:none" accept="image/*">'
            . '<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script><script>window.paginate()</script>'
            . '</body></html>';
    }

    private function fields(array $overrides = []): array
    {
        return array_merge(['b1' => 'Jane Doe', 'b2' => true, 'b3' => self::PNG, 'b3:date' => '30 Oct, 2026'], $overrides);
    }

    #[Test]
    public function it_writes_the_signers_values_into_the_agreement_as_text(): void
    {
        $html = $this->builder->build($this->template(), $this->fields());

        $this->assertStringContainsString('Loan terms', $html);
        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('30 Oct, 2026', $html);
        $this->assertStringContainsString('☑', $html);
        $this->assertStringNotContainsString('<input', $html);
    }

    #[Test]
    public function an_unticked_box_shows_as_unticked(): void
    {
        $html = $this->builder->build($this->template(required: false), $this->fields(['b2' => false]));

        $this->assertStringContainsString('☐', $html);
        $this->assertStringNotContainsString('☑', $html);
    }

    #[Test]
    public function what_the_signer_typed_stays_text_and_never_becomes_markup(): void
    {
        $html = $this->builder->build($this->template(), $this->fields(['b1' => '<img src=x onerror=alert(1)>Jane']));

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;Jane', $html);
    }

    #[Test]
    public function the_signature_image_is_placed_in_the_signature_box(): void
    {
        $html = $this->builder->build($this->template(), $this->fields());

        $this->assertMatchesRegularExpression('#<div class="p-sig-box[^"]*preview-dropzone"[^>]*><img src="' . preg_quote(self::PNG, '#') . '"#', $html);
    }

    #[Test]
    public function a_signature_that_is_not_a_plain_image_is_refused(): void
    {
        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', 'https://evil.test/x.png', 'data:image/png;base64,***'] as $bad) {
            try {
                $this->builder->build($this->template(), $this->fields(['b3' => $bad]));
                $this->fail("Accepted signature: {$bad}");
            } catch (AgreementFieldsInvalidException $e) {
                $this->assertSame(['b3'], $e->fields, $bad);
            }
        }
    }

    #[Test]
    public function the_template_scripts_and_upload_input_are_left_out(): void
    {
        $html = $this->builder->build($this->template(), $this->fields());

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('preview-upload', $html);
        $this->assertStringNotContainsString('hidden-submit', $html);
    }

    /** No script splits the pages any more, so each page shows in full instead of being cut at A4. */
    #[Test]
    public function the_whole_document_is_shown_not_cut_at_the_first_page(): void
    {
        $html = $this->builder->build($this->template(), $this->fields());

        $this->assertStringContainsString('.doc{height:auto !important;overflow:visible !important}', $html);
    }

    #[Test]
    public function every_required_field_must_be_filled(): void
    {
        try {
            $this->builder->build($this->template(), ['b1' => '  ', 'b2' => false]);
            $this->fail('Signed with required fields missing.');
        } catch (AgreementFieldsInvalidException $e) {
            $this->assertSame(['b1', 'b2', 'b3', 'b3:date'], $e->fields);
        }
    }

    /** Templates saved before fields had ids: the fields are named by their order. */
    #[Test]
    public function fields_of_an_older_template_are_named_by_their_order(): void
    {
        $html = $this->builder->build($this->template(withIds: false), [
            'field_0' => 'Jane Doe', 'field_1' => true, 'field_2' => self::PNG, 'field_3' => '30 Oct, 2026',
        ]);

        $this->assertStringContainsString('Jane Doe', $html);
        $this->assertStringContainsString('<img src="' . self::PNG . '"', $html);
    }

    #[Test]
    public function it_lists_the_fields_a_template_asks_for(): void
    {
        $this->assertSame(
            ['b1' => 'text', 'b2' => 'checkbox', 'b3' => 'signature', 'b3:date' => 'text'],
            $this->builder->fields($this->template())
        );
        $this->assertSame(['field_0', 'field_1', 'field_2', 'field_3'], array_keys($this->builder->fields($this->template(withIds: false))));
    }

    /** The Agreement Builder's signing line: a "Sign here" tag over a rule (see its gen()). */
    private function signingLine(bool $required): string
    {
        return '<html><body><div class="doc"><div class="p-sign"><div class="p-field p-sign-col">'
            . '<div class="p-sign-pad preview-dropzone" data-block-id="s"><span class="p-sign-tag"><i class="ri-quill-pen-line"></i>Sign here</span>'
            . '<input type="text" class="p-input" data-field-id="s"' . ($required ? ' required' : '') . '/></div>'
            . '<div class="p-sign-rule"></div></div></div></div></body></html>';
    }

    #[Test]
    public function a_signed_line_shows_the_signature_and_not_the_sign_here_tag(): void
    {
        $html = $this->builder->build($this->signingLine(true), ['s' => self::PNG]);

        $this->assertStringContainsString('<img src="' . self::PNG . '"', $html);
        $this->assertStringNotContainsString('Sign here', $html);
    }

    #[Test]
    public function a_line_left_unsigned_is_left_blank_without_the_sign_here_tag(): void
    {
        $html = $this->builder->build($this->signingLine(false), []);

        $this->assertStringNotContainsString('Sign here', $html);
        $this->assertStringContainsString('p-sign-rule', $html);
    }

    #[Test]
    public function it_ends_with_a_signature_certificate_written_as_text(): void
    {
        $html = $this->builder->build($this->signingLine(true), ['s' => self::PNG], [
            'Signed by' => 'Jane <b>Doe</b>',
            'IP address' => '203.0.113.9',
        ]);

        $this->assertStringContainsString('Signature certificate', $html);
        $this->assertStringContainsString('Jane &lt;b&gt;Doe&lt;/b&gt;', $html);
        $this->assertStringContainsString('203.0.113.9', $html);
        $this->assertGreaterThan(strpos($html, 'p-sign-rule'), strpos($html, 'Signature certificate'));
    }

    #[Test]
    public function the_certificate_shows_the_adopted_signature_and_that_signing_is_complete(): void
    {
        $html = $this->builder->build($this->signingLine(true), ['s' => self::PNG], ['Signed by' => 'Jane Doe']);
        $certificate = substr($html, strpos($html, 'Signature certificate'));

        $this->assertStringContainsString('Completed', $certificate);
        $this->assertMatchesRegularExpression('#<img[^>]+class="p-certificate-sign"[^>]+src="' . preg_quote(self::PNG, '#') . '"#', $certificate);
        $this->assertStringContainsString('Adopted signature', $certificate);
    }

    #[Test]
    public function the_certificate_shows_no_signature_image_when_none_was_adopted(): void
    {
        $html = $this->builder->build($this->signingLine(false), [], ['Signed by' => 'Jane Doe']);

        $this->assertStringNotContainsString('p-certificate-sign', $html);
    }
}
