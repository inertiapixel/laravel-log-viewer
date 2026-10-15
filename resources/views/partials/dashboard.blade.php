@php
    $t = fn (string $key, array $replace = []) => __('inertiapixel-log-viewer::log-viewer.'.$key, $replace);
    $titleTag = $vm['layout'] ? 'h2' : 'h1';
@endphp
<div class="ipx-log-viewer ipx-log-viewer--theme-{{ $vm['theme'] }}"
     data-ipx-log-viewer
     data-ipx-log-viewer-config="{{ json_encode($vm['client'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) }}">
    @if ($vm['layout'])
        {!! $vm['styles'] !!}
        {!! $vm['scripts'] !!}
    @endif

    <a class="ipx-log-viewer__skip-link" href="#ipx-lv-results">{{ $t('skip_to_results') }}</a>

    <header class="ipx-log-viewer__header">
        <div class="ipx-log-viewer__heading">
            @if ($vm['backUrl'])
                <a class="ipx-log-viewer__back" href="{{ $vm['backUrl'] }}">@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'back']){{ $t('back') }}</a>
            @endif
            <{{ $titleTag }} class="ipx-log-viewer__title">{{ $vm['title'] }}</{{ $titleTag }}>
            <p class="ipx-log-viewer__subtitle">
                {{ trans_choice('inertiapixel-log-viewer::log-viewer.subtitle', $vm['totalFiles'], ['count' => $vm['totalFiles']]) }}
                <span aria-hidden="true">·</span>
                {{ $t('filters.date_hint', ['timezone' => $vm['timezone']]) }}
            </p>
        </div>
        <div class="ipx-log-viewer__header-actions">
            @if ($vm['enabled']['autoRefresh'])
                <label class="ipx-log-viewer__switch" data-ipx-lv-auto-refresh-field hidden>
                    <input type="checkbox" class="ipx-log-viewer__switch-input" data-ipx-lv-auto-refresh>
                    <span class="ipx-log-viewer__switch-track" aria-hidden="true"></span>
                    <span>{{ $t('auto_refresh', ['seconds' => $vm['autoRefreshInterval']]) }}</span>
                </label>
            @endif
            <a class="ipx-log-viewer__button ipx-log-viewer__button--secondary" href="{{ $vm['routes']['index'].$vm['presenter']->queryString($vm['filters']->query()) }}" data-ipx-lv-refresh>
                @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'refresh']){{ $t('refresh') }}
            </a>
        </div>
    </header>

    @include('inertiapixel-log-viewer::partials.filters')

    <p class="ipx-log-viewer__sr-only" role="status" data-ipx-lv-status></p>

    <section id="ipx-lv-results" class="ipx-log-viewer__results" aria-label="{{ $t('table.heading') }}" data-ipx-lv-results tabindex="-1">
        @include('inertiapixel-log-viewer::partials.results')
    </section>
</div>
