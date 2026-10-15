@php
    $presenter = $vm['presenter'];
    $selectedFile = $filters->file === null ? null : collect($vm['files'])->firstWhere('id', $filters->file);
@endphp
<section class="ipx-log-viewer__card ipx-log-viewer__entries" aria-labelledby="ipx-lv-entries-title">
    <div class="ipx-log-viewer__card-header">
        <div>
            <{{ $h }} class="ipx-log-viewer__card-title" id="ipx-lv-entries-title">{{ $t('table.heading') }}</{{ $h }}>
            @if ($result->total > 0)
                <p class="ipx-log-viewer__card-subtitle">{{ $t('table.showing', ['first' => number_format($result->firstItem()), 'last' => number_format($result->lastItem()), 'total' => number_format($result->total)]) }}</p>
            @endif
        </div>
        @if ($vm['routes']['download'] && $selectedFile && $selectedFile->readable)
            <a class="ipx-log-viewer__button ipx-log-viewer__button--secondary ipx-log-viewer__button--sm" href="{{ route($vm['routes']['download'], ['file' => $selectedFile->id]) }}" download>
                @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'download']){{ $t('detail.download') }}
            </a>
        @endif
    </div>

    @if ($result->total === 0)
        <div class="ipx-log-viewer__empty">
            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'file'])
            <p class="ipx-log-viewer__empty-title">{{ $t('table.empty_title') }}</p>
            @if ($vm['totalFiles'] === 0)
                <p class="ipx-log-viewer__empty-text">{{ $t('table.no_files') }}</p>
            @else
                <p class="ipx-log-viewer__empty-text">{{ $t('table.empty_filtered') }}</p>
                @if ($filters->preset !== \InertiaPixel\LaravelLogViewer\Query\DatePresets::ALL)
                    <a class="ipx-log-viewer__button ipx-log-viewer__button--secondary" href="{{ $vm['routes']['index'].$presenter->queryString($filters->query(['preset' => 'all', 'from' => null, 'to' => null, 'page' => null])) }}" data-ipx-lv-nav>{{ $t('table.empty_any_time') }}</a>
                @endif
            @endif
        </div>
    @else
        <div class="ipx-log-viewer__table-wrap">
            <table class="ipx-log-viewer__table ipx-log-viewer__table--entries">
                <caption class="ipx-log-viewer__sr-only">{{ $t('table.caption') }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--time">{{ $t('table.time') }}</th>
                        <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--level">{{ $t('table.level') }}</th>
                        <th scope="col" class="ipx-log-viewer__th">{{ $t('table.message') }}</th>
                        <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--channel">{{ $t('table.channel') }}</th>
                        <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--file">{{ $t('table.file') }}</th>
                        <th scope="col" class="ipx-log-viewer__th ipx-log-viewer__th--actions"><span class="ipx-log-viewer__sr-only">{{ $t('table.details') }}</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result->entries as $entry)
                        @php
                            $key = $entry->key();
                            $url = route($vm['routes']['entry'], ['file' => $entry->file->id, 'offset' => $entry->offset, 'h' => $entry->hash]);
                        @endphp
                        <tr class="ipx-log-viewer__row ipx-log-viewer__row--{{ $entry->level }}" data-ipx-lv-row="{{ $key }}">
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--time" data-label="{{ $t('table.time') }}">
                                @if ($entry->timestamp !== null)
                                    <time datetime="{{ $presenter->formatTime($entry->timestamp, DATE_ATOM) }}">{{ $presenter->formatTime($entry->timestamp) }}</time>
                                @else
                                    <span class="ipx-log-viewer__muted">{{ $t('table.no_timestamp') }}</span>
                                @endif
                            </td>
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--level" data-label="{{ $t('table.level') }}">
                                @include('inertiapixel-log-viewer::partials.badge', ['level' => $entry->level])
                            </td>
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--message" data-label="{{ $t('table.message') }}">
                                <span class="ipx-log-viewer__message">{{ $entry->summary !== '' ? $entry->summary : '—' }}</span>
                                @if ($entry->exceptionClass || $entry->hasTrace || $entry->truncated || $entry->format === 'unparsed')
                                    <span class="ipx-log-viewer__tags">
                                        @if ($entry->exceptionClass)<code class="ipx-log-viewer__tag ipx-log-viewer__tag--code">{{ $entry->exceptionClass }}</code>@endif
                                        @if ($entry->hasTrace)<span class="ipx-log-viewer__tag">{{ $t('table.stack_trace') }}</span>@endif
                                        @if ($entry->format === 'unparsed')<span class="ipx-log-viewer__tag">{{ $t('table.unparsed') }}</span>@endif
                                        @if ($entry->truncated)<span class="ipx-log-viewer__tag">{{ $t('table.truncated') }}</span>@endif
                                    </span>
                                @endif
                            </td>
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--channel" data-label="{{ $t('table.channel') }}">{{ $entry->channel ?? '—' }}</td>
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--file" data-label="{{ $t('table.file') }}">{{ $entry->file->name }}</td>
                            <td class="ipx-log-viewer__td ipx-log-viewer__td--actions">
                                <a class="ipx-log-viewer__button ipx-log-viewer__button--ghost ipx-log-viewer__button--sm" href="{{ $url }}" data-ipx-lv-detail-link>{{ $t('table.details') }}</a>
                                <button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--ghost ipx-log-viewer__button--sm ipx-log-viewer__disclosure" data-ipx-lv-detail="{{ $url }}"
                                        aria-expanded="false" aria-controls="ipx-lv-detail-{{ $key }}" hidden>
                                    @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'down'])<span class="ipx-log-viewer__sr-only">{{ $t('table.show_details') }}: </span><span aria-hidden="true">{{ $t('table.details') }}</span>
                                </button>
                            </td>
                        </tr>
                        <tr class="ipx-log-viewer__detail-row" id="ipx-lv-detail-{{ $key }}" data-ipx-lv-detail-row hidden>
                            <td class="ipx-log-viewer__detail-cell" colspan="6"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @include('inertiapixel-log-viewer::partials.pagination')
    @endif
</section>
