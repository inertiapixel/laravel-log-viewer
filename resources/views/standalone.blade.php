{{-- Standalone document used when log-viewer.ui.layout is null. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <title>{{ $vm['title'] }}</title>
    {!! $vm['styles'] !!}
    {!! $vm['scripts'] !!}
</head>
<body class="ipx-log-viewer-page ipx-log-viewer-page--theme-{{ $vm['theme'] }}">
    @include($inner)
</body>
</html>
