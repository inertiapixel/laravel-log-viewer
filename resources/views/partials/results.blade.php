@php
    $t ??= fn (string $key, array $replace = []) => __('inertiapixel-log-viewer::log-viewer.'.$key, $replace);
    $result = $vm['result'];
    $filters = $vm['filters'];
    $h = $vm['layout'] ? 'h3' : 'h2';
@endphp
<div class="ipx-log-viewer__fragment"
     data-ipx-lv-fragment
     data-ipx-lv-state="{{ json_encode($vm['state'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}"
     data-ipx-lv-channels="{{ json_encode($vm['channels'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}"
     data-ipx-lv-total="{{ $result->total }}">

    @if ($vm['activeFilters'] !== [])
        <div class="ipx-log-viewer__active-filters">
            <span class="ipx-log-viewer__active-label">{{ $t('filters.active') }}:</span>
            <ul class="ipx-log-viewer__chips">
                @foreach ($vm['activeFilters'] as $chip)
                    <li class="ipx-log-viewer__chip">
                        <span class="ipx-log-viewer__chip-label">{{ $chip['label'] }}:</span>
                        <span class="ipx-log-viewer__chip-value">{{ $chip['value'] }}</span>
                        <a class="ipx-log-viewer__chip-remove" href="{{ $chip['url'] }}" data-ipx-lv-nav aria-label="{{ $t('filters.remove', ['filter' => $chip['label'].': '.$chip['value']]) }}">
                            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'close'])
                        </a>
                    </li>
                @endforeach
            </ul>
            <a class="ipx-log-viewer__link" href="{{ $vm['resetUrl'] }}" data-ipx-lv-nav>{{ $t('filters.clear_all') }}</a>
        </div>
    @endif

    @if ($vm['errors'] !== [])
        <div class="ipx-log-viewer__alert ipx-log-viewer__alert--danger" role="alert">
            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'alert'])
            <div>
                <p class="ipx-log-viewer__alert-title">{{ $t('errors.heading') }}</p>
                <ul class="ipx-log-viewer__alert-list">
                    @foreach ($vm['errors'] as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @foreach ($result->notices as $notice)
        <div class="ipx-log-viewer__alert ipx-log-viewer__alert--notice">
            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'info'])
            <p>{{ $notice }}</p>
        </div>
    @endforeach

    @if ($vm['enabled']['summary'])
        <ul class="ipx-log-viewer__stats" aria-label="{{ $t('summary.heading') }}">
            <li class="ipx-log-viewer__card ipx-log-viewer__stat">
                <span class="ipx-log-viewer__stat-label">{{ $t('summary.total') }}</span>
                <span class="ipx-log-viewer__stat-value" data-ipx-lv-stat="total">{{ number_format($result->total) }}</span>
                @if ($result->partial)<span class="ipx-log-viewer__stat-note">{{ $t('summary.partial') }}</span>@endif
            </li>
            <li class="ipx-log-viewer__card ipx-log-viewer__stat ipx-log-viewer__stat--errors">
                <span class="ipx-log-viewer__stat-label" title="{{ $t('summary.errors_hint') }}">{{ $t('summary.errors') }}</span>
                <span class="ipx-log-viewer__stat-value" data-ipx-lv-stat="errors">{{ number_format($result->errorCount()) }}</span>
                <span class="ipx-log-viewer__stat-note">{{ $t('summary.errors_hint') }}</span>
            </li>
            <li class="ipx-log-viewer__card ipx-log-viewer__stat ipx-log-viewer__stat--warnings">
                <span class="ipx-log-viewer__stat-label">{{ $t('summary.warnings') }}</span>
                <span class="ipx-log-viewer__stat-value" data-ipx-lv-stat="warnings">{{ number_format($result->levels['warning'] ?? 0) }}</span>
            </li>
            <li class="ipx-log-viewer__card ipx-log-viewer__stat">
                <span class="ipx-log-viewer__stat-label">{{ $t('summary.files') }}</span>
                <span class="ipx-log-viewer__stat-value" data-ipx-lv-stat="files">{{ number_format(count($result->scannedFiles)) }}</span>
            </li>
        </ul>
    @endif

    @if ($vm['enabled']['charts'])
        @include('inertiapixel-log-viewer::partials.charts')
    @endif

    @include('inertiapixel-log-viewer::partials.entries')
</div>
