@php
    $t = fn (string $key, array $replace = []) => __('inertiapixel-log-viewer::log-viewer.'.$key, $replace);
    $titleTag = $vm['layout'] ? 'h2' : 'h1';
@endphp
<div class="ipx-log-viewer ipx-log-viewer--theme-{{ $vm['theme'] }}" data-ipx-log-viewer>
    @if ($vm['layout'])
        {!! $vm['styles'] !!}
        {!! $vm['scripts'] !!}
    @endif
    <header class="ipx-log-viewer__header">
        <div class="ipx-log-viewer__heading">
            <a class="ipx-log-viewer__back" href="{{ $vm['routes']['index'] }}">@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'back']){{ $t('detail.back') }}</a>
            <{{ $titleTag }} class="ipx-log-viewer__title">{{ $t('detail.heading') }}</{{ $titleTag }}>
            <p class="ipx-log-viewer__subtitle">{{ $entry->summary }}</p>
        </div>
    </header>
    <div class="ipx-log-viewer__card">
        @include('inertiapixel-log-viewer::partials.entry-detail', ['dh' => $vm['layout'] ? 'h3' : 'h2'])
    </div>
</div>
