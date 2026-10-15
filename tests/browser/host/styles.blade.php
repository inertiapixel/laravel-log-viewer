{{--
    Element-level styles typical of host applications, reproduced by hand
    (no framework is installed): Tailwind's preflight and Bootstrap's
    reboot, plus generic class names that share words with the viewer's.
--}}
@if ($framework === 'tailwind')
<style>
    *, ::after, ::before { box-sizing: border-box; border: 0 solid; margin: 0; padding: 0; }
    html { line-height: 1.5; font-family: ui-sans-serif, system-ui, sans-serif; font-size: 18px; }
    h1, h2, h3, h4, h5, h6 { font-size: inherit; font-weight: inherit; }
    a { color: inherit; text-decoration: inherit; }
    button, input, select, optgroup, textarea { font: inherit; color: inherit; background-color: transparent; border-radius: 0; }
    button, [type=button], [type=submit] { appearance: button; background-color: transparent; background-image: none; }
    ol, ul, menu { list-style: none; }
    img, svg, video, canvas { display: block; vertical-align: middle; }
    table { text-indent: 0; border-color: inherit; border-collapse: collapse; }
    pre, code { font-family: monospace; font-size: 1em; }
    [hidden]:where(:not([hidden=until-found])) { display: none !important; }
    .card { padding: 40px; background: hotpink; }
    .badge { background: lime; text-transform: uppercase; }
    .table { color: red; }
    .btn { background: orange; }
</style>
@elseif ($framework === 'bootstrap')
<style>
    :root { --bs-body-font-size: 1rem; --primary: #ff00aa; }
    body { margin: 0; font-family: "Comic Sans MS", cursive; font-size: 1rem; font-weight: 400; line-height: 1.5; color: #212529; background-color: #fff; }
    h1, h2, h3, h4, h5, h6 { margin-top: 0; margin-bottom: .5rem; font-weight: 500; line-height: 1.2; }
    h1 { font-size: 2.5rem; } h2 { font-size: 2rem; } h3 { font-size: 1.75rem; }
    p { margin-top: 0; margin-bottom: 1rem; }
    ol, ul { padding-left: 2rem; margin-top: 0; margin-bottom: 1rem; }
    dl { margin-top: 0; margin-bottom: 1rem; } dd { margin-bottom: .5rem; margin-left: 0; } dt { font-weight: 700; }
    a { color: rgba(13, 110, 253, 1); text-decoration: underline; }
    code { font-size: .875em; color: #d63384; word-wrap: break-word; }
    pre { display: block; margin-top: 0; margin-bottom: 1rem; overflow: auto; font-size: .875em; }
    table { caption-side: bottom; border-collapse: collapse; }
    th { text-align: inherit; }
    label { display: inline-block; }
    button { border-radius: 0; }
    button, input, select { margin: 0; font-family: inherit; font-size: inherit; line-height: inherit; }
    button, [type=button], [type=submit] { -webkit-appearance: button; }
    fieldset { min-width: 0; padding: 0; margin: 0; border: 0; }
    legend { float: left; width: 100%; padding: 0; margin-bottom: .5rem; font-size: calc(1.275rem + .3vw); line-height: inherit; }
    [hidden] { display: none !important; }
    .card { border: 5px solid purple; padding: 30px; }
    .badge { background: cyan; font-size: 30px; }
    .table { --bs-table-bg: yellow; width: 100%; margin-bottom: 1rem; }
    .table > :not(caption) > * > * { padding: 1rem; background-color: yellow; border-bottom-width: 3px; }
    .btn { padding: 20px; background: orange; }
    .alert { background: red; }
    .pagination { display: none; }
</style>
@endif
