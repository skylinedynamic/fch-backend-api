<?php

namespace App\Support\EmbedForm;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Embed code from Embed Form Settings, checked before it is saved or served.
 *
 * The admin pastes this code into CMS > Site Settings for the website's
 * /apply page (setting SETTING_KEY). It is never served as HTML: it is parsed
 * here, and only what the builder itself produces is accepted -
 *
 *   <iframe src=".../form/{key}?header=0&bg=white" [data-fch-embed]
 *           height="N" style="border-radius:Npx" ...></iframe>
 *   [<script src=".../form/assets/embed.js" async></script>]
 *
 * - so no other element, no event handler and no inline script gets through.
 * The builder is react-fch src/utils/embedSnippet.ts. Which form and which
 * script are allowed is EmbedFormSnippet's check.
 */
final class EmbedSnippet
{
    /** Site setting (website module) holding the pasted code. */
    public const SETTING_KEY = 'apply_form_embed_code';

    /** What an iframe may carry; data-fch-embed marks one embed.js sizes. */
    private const IFRAME_ATTRIBUTES = [
        'src', 'width', 'height', 'frameborder', 'style', 'title', 'scrolling',
        'allow', 'loading', 'allowfullscreen', 'referrerpolicy', 'data-fch-embed',
    ];

    private const SCRIPT_ATTRIBUTES = ['src', 'async', 'defer'];

    private const FORM_PATH = '#^/form/([A-Za-z0-9_\-]+)/?$#';

    private function __construct(
        private readonly string $host,
        private readonly string $key,
        private readonly ?string $scriptUrl,
    ) {}

    /** @throws InvalidEmbedSnippet when the code is anything but the builder's own. */
    public static function parse(string $code): self
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidEmbedSnippet('The code is empty.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$code.'</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // A <script> before any body content is moved into <head> by the parser.
        $nodes = [];
        foreach (['head', 'body'] as $section) {
            foreach ($document->getElementsByTagName($section)->item(0)?->childNodes ?? [] as $node) {
                $nodes[] = $node;
            }
        }

        $iframes = [];
        $scripts = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement && $node->tagName === 'meta' && $node->parentNode?->nodeName === 'head') {
                continue;
            }
            match (true) {
                $node instanceof DOMElement && $node->tagName === 'iframe' => $iframes[] = $node,
                $node instanceof DOMElement && $node->tagName === 'script' => $scripts[] = $node,
                $node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '' => null,
                default => throw new InvalidEmbedSnippet('Only the embed iframe and its script are allowed.'),
            };
        }

        if (count($iframes) !== 1 || count($scripts) > 1) {
            throw new InvalidEmbedSnippet('The code must hold one iframe and at most one script.');
        }

        $iframe = $iframes[0];
        self::assertAttributes($iframe, self::IFRAME_ATTRIBUTES);
        if (trim($iframe->textContent) !== '') {
            throw new InvalidEmbedSnippet('The iframe must be empty.');
        }

        $src = parse_url($iframe->getAttribute('src'));
        if (
            ! is_array($src)
            || ! in_array(strtolower($src['scheme'] ?? ''), ['http', 'https'], true)
            || empty($src['host'])
            || ! preg_match(self::FORM_PATH, $src['path'] ?? '', $path)
        ) {
            throw new InvalidEmbedSnippet('The iframe must open an application form link.');
        }

        $scriptUrl = null;
        if ($scripts) {
            $script = $scripts[0];
            self::assertAttributes($script, self::SCRIPT_ATTRIBUTES);
            $scriptUrl = $script->getAttribute('src');
            if (trim($script->textContent) !== '' || ! preg_match('#^https?://#i', $scriptUrl)) {
                throw new InvalidEmbedSnippet('Only the embed script may be included.');
            }
        }

        return new self(strtolower($src['host']), $path[1], $scriptUrl);
    }

    private static function assertAttributes(DOMNode $element, array $allowed): void
    {
        foreach ($element->attributes ?? [] as $attribute) {
            if (! in_array(strtolower($attribute->name), $allowed, true)) {
                throw new InvalidEmbedSnippet("The attribute \"{$attribute->name}\" is not allowed.");
            }
        }
    }

    public function host(): string
    {
        return $this->host;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function scriptUrl(): ?string
    {
        return $this->scriptUrl;
    }
}
