<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Host page</title>
    @include('host::styles', ['framework' => $framework])
</head>
<body>
    <h1 id="host-heading">Host heading</h1>
    <p id="host-paragraph">Host paragraph</p>
    <button id="host-button" class="btn" type="button">Host button</button>
    <span id="host-badge" class="badge">Host badge</span>
    <table id="host-table" class="table"><tr><td id="host-cell">Host cell</td></tr></table>
    <input id="host-input" type="text" value="Host input">
</body>
</html>
