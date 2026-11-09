<?php

namespace App\Support\EmbedForm;

use Illuminate\Http\Request;

/**
 * How the public form draws itself inside a partner's iframe, read from the
 * embed code's link: /form/{key}?header=0&footer=0&bg=transparent&frame=0.
 *
 * The admin's Embed Form Settings builds these links (react-fch
 * src/config/embedFormDisplay.ts mirrors the names and values below). Only
 * the listed values are read; anything else falls back to the default, so a
 * value from the address bar never reaches the page's markup or CSS.
 *
 * `embed=1` is a preset - flush in a card the framing page draws itself, as
 * the website's /apply does - and an explicit option overrides it.
 */
final class EmbedFormDisplay
{
    public const PARAM_EMBED = 'embed';

    public const PARAM_HEADER = 'header';

    public const PARAM_FOOTER = 'footer';

    public const PARAM_BACKGROUND = 'bg';

    public const PARAM_FRAME = 'frame';

    /** The primary colour (buttons, steps, links), to match the framing site: color=2d9a96. */
    public const PARAM_PRIMARY = 'color';

    public const BACKGROUND_GREY = 'grey';

    public const BACKGROUND_WHITE = 'white';

    public const BACKGROUND_TRANSPARENT = 'transparent';

    public const BACKGROUNDS = [self::BACKGROUND_GREY, self::BACKGROUND_WHITE, self::BACKGROUND_TRANSPARENT];

    /**
     * Or a colour code: six hex digits, without the '#' (which a link would
     * treat as its fragment), e.g. bg=f5f7fa. Nothing else is read.
     */
    public const BACKGROUND_COLOUR_PATTERN = '/^[0-9a-fA-F]{6}$/';

    /** Body class for a colour-code background; the colour itself goes in --form-bg. */
    public const BACKGROUND_CUSTOM = 'custom';

    private const ON = '1';

    private const OFF = '0';

    private function __construct(
        private readonly bool $header,
        private readonly bool $footer,
        private readonly string $background,
        private readonly bool $frame,
        /** Lower-case hex without '#', or null for the partner's brand colour. */
        private readonly ?string $primary = null,
    ) {}

    /** The standalone page: everything shown, on grey. */
    private static function defaults(): self
    {
        return new self(true, true, self::BACKGROUND_GREY, true);
    }

    public static function fromRequest(Request $request): self
    {
        return self::fromQuery($request->query());
    }

    /**
     * @param  array<string, mixed>  $query  a link's query parameters
     */
    public static function fromQuery(array $query): self
    {
        $base = self::flag($query, self::PARAM_EMBED) === true
            ? new self(false, false, self::BACKGROUND_TRANSPARENT, false)
            : self::defaults();

        $background = $query[self::PARAM_BACKGROUND] ?? null;

        return new self(
            self::flag($query, self::PARAM_HEADER) ?? $base->header,
            self::flag($query, self::PARAM_FOOTER) ?? $base->footer,
            self::backgroundFrom($background) ?? $base->background,
            self::flag($query, self::PARAM_FRAME) ?? $base->frame,
            self::colourFrom($query[self::PARAM_PRIMARY] ?? null),
        );
    }

    /** A colour code (lower-cased); anything else is "not given". */
    private static function colourFrom(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::BACKGROUND_COLOUR_PATTERN, $value) ? strtolower($value) : null;
    }

    /** A listed name, or a colour code (lower-cased); anything else is "not given". */
    private static function backgroundFrom(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        if (in_array($value, self::BACKGROUNDS, true)) {
            return $value;
        }

        return preg_match(self::BACKGROUND_COLOUR_PATTERN, $value) ? strtolower($value) : null;
    }

    /** '1' / '0' only; anything else is "not given". */
    private static function flag(array $query, string $name): ?bool
    {
        $value = $query[$name] ?? null;

        return match ($value) {
            self::ON => true,
            self::OFF => false,
            default => null,
        };
    }

    public function showHeader(): bool
    {
        return $this->header;
    }

    public function showFooter(): bool
    {
        return $this->footer;
    }

    /** '#rrggbb' for a colour-code background, else null (a named one is a class). */
    public function backgroundColor(): ?string
    {
        return in_array($this->background, self::BACKGROUNDS, true) ? null : '#'.$this->background;
    }

    /** '#rrggbb' to use instead of the partner's brand colour, or null. */
    public function primaryColor(): ?string
    {
        return $this->primary ? '#'.$this->primary : null;
    }

    public function framed(): bool
    {
        return $this->frame;
    }

    /** Classes for <body>; built from the listed values only. */
    public function bodyClasses(): array
    {
        return array_values(array_filter([
            'form-bg-'.($this->backgroundColor() ? self::BACKGROUND_CUSTOM : $this->background),
            $this->frame ? null : 'is-frameless',
        ]));
    }
}
