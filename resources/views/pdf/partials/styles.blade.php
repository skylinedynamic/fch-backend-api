{{--
    The shared look of the funding application PDF and the electronic signature
    certificate: one font throughout (DejaVu Sans - dompdf has no 500 weight, so
    a "medium" value used to fall back to a serif face), labels and values the
    same size and weight, told apart by colour only. The brand colour carries
    the header band, the section headings and the footer bar.
--}}
@php
    // A light tint of the brand colour for the section headings; dompdf cannot
    // mix colours itself.
    $tint = function (string $hex, float $whiteShare): string {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return '#f1f5f9';
        }

        return '#' . implode('', array_map(
            fn ($channel) => str_pad(dechex((int) round(hexdec($channel) + (255 - hexdec($channel)) * $whiteShare)), 2, '0', STR_PAD_LEFT),
            str_split($hex, 2)
        ));
    };
    $primaryTint = $tint($primaryColor, 0.92);
@endphp
<style>
    @page { margin: 36px 40px 64px 40px; }

    body {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 9px;
        line-height: 1.45;
        color: #1f2937;
        margin: 0;
    }

    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; }

    /* The logo, faint, behind every page. */
    .watermark {
        position: fixed;
        top: 330px;
        left: 0;
        right: 0;
        text-align: center;
        opacity: 0.06;
        z-index: -1000;
    }

    /* Header: logo on the left, title on the right, then the brand band. */
    .doc-header td { vertical-align: middle; }
    .doc-company { font-size: 13px; font-weight: bold; color: {{ $primaryColor }}; }
    .doc-title { font-size: 15px; font-weight: bold; color: {{ $primaryColor }}; text-align: right; letter-spacing: 0.3px; }
    .doc-band {
        margin-top: 10px;
        background-color: {{ $primaryColor }};
        color: #ffffff;
    }
    .doc-band td { padding: 8px 12px; color: #ffffff; vertical-align: middle; }
    .doc-band .band-label { color: #ffffff; opacity: 0.75; }

    /* Sections: a tinted heading with a brand edge. */
    .section { margin-top: 10px; page-break-inside: avoid; }
    .section-title {
        font-size: 9px;
        font-weight: bold;
        letter-spacing: 0.6px;
        text-transform: uppercase;
        color: {{ $primaryColor }};
        background-color: {{ $primaryTint }};
        border-left: 3px solid {{ $primaryColor }};
        padding: 4px 8px;
    }

    /* Fields: label and value in the same font, size and weight. */
    .fields td {
        padding: 3.5px 0;
        border-bottom: 1px solid #eceef1;
    }
    .fields .label { width: 22%; color: #6b7280; padding-left: 8px; padding-right: 8px; }
    .fields .value { width: 26%; color: #111827; padding-right: 12px; }
    .fields .gap { width: 4%; border-bottom: none; }

    .note { color: #4b5563; line-height: 1.6; }
    .muted { color: #6b7280; }
    .accepted { color: #047857; }

    /* Footer bar on every page. */
    .doc-footer {
        position: fixed;
        bottom: -44px;
        left: 0;
        right: 0;
        background-color: {{ $primaryColor }};
        color: #ffffff;
        font-size: 8px;
    }
    .doc-footer td { padding: 7px 12px; color: #ffffff; }

    .page-break { page-break-after: always; }
</style>
