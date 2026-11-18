{{--
    One section of the Owner Summary report, shared by the PDF and the email body
    so the two say the same thing. Pick it with $section; $summary is an
    OwnerReportPresenter. Inline styles and tables only: email clients and dompdf
    both handle those, and neither handles flex or grid. Bold is always 700: dompdf
    has no 600 weight and silently falls back to a serif face.
--}}
@php
    use App\Services\FCH\Common\OwnerSummary\OwnerReportPresenter as Present;

    // The period's own words for every :when ("last week" in the weekly report).
    $r = fn (string $key, array $replace = []) => $summary->t($key, $replace);
    $tag = fn (string $tone) => 'display: inline-block; padding: 3px 7px; border-radius: 4px; font-size: 11px; font-weight: 700; white-space: nowrap; background-color: ' . Present::TONES[$tone]['background'] . '; color: ' . Present::TONES[$tone]['color'] . ';';

    $card = 'width: 100%; border-collapse: separate; border-spacing: 0; background-color: #ffffff; border: 1px solid #e9ebec; border-radius: 4px;';
    $pad = 'padding: 14px;';
    // Fixed, so a wide table scrolls inside its card instead of stretching it past a phone's edge.
    $wideCard = $card . ' table-layout: fixed;';
    $title = 'margin: 0; font-size: 14px; font-weight: 700; color: #212529;';
    $sub = 'margin: 3px 0 0; font-size: 11.5px; line-height: 1.4; color: #5c6670;';
    $line = 'padding: 5px 0; font-size: 12.5px; color: #212529;';
    $muted = 'padding: 5px 0; font-size: 12.5px; color: #5c6670;';
    $num = 'text-align: right; font-weight: 700; color: #212529; white-space: nowrap;';
    $th = 'padding: 8px 5px; text-align: left; font-size: 11px; font-weight: 700; color: #5c6670; background-color: #f8f9fa; border-bottom: 1px solid #e9ebec;';
    $thNum = $th . ' text-align: right;';
    $td = 'padding: 8px 5px; font-size: 12px; color: #212529; border-bottom: 1px solid #f1f2f4;';
    $tdNum = $td . ' text-align: right; white-space: nowrap;';
    $note = 'margin: 10px 0 0; font-size: 11px; line-height: 1.45; color: #5c6670;';
    $empty = 'margin: 0; padding: 18px 8px; text-align: center; font-size: 12.5px; color: #5c6670;';
    $table = 'width: 100%; border-collapse: collapse;';
@endphp

@switch($section)
    @case('kpis')
        {{-- $columns cards to a row, all $height tall where narrow cards would otherwise wrap unevenly. --}}
        <table class="detail-grid" role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: separate; border-spacing: 0;">
            @foreach (array_chunk($summary->kpis(), $columns) as $row)
                <tr>
                    @foreach ($row as $i => $kpi)
                        <td class="detail-item" width="{{ floor(100 / $columns) }}%" valign="top" style="width: {{ floor(100 / $columns) }}%; padding: 0 {{ $i < $columns - 1 ? 8 : 0 }}px 8px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
                                <tr><td style="padding: 12px; @isset($height) height: {{ $height }}px; @endisset">
                                    <div style="font-size: 11.5px; font-weight: 700; color: #5c6670;">{{ $kpi['label'] }}</div>
                                    <div style="margin: 6px 0; font-size: 22px; font-weight: 700; line-height: 1.2; color: #001f3f;">{{ $kpi['value'] }}</div>
                                    @if ($kpi['tag'])
                                        <span style="{{ $tag($kpi['tone']) }}">{{ $kpi['tag'] }}</span>
                                    @endif
                                    @if ($kpi['note'])
                                        <span style="font-size: 11px; color: #5c6670;">{{ $kpi['note'] }}</span>
                                    @endif
                                    @if ($kpi['rows'])
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; margin-top: 6px; border-collapse: collapse; font-size: 11px;">
                                            @foreach ($kpi['rows'] as $line)
                                                <tr>
                                                    <td style="padding: 2px 0; color: #5c6670;">{{ $line['label'] }}</td>
                                                    <td style="padding: 2px 0; text-align: right; font-weight: 700; color: #212529; white-space: nowrap;">{{ $line['value'] }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif
                                </td></tr>
                            </table>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
        @break

    @case('attention')
        {{-- $columns items to a row; Review links only where they can be clicked ($links). --}}
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="padding: 14px 16px; border-bottom: 1px solid #e9ebec;">
                <p style="{{ $title }}">{{ $r('attention') }}</p>
                <p style="{{ $sub }}">{{ $r('attention_sub') }}</p>
            </td></tr>
            <tr><td style="padding: 0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $table }}">
                    @foreach (array_chunk($summary->attention(), $columns) as $row)
                        <tr>
                            @foreach ($row as $item)
                                <td width="{{ floor(100 / $columns) }}%" valign="top" style="width: {{ floor(100 / $columns) }}%; padding: 10px 16px; border-bottom: 1px solid #f1f2f4;">
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $table }}">
                                        <tr>
                                            <td valign="middle">
                                                <div style="font-size: 12.5px; font-weight: 700; color: #212529;">{{ $item['label'] }}</div>
                                                <div style="margin-top: 2px; font-size: 11px; color: #5c6670;">{{ $item['sub'] }}</div>
                                            </td>
                                            <td valign="middle" align="right" style="padding-left: 8px; white-space: nowrap; text-align: right;">
                                                <span style="font-size: 16px; font-weight: 700; color: {{ Present::COUNT_COLORS[$item['tone']] }};">{{ $item['count'] }}</span>
                                                @if ($links)
                                                    <a href="{{ $item['url'] }}" style="margin-left: 8px; font-size: 12px; font-weight: 700; color: #001f3f;">{{ $r('review') }}</a>
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            @endforeach
                            @for ($i = count($row); $i < $columns; $i++)
                                <td style="border-bottom: 1px solid #f1f2f4;"></td>
                            @endfor
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        </table>
        @break

    @case('cash')
        @php($streams = $summary->streams())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('cash_collected') }}</p>
                <p style="{{ $sub }}">{{ $r('cash_sub', ['company' => $summary->company]) }}</p>
                <table cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px;">
                    <tr>
                        <th style="{{ $th }}">{{ $r('stream') }}</th>
                        <th style="{{ $thNum }}">{{ $r('payments') }}</th>
                        <th style="{{ $thNum }}">{{ $summary->periodColumn() }}</th>
                    </tr>
                    @foreach ($streams['rows'] as $row)
                        <tr>
                            <td style="{{ $td }}">
                                <div style="font-weight: 700;">{{ $row['label'] }}</div>
                                <div style="margin-top: 2px; font-size: 11px; color: #5c6670;">{{ $row['note'] }}</div>
                            </td>
                            <td style="{{ $tdNum }}">{{ $row['payments'] }}</td>
                            <td style="{{ $tdNum }}">{{ $row['amount'] }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td style="padding: 9px 8px; font-size: 12.5px; font-weight: 700; color: #001f3f; background-color: #f4f7fb;">{{ $r('total_collected') }}</td>
                        <td style="padding: 9px 8px; font-size: 12.5px; font-weight: 700; color: #001f3f; background-color: #f4f7fb; text-align: right; white-space: nowrap;">{{ $streams['payments'] }}</td>
                        <td style="padding: 9px 8px; font-size: 12.5px; font-weight: 700; color: #001f3f; background-color: #f4f7fb; text-align: right; white-space: nowrap;">{{ $streams['amount'] }}</td>
                    </tr>
                </table>
                <p style="{{ $note }}">{{ $r('cash_note', ['company' => $summary->company]) }}</p>
            </td></tr>
        </table>
        @break

    @case('commissions')
        @php($c = $summary->commissions())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('commissions') }}</p>
                <p style="{{ $sub }}">{{ $r('commissions_sub') }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 8px;">
                    <tr><td style="{{ $line }}">{{ $r('host_share') }}</td><td style="{{ $line }} {{ $num }}">{{ $c['host_share'] }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ $r('partner_share') }}</td><td style="{{ $line }} {{ $num }}">{{ $c['partner_share'] }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ $r('paid_to_partners') }}</td><td style="{{ $line }} {{ $num }}">{{ $c['paid_to_partners'] }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ $r('paid_to_staff') }}</td><td style="{{ $line }} {{ $num }}">{{ $c['paid_to_staff'] }}</td></tr>
                    <tr>
                        <td style="padding: 8px 0 0; font-size: 12.5px; font-weight: 700; color: #b54708; border-top: 1px solid #e9ebec;">{{ $r('payouts_due') }}</td>
                        <td style="padding: 8px 0 0; font-size: 12.5px; font-weight: 700; color: #b54708; border-top: 1px solid #e9ebec; text-align: right; white-space: nowrap;">{{ $c['payouts_due'] }}</td>
                    </tr>
                </table>
            </td></tr>
        </table>
        @break

    @case('owed')
        @php($owed = $summary->owed())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('owed_to', ['company' => $summary->company]) }}</p>
                <p style="{{ $sub }}">{{ $r('owed_sub') }}</p>
                <div style="margin: 10px 0 8px;">
                    <span style="font-size: 24px; font-weight: 700; color: #001f3f;">{{ $owed['amount'] }}</span>
                    <span style="margin-left: 6px; font-size: 12.5px; color: #5c6670;">{{ $owed['invoices'] }}</span>
                </div>
                @include('owner_summary.stackbar', ['parts' => $owed['parts']])
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 8px;">
                    @foreach ($owed['parts'] as $part)
                        <tr>
                            <td width="16" style="width: 16px; padding: 5px 0;"><div style="width: 10px; height: 10px; border-radius: 2px; background-color: {{ $part['color'] }};"></div></td>
                            <td style="padding: 5px 0; font-size: 12.5px; color: #212529;">{{ $part['label'] }}</td>
                            <td style="padding: 5px 0; font-size: 12.5px; {{ $num }}">{{ $part['detail'] }}</td>
                        </tr>
                    @endforeach
                </table>
            </td></tr>
        </table>
        @break

    @case('pipeline')
        @php($p = $summary->pipeline())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }}">
                    <tr>
                        <td><p style="{{ $title }}">{{ $r('pipeline') }}</p></td>
                        <td align="right" style="text-align: right; font-size: 12px; color: #5c6670;">{{ $p['summary'] }}</td>
                    </tr>
                </table>
                <p style="{{ $sub }}">{{ $r('pipeline_sub') }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px;">
                    @foreach ($p['stages'] as $stage)
                        <tr>
                            <td width="36%" style="width: 36%; padding: 6px 8px 6px 0;">
                                <div style="font-size: 12.5px; font-weight: 700; color: #212529;">{{ $stage['label'] }}</div>
                                <div style="font-size: 10.5px; color: #5c6670;">{{ $stage['statuses'] }}</div>
                            </td>
                            <td valign="middle" style="padding: 6px 0;">
                                <div style="height: 16px; background-color: #f1f3f5; border-radius: 4px;">
                                    @if ($stage['percent'] > 0)
                                        <div style="width: {{ $stage['percent'] }}%; height: 16px; background-color: {{ $stage['color'] }}; border-radius: 4px;"></div>
                                    @endif
                                </div>
                            </td>
                            <td width="84" align="right" style="width: 84px; padding: 6px 0 6px 8px; text-align: right;">
                                <div style="font-size: 12.5px; font-weight: 700; color: #212529;">{{ $stage['count'] }}</div>
                                <div style="font-size: 10.5px; color: #5c6670;">{{ $stage['requested'] }}</div>
                            </td>
                        </tr>
                    @endforeach
                </table>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px; border-top: 1px solid #e9ebec;">
                    <tr>
                        @foreach ($p['activity'] as $activity)
                            <td valign="top" style="padding: 8px 6px 0 0;">
                                <div style="font-size: 10.5px; color: #5c6670;">{{ $activity['label'] }}</div>
                                <div style="margin-top: 2px; font-size: 15px; font-weight: 700; color: #212529;">{{ $activity['count'] }}</div>
                            </td>
                        @endforeach
                    </tr>
                </table>
                <p style="{{ $note }}">{{ $p['note'] }}</p>
            </td></tr>
        </table>
        @break

    @case('leads')
        @php($leads = $summary->leadSources())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('leads') }}</p>
                <p style="{{ $sub }}">{{ $leads['summary'] }}</p>
                @if ($leads['rows'] === [])
                    <p style="{{ $sub }} margin-top: 10px;">{{ $r('no_leads') }}</p>
                @else
                    <div style="margin-top: 10px;">@include('owner_summary.stackbar', ['parts' => $leads['rows']])</div>
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 8px;">
                        @foreach ($leads['rows'] as $row)
                            <tr>
                                <td width="16" style="width: 16px; padding: 5px 0;"><div style="width: 10px; height: 10px; border-radius: 2px; background-color: {{ $row['color'] }};"></div></td>
                                <td style="padding: 5px 0; font-size: 12.5px; color: #212529;">{{ $row['name'] }}</td>
                                <td style="padding: 5px 0; font-size: 12.5px; {{ $num }}">{{ $row['count'] }}</td>
                                <td width="40" style="width: 40px; padding: 5px 0; font-size: 12.5px; text-align: right; color: #5c6670;">{{ $row['percent'] }}%</td>
                            </tr>
                        @endforeach
                    </table>
                @endif
                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ebec; font-size: 11.5px; font-weight: 700; color: #5c6670;">{{ $r('other_ways') }}</div>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }}">
                    <tr><td style="{{ $line }}">{{ $r('prequal_requests') }}</td><td style="{{ $line }} {{ $num }}">{{ $leads['prequalifications'] }}</td></tr>
                    <tr><td style="{{ $line }}">{{ $r('embedded_applications') }}</td><td style="{{ $line }} {{ $num }}">{{ $leads['embedded_applications'] }}</td></tr>
                </table>
            </td></tr>
        </table>
        @break

    @case('chart')
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }}">
                    <tr>
                        <td><p style="{{ $title }}">{{ $chart['title'] }}</p></td>
                        @if ($chart['trend'])
                            <td align="right" style="text-align: right;"><span style="{{ $tag($chart['trend']['tone']) }}">{{ $chart['trend']['text'] }}</span></td>
                        @endif
                    </tr>
                </table>
                <p style="{{ $sub }}">{{ $chart['sub'] }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px;">
                    <tr>
                        @foreach ($chart['bars'] as $bar)
                            <td valign="bottom" align="center" height="{{ Present::CHART_BAR_AREA + 22 }}" style="height: {{ Present::CHART_BAR_AREA + 22 }}px; padding: 0 4px; text-align: center; vertical-align: bottom;">
                                <div style="margin-bottom: 4px; font-size: 10px; color: #5c6670; white-space: nowrap;">{{ $bar['value'] }}</div>
                                @if ($bar['height'] > 0)
                                    <div style="height: {{ $bar['height'] }}px; background-color: {{ $bar['current'] ? '#001f3f' : '#bcd0e6' }}; border-radius: 4px 4px 0 0;"></div>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr>
                        @foreach ($chart['bars'] as $bar)
                            <td align="center" style="padding-top: 5px; text-align: center; font-size: 11px; color: #5c6670;">{{ $bar['month'] }}</td>
                        @endforeach
                    </tr>
                </table>
            </td></tr>
        </table>
        @break

    @case('network')
        @php($n = $summary->network())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $card }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('network') }}</p>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px;">
                    <tr>
                        <td width="50%" style="width: 50%; padding-right: 4px;">
                            <div style="padding: 10px; border-radius: 4px; background-color: #eef2fe;">
                                <div style="font-size: 11.5px; font-weight: 700; color: #2f4fb8;">{{ $r('white_label') }}</div>
                                <div style="font-size: 20px; font-weight: 700; color: #001f3f;">{{ $n['white_label'] }}</div>
                            </div>
                        </td>
                        <td width="50%" style="width: 50%; padding-left: 4px;">
                            <div style="padding: 10px; border-radius: 4px; background-color: #f1eefc;">
                                <div style="font-size: 11.5px; font-weight: 700; color: #4b3bb8;">{{ $r('certified') }}</div>
                                <div style="font-size: 20px; font-weight: 700; color: #001f3f;">{{ $n['certified'] }}</div>
                            </div>
                        </td>
                    </tr>
                </table>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 8px;">
                    <tr><td style="{{ $line }}">{{ $r('active_line') }}</td><td style="{{ $line }} {{ $num }}">{{ $n['active'] }}</td></tr>
                </table>
                <div style="height: 6px; background-color: #f1f3f5; border-radius: 4px;">
                    @if ($n['active_percent'] > 0)
                        <div style="width: {{ $n['active_percent'] }}%; height: 6px; background-color: #001f3f; border-radius: 4px;"></div>
                    @endif
                </div>
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 4px;">
                    <tr><td style="{{ $muted }}">{{ $r('new_period') }}</td><td style="{{ $line }} {{ $num }}">{{ $n['new'] }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ $r('on_trial') }}</td><td style="{{ $line }} {{ $num }}">{{ $n['on_trial'] }}</td></tr>
                    <tr><td style="{{ $muted }}">{{ $r('suspended') }}</td><td style="{{ $line }} {{ $num }} @if ($n['suspended'] > 0) color: #b42318; @endif">{{ $n['suspended'] }}</td></tr>
                </table>
            </td></tr>
        </table>
        @break

    @case('top_partners')
        @php($partners = $summary->topPartners())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $wideCard }}">
            <tr><td style="{{ $pad }}">
                <p style="{{ $title }}">{{ $r('top_partners') }}</p>
                <p style="{{ $sub }}">{{ $r('top_partners_sub') }}</p>
                @if ($partners === [])
                    <p style="{{ $empty }}">{{ $r('no_partners') }}</p>
                @else
                    {{-- Scrolls sideways rather than losing columns on a phone. --}}
                    <div style="overflow-x: auto;">
                    <table cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px;">
                        <tr>
                            <th style="{{ $th }}">{{ $r('partner') }}</th>
                            <th style="{{ $th }}">{{ $r('type') }}</th>
                            <th style="{{ $thNum }}">{{ $r('leads_column') }}</th>
                            <th style="{{ $thNum }}">{{ $r('funded_column') }}</th>
                            <th style="{{ $thNum }}">{{ $r('volume') }}</th>
                            <th style="{{ $thNum }}">{{ $r('commission_paid') }}</th>
                        </tr>
                        @foreach ($partners as $partner)
                            <tr>
                                <td style="{{ $td }} font-weight: 700;">{{ $partner['name'] }}</td>
                                <td style="{{ $td }}"><span style="{{ $tag($partner['type_tone']) }}">{{ $partner['type'] }}</span></td>
                                <td style="{{ $tdNum }}">{{ $partner['leads'] }}</td>
                                <td style="{{ $tdNum }}">{{ $partner['funded'] }}</td>
                                <td style="{{ $tdNum }} font-weight: 700;">{{ $partner['volume'] }}</td>
                                <td style="{{ $tdNum }}">{{ $partner['paid'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                    </div>
                @endif
            </td></tr>
        </table>
        @break

    @case('stalled')
        @php($stalled = $summary->stalled())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $wideCard }}">
            <tr><td style="{{ $pad }}">
                <span style="{{ $title }}">{{ $r('stalled') }}</span>
                <span style="{{ $tag('warn') }} margin-left: 6px;">{{ $stalled['summary'] }}</span>
                <p style="{{ $sub }}">{{ $stalled['sub'] }}</p>
                @if ($stalled['rows'] === [])
                    <p style="{{ $empty }}">{{ $r('no_stalled') }}</p>
                @else
                    <div style="overflow-x: auto;">
                    <table cellpadding="0" cellspacing="0" border="0" style="{{ $table }} margin-top: 10px;">
                        <tr>
                            <th style="{{ $th }}">{{ $r('application') }}</th>
                            <th style="{{ $th }}">{{ $r('business') }}</th>
                            <th style="{{ $th }}">{{ $r('partner') }}</th>
                            <th style="{{ $th }}">{{ $r('status') }}</th>
                            <th style="{{ $thNum }}">{{ $r('requested') }}</th>
                            <th style="{{ $thNum }}">{{ $r('days_idle') }}</th>
                        </tr>
                        @foreach ($stalled['rows'] as $deal)
                            <tr>
                                <td style="{{ $td }} font-weight: 700; color: #001f3f;">{{ $deal['application'] }}</td>
                                <td style="{{ $td }}">{{ $deal['business'] }}</td>
                                <td style="{{ $td }}">{{ $deal['partner'] }}</td>
                                <td style="{{ $td }}"><span style="{{ $tag('status') }} white-space: normal;">{{ $deal['status'] }}</span></td>
                                <td style="{{ $tdNum }}">{{ $deal['requested'] }}</td>
                                <td style="{{ $tdNum }} font-weight: 700; color: {{ $deal['long'] ? '#b42318' : '#93370d' }};">{{ $deal['idle'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                    </div>
                    <p style="{{ $note }}">{{ $stalled['showing'] }}</p>
                @endif
            </td></tr>
        </table>
        @break

    @case('definitions')
        <div style="font-size: 11px; line-height: 1.5; color: #5c6670;">
            <p style="margin: 0 0 5px;"><strong style="color: #212529;">{{ $r('definitions_title') }}</strong> {{ $r('definitions_timezone', ['timezone' => $summary->timezone()]) }}</p>
            <p style="margin: 0 0 5px;">{{ $r('definitions', ['company' => $summary->company, 'days' => \App\Services\FCH\Common\OwnerSummary\OwnerSummaryService::STALLED_AFTER_DAYS]) }}</p>
            <p style="margin: 0;">{{ $r('definitions_missing') }}</p>
        </div>
        @break
@endswitch
