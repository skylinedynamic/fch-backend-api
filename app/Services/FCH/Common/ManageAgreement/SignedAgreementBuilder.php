<?php

namespace App\Services\FCH\Common\ManageAgreement;

use App\Exceptions\AgreementFieldsInvalidException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Builds a signed agreement on the server from the template version signed
 * and the values the signer entered, so the record no longer comes from the
 * signer's browser.
 *
 * A field is named by its `data-field-id` (set by the Agreement Builder), or,
 * in templates saved before fields had ids, `field_{n}` by its order. Each
 * field is replaced by its value as text (never markup), a checkbox by ☑/☐, a
 * signature by its image. The template's scripts and upload input are left
 * out, and pages show in full, since no script splits them any more.
 */
class SignedAgreementBuilder
{
    private const SIGNATURE_IMAGE = '#^data:image/(png|jpeg|jpg|gif|webp|heic|heif);base64,([A-Za-z0-9+/]+={0,2})$#';

    private const SKIPPED_TYPES = ['file', 'hidden', 'submit', 'button', 'image', 'reset'];

    private const FULL_PAGES_CSS = '.doc{height:auto !important;overflow:visible !important}';

    private const CERTIFICATE_PAGE_STYLE = 'width:794px;max-width:100%;margin:15px auto 0;padding:56px 64px;box-sizing:border-box;'
        . 'background:#fff;font-family:Inter,DejaVu Sans,sans-serif;page-break-before:always;break-before:page';

    /**
     * The fields a template asks for, in order: key => text|checkbox|signature.
     *
     * @return array<string, string>
     */
    public function fields(string $templateHtml): array
    {
        [, $fields] = $this->parse($templateHtml);

        return array_map(fn (array $field) => $field['type'], $fields);
    }

    /**
     * @param  array<string, mixed>  $values  field key => value entered
     *
     * @throws AgreementFieldsInvalidException when a required field is empty or a signature is not a plain image
     */
    /**
     * @param array<string, string> $certificate details for the closing signature certificate
     *                                           (label => value), or none for no certificate
     */
    public function build(string $templateHtml, array $values, array $certificate = []): string
    {
        [$doc, $fields] = $this->parse($templateHtml);
        $invalid = [];

        foreach ($fields as $key => $field) {
            $value = $values[$key] ?? null;

            if ($field['type'] === 'checkbox') {
                $ticked = in_array($value, [true, 1, '1', 'true', 'on'], true);
                if ($field['required'] && !$ticked) {
                    $invalid[] = $key;
                }
                continue;
            }

            $text = is_scalar($value) ? trim((string) $value) : '';
            if ($field['required'] && $text === '') {
                $invalid[] = $key;
            } elseif ($field['type'] === 'signature' && $text !== '' && !$this->isSignatureImage($text)) {
                $invalid[] = $key;
            }
        }

        if ($invalid !== []) {
            throw new AgreementFieldsInvalidException(__('agreement.fields_invalid'), $invalid);
        }

        foreach ($fields as $key => $field) {
            $this->fill($doc, $field, $values[$key] ?? null);
        }

        $this->strip($doc);
        $this->showFullPages($doc);
        if ($certificate !== []) {
            $signatures = [];
            foreach ($fields as $key => $field) {
                $value = is_string($values[$key] ?? null) ? trim($values[$key]) : '';
                if ($field['type'] === 'signature' && $value !== '' && $this->isSignatureImage($value)) {
                    $signatures[] = $value;
                }
            }
            $this->appendCertificate($doc, $certificate, $signatures);
        }

        return $this->html($doc);
    }

    /**
     * @return array{0: DOMDocument, 1: array<string, array{element: DOMElement, type: string, required: bool}>}
     */
    private function parse(string $html): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $doc->removeChild($node);
            }
        }
        $doc->encoding = 'UTF-8';

        $fields = [];
        $position = 0;
        foreach ((new DOMXPath($doc))->query('//input | //textarea') as $element) {
            /** @var DOMElement $element */
            $type = strtolower($element->getAttribute('type'));
            if (in_array($type, self::SKIPPED_TYPES, true) || in_array($element->getAttribute('id'), ['preview-upload', 'hidden-submit'], true)) {
                continue;
            }

            $key = $element->getAttribute('data-field-id') ?: 'field_' . $position;
            $position++;

            $fields[$key] = [
                'element' => $element,
                'type' => $type === 'checkbox' ? 'checkbox' : ($this->dropzoneOf($element) ? 'signature' : 'text'),
                'required' => $element->hasAttribute('required'),
            ];
        }

        return [$doc, $fields];
    }

    /** @param array{element: DOMElement, type: string, required: bool} $field */
    private function fill(DOMDocument $doc, array $field, mixed $value): void
    {
        $element = $field['element'];

        if ($field['type'] === 'signature') {
            $dropzone = $this->dropzoneOf($element);
            $image = is_string($value) ? trim($value) : '';
            if ($image === '') {
                $element->parentNode->removeChild($element);
                // Left unsigned: a blank line, not the "Sign here" prompt.
                foreach (iterator_to_array($dropzone->childNodes) as $child) {
                    $dropzone->removeChild($child);
                }
                return;
            }
            while ($dropzone->firstChild) {
                $dropzone->removeChild($dropzone->firstChild);
            }
            $img = $doc->createElement('img');
            $img->setAttribute('src', $image);
            $img->setAttribute('alt', 'Signature');
            $img->setAttribute('class', 'p-sign-img');
            $img->setAttribute('style', 'max-height:100%;max-width:100%;border-radius:4px');
            $dropzone->appendChild($img);
            $dropzone->setAttribute('style', 'border:none;background:transparent;padding:0');
            return;
        }

        $span = $doc->createElement('span');
        if ($field['type'] === 'checkbox') {
            $ticked = in_array($value, [true, 1, '1', 'true', 'on'], true);
            $span->appendChild($doc->createTextNode($ticked ? '☑ ' : '☐ '));
            $span->setAttribute('style', 'font-size:18px');
        } else {
            $text = is_scalar($value) ? trim((string) $value) : '';
            $span->appendChild($doc->createTextNode($text !== '' ? $text : '_________'));
            $span->setAttribute('class', 'p-filled');
            $span->setAttribute('style', $element->nodeName === 'textarea' ? 'display:block;white-space:pre-wrap;padding:0 2px' : 'padding:0 2px');
        }
        $element->parentNode->replaceChild($span, $element);
    }

    private function strip(DOMDocument $doc): void
    {
        $xpath = new DOMXPath($doc);
        $unwanted = $xpath->query('//script | //input[@type="file"] | //*[@id="preview-upload"] | //*[@id="hidden-submit"]');
        foreach (iterator_to_array($unwanted) as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    private function showFullPages(DOMDocument $doc): void
    {
        $head = $doc->getElementsByTagName('head')->item(0);
        if (!$head) {
            $html = $doc->getElementsByTagName('html')->item(0);
            $head = $doc->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }
        $style = $doc->createElement('style');
        $style->appendChild($doc->createTextNode(self::FULL_PAGES_CSS));
        $head->appendChild($style);
    }

    /**
     * A last page recording who signed, when and from where, with the signature
     * they adopted. Written as text and tables (dompdf draws no flexbox).
     *
     * @param array<string, string> $details label => value
     * @param list<string>          $signatures adopted signature images (data URLs, already checked)
     */
    private function appendCertificate(DOMDocument $doc, array $details, array $signatures = []): void
    {
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return;
        }
        $el = function (string $tag, string $style, ?string $text = null) use ($doc): DOMElement {
            $node = $doc->createElement($tag);
            $node->setAttribute('style', $style);
            if ($text !== null) {
                $node->appendChild($doc->createTextNode($text));
            }

            return $node;
        };

        $page = $el('div', self::CERTIFICATE_PAGE_STYLE);
        $page->setAttribute('class', 'doc p-certificate');

        // Title, with the outcome beside it.
        $head = $el('table', 'width:100%;border-collapse:collapse;border-bottom:3px solid #2a5fc1;margin-bottom:6px');
        $row = $doc->createElement('tr');
        $titleCell = $el('td', 'padding:0 0 12px;vertical-align:bottom');
        $titleCell->appendChild($el('div', 'font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#2a5fc1;margin-bottom:4px', 'Audit trail'));
        $titleCell->appendChild($el('div', 'font-size:22px;font-weight:800;color:#0f172a', 'Signature certificate'));
        $row->appendChild($titleCell);
        $statusCell = $el('td', 'padding:0 0 14px;vertical-align:bottom;text-align:right');
        $statusCell->appendChild($el('span', 'display:inline-block;padding:5px 12px;border-radius:4px;background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;letter-spacing:.5px', '✓ Completed'));
        $row->appendChild($statusCell);
        $head->appendChild($row);
        $page->appendChild($head);
        $page->appendChild($el('div', 'font-size:11.5px;line-height:1.6;color:#64748b;margin:10px 0 22px',
            'This agreement was signed electronically. The details below were recorded at the moment it was signed and form part of the signed record.'));

        // The signer: the adopted signature beside who signed and when.
        $card = $el('table', 'width:100%;border-collapse:separate;border-spacing:0;border:1px solid #dbe3ef;border-radius:4px;margin-bottom:22px');
        $row = $doc->createElement('tr');
        if ($signatures !== []) {
            $signCell = $el('td', 'width:42%;padding:16px 18px;vertical-align:top;background:#f8fafc;border-right:1px solid #dbe3ef');
            $signCell->appendChild($el('div', 'font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#64748b;margin-bottom:8px', 'Adopted signature'));
            foreach ($signatures as $signature) {
                $img = $el('img', 'display:block;max-width:100%;max-height:70px;margin:2px 0 6px');
                $img->setAttribute('class', 'p-certificate-sign');
                $img->setAttribute('src', $signature);
                $img->setAttribute('alt', 'Signature');
                $signCell->appendChild($img);
            }
            $signCell->appendChild($el('div', 'border-top:1px solid #334155;height:1px'));
            $row->appendChild($signCell);
        }
        $infoCell = $el('td', 'padding:8px 18px;vertical-align:top');
        $table = $el('table', 'width:100%;border-collapse:collapse;font-size:12px');
        $last = array_key_last($details);
        foreach ($details as $label => $value) {
            $line = $doc->createElement('tr');
            $border = $label === $last ? '' : 'border-bottom:1px solid #eef2f7;';
            $line->appendChild($el('td', 'width:34%;padding:9px 0;color:#64748b;font-weight:600;vertical-align:top;' . $border, (string) $label));
            $line->appendChild($el('td', 'padding:9px 0;color:#0f172a;font-weight:500;vertical-align:top;word-break:break-word;' . $border, (string) $value));
            $table->appendChild($line);
        }
        $infoCell->appendChild($table);
        $row->appendChild($infoCell);
        $card->appendChild($row);
        $page->appendChild($card);

        $page->appendChild($el('div', 'padding:12px 14px;border-radius:4px;background:#f8fafc;font-size:10.5px;line-height:1.6;color:#64748b',
            'Electronic signatures are recorded under the U.S. Electronic Signatures in Global and National Commerce Act (ESIGN) and the Uniform Electronic Transactions Act (UETA). '
            . 'The signed record and this certificate are kept together; any change to the record after signing can be detected.'));
        $body->appendChild($page);
    }

    private function html(DOMDocument $doc): string
    {
        $out = '';
        foreach ($doc->childNodes as $node) {
            $out .= $doc->saveHTML($node);
        }

        return $out;
    }

    private function dropzoneOf(DOMElement $element): ?DOMElement
    {
        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array('preview-dropzone', preg_split('/\s+/', $node->getAttribute('class')), true)) {
                return $node;
            }
        }

        return null;
    }

    private function isSignatureImage(string $value): bool
    {
        return preg_match(self::SIGNATURE_IMAGE, $value, $match) === 1 && base64_decode($match[2], true) !== false;
    }
}
