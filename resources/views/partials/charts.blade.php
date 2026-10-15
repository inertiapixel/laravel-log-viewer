@php
    $charts = $vm['charts'];
    $json = fn (array $value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
@endphp
<div class="ipx-log-viewer__charts">
    <section class="ipx-log-viewer__card ipx-log-viewer__chart-card ipx-log-viewer__chart-card--wide" aria-labelledby="ipx-lv-trend-title">
        <div class="ipx-log-viewer__card-header">
            <div>
                <{{ $h }} class="ipx-log-viewer__card-title" id="ipx-lv-trend-title">{{ $t('charts.trend') }}</{{ $h }}>
                @if ($charts['trend'])
                    <p class="ipx-log-viewer__card-subtitle">{{ $t('charts.trend_description', ['interval' => $charts['trend']['interval']]) }}</p>
                @endif
            </div>
            @if ($charts['trend'])
                <ul class="ipx-log-viewer__legend">
                    @foreach (array_reverse($charts['trend']['series']) as $series)
                        <li class="ipx-log-viewer__legend-item"><span class="ipx-log-viewer__swatch ipx-log-viewer__swatch--{{ $series['key'] }}" aria-hidden="true"></span>{{ $series['label'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($charts['trend'])
            <div class="ipx-log-viewer__chart ipx-log-viewer__chart--trend" data-ipx-lv-chart="trend" data-ipx-lv-chart-data="{{ $json($charts['trend']) }}">
                <canvas class="ipx-log-viewer__canvas" role="img" aria-label="{{ $charts['trend']['summary'] }}"></canvas>
            </div>
            <details class="ipx-log-viewer__data-table" open data-ipx-lv-chart-table>
                <summary class="ipx-log-viewer__summary">{{ $t('charts.show_table') }}</summary>
                <div class="ipx-log-viewer__table-wrap">
                    <table class="ipx-log-viewer__table ipx-log-viewer__table--compact">
                        <caption class="ipx-log-viewer__sr-only">{{ $t('charts.trend') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="ipx-log-viewer__th">{{ $t('charts.period') }}</th>
                                @foreach (array_reverse($charts['trend']['series']) as $series)
                                    <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--number">{{ $series['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($charts['trend']['labels'] as $i => $label)
                                <tr>
                                    <th scope="row" class="ipx-log-viewer__td">{{ $label }}</th>
                                    @foreach (array_reverse($charts['trend']['series']) as $series)
                                        <td class="ipx-log-viewer__td ipx-log-viewer__td--number">{{ number_format($series['data'][$i]) }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @else
            <p class="ipx-log-viewer__empty-inline">{{ $t('charts.empty') }}</p>
        @endif
    </section>

    <section class="ipx-log-viewer__card ipx-log-viewer__chart-card" aria-labelledby="ipx-lv-levels-title">
        <div class="ipx-log-viewer__card-header">
            <{{ $h }} class="ipx-log-viewer__card-title" id="ipx-lv-levels-title">{{ $t('charts.levels') }}</{{ $h }}>
        </div>

        @if ($result->total > 0)
            <div class="ipx-log-viewer__chart ipx-log-viewer__chart--levels" data-ipx-lv-chart="levels" data-ipx-lv-chart-data="{{ $json(['levels' => $charts['levels']]) }}">
                <canvas class="ipx-log-viewer__canvas" role="img" aria-label="{{ $charts['levelsSummary'] }}"></canvas>
            </div>
            <details class="ipx-log-viewer__data-table" open data-ipx-lv-chart-table>
                <summary class="ipx-log-viewer__summary">{{ $t('charts.show_table') }}</summary>
                <div class="ipx-log-viewer__table-wrap">
                    <table class="ipx-log-viewer__table ipx-log-viewer__table--compact">
                        <caption class="ipx-log-viewer__sr-only">{{ $t('charts.levels') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col" class="ipx-log-viewer__th">{{ $t('charts.level') }}</th>
                                <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--number">{{ $t('charts.count') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($charts['levels'] as $row)
                                <tr>
                                    <th scope="row" class="ipx-log-viewer__td">@include('inertiapixel-log-viewer::partials.badge', ['level' => $row['key']])</th>
                                    <td class="ipx-log-viewer__td ipx-log-viewer__td--number">{{ number_format($row['count']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @else
            <p class="ipx-log-viewer__empty-inline">{{ $t('charts.empty') }}</p>
        @endif
    </section>

    @if ($result->topMessages !== [])
        <section class="ipx-log-viewer__card ipx-log-viewer__chart-card ipx-log-viewer__chart-card--full" aria-labelledby="ipx-lv-top-title">
            <div class="ipx-log-viewer__card-header">
                <{{ $h }} class="ipx-log-viewer__card-title" id="ipx-lv-top-title">{{ $t('charts.top_messages') }}</{{ $h }}>
            </div>
            <ol class="ipx-log-viewer__top-messages">
                @foreach ($result->topMessages as $top)
                    @php
                        $searchable = ! str_contains($top['message'], '[REDACTED]') && ! str_ends_with($top['message'], '…') && $vm['enabled']['search'];
                    @endphp
                    <li class="ipx-log-viewer__top-message">
                        @include('inertiapixel-log-viewer::partials.badge', ['level' => $top['level']])
                        @if ($searchable)
                            <a class="ipx-log-viewer__top-text" href="{{ $vm['routes']['index'].$vm['presenter']->queryString($filters->query(['q' => mb_substr($top['message'], 0, 200), 'page' => null, 'regex' => null])) }}" data-ipx-lv-nav title="{{ $t('charts.search_message') }}">{{ $top['message'] }}</a>
                        @else
                            <span class="ipx-log-viewer__top-text">{{ $top['message'] }}</span>
                        @endif
                        <span class="ipx-log-viewer__top-count">{{ $t('charts.occurrences', ['count' => number_format($top['count'])]) }}</span>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif
</div>
