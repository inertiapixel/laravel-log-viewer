{{-- Renders the viewer inside the application's layout (log-viewer.ui.layout). --}}
@extends($vm['layout'])

@section($vm['section'])
    @include($inner)
@endsection
