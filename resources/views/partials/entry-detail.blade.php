@php
    $t ??= fn (string $key, array $replace = []) => __('inertiapixel-log-viewer::log-viewer.'.$key, $replace);
    $dh ??= 'h4';
    $detail = $entry->detail;
    $id = 'ipx-lv-'.$entry->key();
    $features = $vm['features'];
    $time = $entry->timestamp === null ? null : \Carbon\CarbonImmutable::createFromTimestamp($entry->timestamp, $vm['timezone']);
    $copy = function (string $target, string $section) use ($features, $t) {
        if (! $features['copy']) {
            return '';
        }

        return '<button type="button" class="ipx-log-viewer__button ipx-log-viewer__button--ghost ipx-log-viewer__button--sm" data-ipx-lv-copy="'.e($target).'" aria-label="'.e($t('detail.copy_label', ['section' => $section])).'" hidden>'
            .view('inertiapixel-log-viewer::partials.icon', ['icon' => 'copy'])->render()
            .'<span data-ipx-lv-copy-text>'.e($t('detail.copy')).'</span></button>';
    };
@endphp
<div class="ipx-log-viewer__detail" data-ipx-lv-detail-content>
    <dl class="ipx-log-viewer__meta-list">
        <div class="ipx-log-viewer__meta-item">
            <dt>{{ $t('detail.logged_at') }}</dt>
            <dd>
                @if ($time)
                    <time datetime="{{ $time->format(DATE_ATOM) }}">{{ $time->format($vm['dateFormat']) }}</time> <span class="ipx-log-viewer__muted">({{ $vm['timezone'] }})</span>
                @else
                    <span class="ipx-log-viewer__muted">{{ $t('table.no_timestamp') }}</span>
                @endif
            </dd>
        </div>
        @if ($detail['datetime'])
            <div class="ipx-log-viewer__meta-item">
                <dt>{{ $t('detail.original_timestamp') }}</dt>
                <dd><code class="ipx-log-viewer__inline-code">{{ $detail['datetime'] }}</code></dd>
            </div>
        @endif
        <div class="ipx-log-viewer__meta-item">
            <dt>{{ $t('detail.level') }}</dt>
            <dd>@include('inertiapixel-log-viewer::partials.badge', ['level' => $entry->level])@if ($entry->levelName && strtolower($entry->levelName) !== $entry->level) <code class="ipx-log-viewer__inline-code">{{ $entry->levelName }}</code>@endif</dd>
        </div>
        @if ($entry->channel)
            <div class="ipx-log-viewer__meta-item">
                <dt>{{ $t('detail.channel') }}</dt>
                <dd>{{ $entry->channel }}</dd>
            </div>
        @endif
        <div class="ipx-log-viewer__meta-item">
            <dt>{{ $t('detail.file') }}</dt>
            <dd>{{ $entry->file->name }} <span class="ipx-log-viewer__muted">({{ $features['filePaths'] ? $entry->file->path : $entry->file->location }})</span></dd>
        </div>
        <div class="ipx-log-viewer__meta-item">
            <dt>{{ $t('detail.format') }}</dt>
            <dd>{{ $t('detail.formats.'.$entry->format) }}</dd>
        </div>
    </dl>

    @if ($entry->truncated)
        <div class="ipx-log-viewer__alert ipx-log-viewer__alert--notice">
            @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'info'])
            <p>{{ $t('detail.truncated', ['size' => $vm['maxEntrySize']]) }}</p>
        </div>
    @endif

    <section class="ipx-log-viewer__detail-section">
        <div class="ipx-log-viewer__detail-header">
            <{{ $dh }} class="ipx-log-viewer__detail-title">{{ $t('detail.message') }}</{{ $dh }}>
            {!! $copy($id.'-message', $t('detail.message')) !!}
        </div>
        <pre class="ipx-log-viewer__code" id="{{ $id }}-message">{{ $detail['message'] !== '' ? $detail['message'] : '—' }}</pre>
    </section>

    @if ($detail['exception'])
        <section class="ipx-log-viewer__detail-section">
            <div class="ipx-log-viewer__detail-header">
                <{{ $dh }} class="ipx-log-viewer__detail-title">{{ $t('detail.exception') }}</{{ $dh }}>
            </div>
            <dl class="ipx-log-viewer__exception">
                <div class="ipx-log-viewer__meta-item"><dt>{{ $t('detail.exception') }}</dt><dd><code class="ipx-log-viewer__inline-code">{{ $detail['exception']['class'] }}</code></dd></div>
                @if ($detail['exception']['code'] !== null && $detail['exception']['code'] !== '' && $detail['exception']['code'] !== '0')
                    <div class="ipx-log-viewer__meta-item"><dt>{{ $t('detail.exception_code') }}</dt><dd><code class="ipx-log-viewer__inline-code">{{ $detail['exception']['code'] }}</code></dd></div>
                @endif
                @if ($detail['exception']['message'] !== $detail['message'])
                    <div class="ipx-log-viewer__meta-item"><dt>{{ $t('detail.message') }}</dt><dd>{{ $detail['exception']['message'] }}</dd></div>
                @endif
                @if ($detail['exception']['file'])
                    <div class="ipx-log-viewer__meta-item"><dt>{{ $t('detail.exception_location') }}</dt><dd><code class="ipx-log-viewer__inline-code">{{ $detail['exception']['file'] }}@if ($detail['exception']['line']):{{ $detail['exception']['line'] }}@endif</code></dd></div>
                @endif
            </dl>
        </section>
    @endif

    @if ($features['stackTraces'] && $detail['stack_trace'])
        <section class="ipx-log-viewer__detail-section">
            <div class="ipx-log-viewer__detail-header">
                <{{ $dh }} class="ipx-log-viewer__detail-title">{{ $t('detail.stack_trace') }}</{{ $dh }}>
                {!! $copy($id.'-trace', $t('detail.stack_trace')) !!}
            </div>
            <pre class="ipx-log-viewer__code ipx-log-viewer__code--trace" id="{{ $id }}-trace">@foreach (explode("\n", $detail['stack_trace']) as $frame)<span class="ipx-log-viewer__frame{{ str_contains($frame, 'vendor/') ? ' ipx-log-viewer__frame--vendor' : '' }}">{{ $frame }}</span>
@endforeach</pre>
        </section>
    @endif

    @if ($features['context'] && $detail['context'])
        <section class="ipx-log-viewer__detail-section">
            <div class="ipx-log-viewer__detail-header">
                <{{ $dh }} class="ipx-log-viewer__detail-title">{{ $t('detail.context') }}</{{ $dh }}>
                {!! $copy($id.'-context', $t('detail.context')) !!}
            </div>
            <pre class="ipx-log-viewer__code" id="{{ $id }}-context">{{ $detail['context'] }}</pre>
        </section>
    @endif

    @if ($features['context'] && $detail['extra'])
        <section class="ipx-log-viewer__detail-section">
            <div class="ipx-log-viewer__detail-header">
                <{{ $dh }} class="ipx-log-viewer__detail-title">{{ $t('detail.extra') }}</{{ $dh }}>
                {!! $copy($id.'-extra', $t('detail.extra')) !!}
            </div>
            <pre class="ipx-log-viewer__code" id="{{ $id }}-extra">{{ $detail['extra'] }}</pre>
        </section>
    @endif

    @if ($features['raw'])
        <details class="ipx-log-viewer__detail-section ipx-log-viewer__raw">
            <summary class="ipx-log-viewer__summary">{{ $t('detail.raw') }}</summary>
            <div class="ipx-log-viewer__detail-header ipx-log-viewer__detail-header--end">
                {!! $copy($id.'-raw', $t('detail.raw')) !!}
            </div>
            <pre class="ipx-log-viewer__code" id="{{ $id }}-raw">{{ $detail['raw'] }}</pre>
        </details>
    @endif
</div>
