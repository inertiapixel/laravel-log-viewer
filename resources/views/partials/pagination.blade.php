@php
    $last = $result->lastPage();
    $current = $result->page;
    $pages = array_unique(array_filter([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $last], fn ($page) => $page >= 1 && $page <= $last));
    sort($pages);
    $pageUrl = fn (int $page) => $vm['routes']['index'].$presenter->queryString($filters->query(['page' => $page > 1 ? $page : null]));
@endphp
@if ($last > 1)
    <nav class="ipx-log-viewer__pagination" aria-label="{{ $t('pagination.label') }}">
        <ul class="ipx-log-viewer__pages">
            <li>
                @if ($current > 1)
                    <a class="ipx-log-viewer__page ipx-log-viewer__page--step" href="{{ $pageUrl($current - 1) }}" rel="prev" data-ipx-lv-nav>@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'left'])<span>{{ $t('pagination.previous') }}</span></a>
                @else
                    <span class="ipx-log-viewer__page ipx-log-viewer__page--step ipx-log-viewer__page--disabled" aria-disabled="true">@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'left'])<span>{{ $t('pagination.previous') }}</span></span>
                @endif
            </li>
            @php $previous = 0; @endphp
            @foreach ($pages as $page)
                @if ($page - $previous > 1)
                    <li class="ipx-log-viewer__page-gap" aria-hidden="true">…</li>
                @endif
                <li>
                    @if ($page === $current)
                        <span class="ipx-log-viewer__page ipx-log-viewer__page--current" aria-current="page" aria-label="{{ $t('pagination.current', ['page' => $page]) }}">{{ number_format($page) }}</span>
                    @else
                        <a class="ipx-log-viewer__page" href="{{ $pageUrl($page) }}" aria-label="{{ $t('pagination.page', ['page' => $page]) }}" data-ipx-lv-nav>{{ number_format($page) }}</a>
                    @endif
                </li>
                @php $previous = $page; @endphp
            @endforeach
            <li>
                @if ($current < $last)
                    <a class="ipx-log-viewer__page ipx-log-viewer__page--step" href="{{ $pageUrl($current + 1) }}" rel="next" data-ipx-lv-nav><span>{{ $t('pagination.next') }}</span>@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'right'])</a>
                @else
                    <span class="ipx-log-viewer__page ipx-log-viewer__page--step ipx-log-viewer__page--disabled" aria-disabled="true"><span>{{ $t('pagination.next') }}</span>@include('inertiapixel-log-viewer::partials.icon', ['icon' => 'right'])</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
