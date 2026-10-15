<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin (Tailwind-like)</title>
    @include('host::styles', ['framework' => 'tailwind'])
</head>
<body>
    <nav id="host-nav"><button id="host-button" class="btn" type="button">Host button</button> <span id="host-badge" class="badge">Host badge</span></nav>
    <main id="host-main">
        @yield('content')
    </main>
    <table id="host-table" class="table"><tr><td id="host-cell">Host cell</td></tr></table>
</body>
</html>
