{{-- Decorative inline icon. Usage: @include('inertiapixel-log-viewer::partials.icon', ['icon' => 'search']) --}}
<svg class="ipx-log-viewer__icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
@switch($icon)
    @case('search')<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>@break
    @case('calendar')<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>@break
    @case('refresh')<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 4v7h-7"/>@break
    @case('left')<path d="m15 18-6-6 6-6"/>@break
    @case('right')<path d="m9 18 6-6-6-6"/>@break
    @case('down')<path d="m6 9 6 6 6-6"/>@break
    @case('copy')<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>@break
    @case('download')<path d="M12 3v12m0 0-5-5m5 5 5-5M4 21h16"/>@break
    @case('close')<path d="M18 6 6 18M6 6l12 12"/>@break
    @case('alert')<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4m0 3h.01"/>@break
    @case('info')<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>@break
    @case('back')<path d="M19 12H5m6-6-6 6 6 6"/>@break
    @case('file')<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M14 3v6h6"/>@break
@endswitch
</svg>
