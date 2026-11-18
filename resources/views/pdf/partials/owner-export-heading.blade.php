{{-- A section heading: the teal bar, an optional number ("01"), the title, and its description on the right. --}}
<table class="full sh">
    <tr>
        <td class="sh-title"><span class="sh-bar"></span>@if (!empty($no))<span class="sh-no">{{ $no }}</span>@endif{{ $heading }}</td>
        <td class="sh-sub">{{ $sub }}</td>
    </tr>
</table>
