{{-- The host's logo, faint, behind every page. --}}
@if($logoBase64)
    <div class="watermark">
        <img src="{{ $logoBase64 }}" alt="" style="width: auto; height: auto; max-width: 480px; max-height: 150px;">
    </div>
@endif

{{-- The document header: the host's logo (or name), the title, and the brand band. --}}
<table class="doc-header">
    <tr>
        <td style="width: 45%;">
            @if($logoBase64)
                {{-- Sized in PHP to fit a 200x60 box: dompdf ignores object-fit. --}}
                <img src="{{ $logoBase64 }}" alt="{{ $companyName }}"
                    width="{{ $logoWidth }}" height="{{ $logoHeight }}"
                    style="width: {{ $logoWidth }}px; height: {{ $logoHeight }}px; display: block;">
            @else
                <div class="doc-company">{{ $companyName }}</div>
            @endif
        </td>
        <td style="width: 55%;">
            <div class="doc-title">{{ strtoupper($title) }}</div>
        </td>
    </tr>
</table>
<table class="doc-band">
    <tr>
        <td style="width: 50%;"><span class="band-label">Reference:</span> {{ $appId }}</td>
        <td style="width: 50%; text-align: right;"><span class="band-label">Generated:</span> {{ toDateOnly($currentTime, 'M j, Y') }}</td>
    </tr>
</table>
